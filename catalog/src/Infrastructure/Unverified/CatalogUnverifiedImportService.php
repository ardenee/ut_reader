<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates one unverified-file import, including partial-promotion recovery and result metadata.
 * Why: The HTTP action should not know promotion transaction details or how to recover dependency handoff after commit.
 * Role: Infrastructure use-case service for Unverified Files import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;

final class CatalogUnverifiedImportService
{
    private array $config;
    private readonly CatalogUnverifiedDependencyRecovery $dependencies;
    private readonly CatalogUnverifiedPromotion $promotion;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, array $config)
    {
        // A file already in trusted server-side unverified storage is not a new
        // HTTP upload and must not be rejected by the browser upload-size limit.
        $config['max_upload_bytes'] = PHP_INT_MAX;
        $this->config = $config;
        $this->dependencies = new CatalogUnverifiedDependencyRecovery($db, $config);
        $this->promotion = new CatalogUnverifiedPromotion($db, $config, $this->dependencies);
    }

    /**
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array{result:array<string,mixed>,details:array<string,mixed>,warning:string,recovery:?array<string,mixed>}
     */
    public function import(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array {
        $stagedFileId = (int)($source['file_id'] ?? 0);
        $warning = '';
        $recovery = null;

        try {
            $result = $this->promotion->promote(
                $source,
                $targetGameId,
                $userId,
                $allowProfileOverride,
                $emit
            );
        } catch (Throwable $promotionError) {
            // The filesystem/database promotion can commit successfully before
            // post-import queueing fails. Preserve the historical recovery path:
            // never re-promote an already verified row on retry.
            $verified = $stagedFileId > 0
                ? \catalog_one(
                    $this->db,
                    'SELECT id,original_name,game_id FROM ue_files WHERE id=? AND scan_status="verified"',
                    [$stagedFileId]
                )
                : null;
            if (!$verified) {
                throw $promotionError;
            }

            $target = \catalog_one($this->db, 'SELECT name FROM ue_games WHERE id=?', [(int)$verified['game_id']]) ?: [];
            $result = [
                'status' => 'verified',
                'file_id' => (int)$verified['id'],
                'original_name' => (string)$verified['original_name'],
                'target_game' => (string)($target['name'] ?? 'selected game'),
                'message' => 'The file was verified before dependency jobs could be queued.',
            ];

            $recovery = $this->dependencies->recover(
                (int)$verified['id'],
                $promotionError,
                $userId,
                $emit
            );
            if (empty($recovery['recovered'])) {
                $warning = 'File verification completed, but dependency jobs could not be queued: '
                    . (string)$recovery['message']
                    . ' Use File Maintenance to rebuild dependencies for file #'
                    . (int)$verified['id'] . '.';
            } elseif (is_array($recovery['jobs'] ?? null)) {
                $result['dependency_jobs'] = $recovery['jobs'];
            }
        }

        $details = \catalog_one(
            $this->db,
            'SELECT package_guid,name_count,import_count,export_count FROM ue_files WHERE id=?',
            [(int)$result['file_id']]
        ) ?: [];
        $guid = trim((string)($details['package_guid'] ?? ''));

        return [
            'result' => $result,
            'details' => [
                'name_count' => (int)($details['name_count'] ?? 0),
                'import_count' => (int)($details['import_count'] ?? 0),
                'export_count' => (int)($details['export_count'] ?? 0),
                'package_guid' => $guid,
            ],
            'warning' => $warning,
            'recovery' => $recovery,
        ];
    }
}
