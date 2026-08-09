<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists one newly verified package file row atomically.
 * Why: Parsed Names/Imports/Exports are published directly to format-2 metadata by the importer and must not be duplicated into retired SQL tables.
 * Role: Infrastructure persistence collaborator for verified package import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoCatalogVerifiedPackagePersistence
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerSupport.php';
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $header
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     */
    public function persist(
        int $gameId,
        string $packageName,
        string $originalName,
        string $sourceRelativePath,
        string $storedName,
        string $relativePath,
        string $extension,
        array $classification,
        int $size,
        string $md5,
        string $sha1,
        string $packageGuid,
        array $header,
        array $names,
        array $imports,
        array $exports,
        ?string $scanNotes,
        ?int $userId,
        ?callable $progress
    ): int {
        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'INSERT INTO ue_files('
                . 'game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
                . 'detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,'
                . 'compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,'
                . 'package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                $gameId,
                $packageName,
                $originalName,
                $sourceRelativePath !== '' ? $sourceRelativePath : null,
                $storedName,
                $relativePath,
                $extension,
                $classification['detected_engine'],
                $classification['package_version'],
                $classification['licensee_version'],
                $classification['confidence'],
                $classification['compatibility_status'] ?? 'native',
                $classification['compatibility_label'] ?? null,
                implode("\n", $classification['notes']),
                $size,
                $md5,
                $sha1,
                $packageGuid,
                (int)($header['version'] ?? 0),
                (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)),
                count($names),
                count($imports),
                count($exports),
                'verified',
                $scanNotes,
                $userId,
            ]);
            $fileId = (int)$this->db->lastInsertId();
            $this->db->commit();

            \scanner_emit_percent($progress, 'database', 35, 'Stored verified file row; publishing compact metadata');
            return $fileId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
