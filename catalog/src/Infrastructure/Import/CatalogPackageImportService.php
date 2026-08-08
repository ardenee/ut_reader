<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Imports one package into the catalog and detects a suitable game when no preferred game is supplied.
 * Why: Hashing, duplicate detection, game selection and scanner invocation are one import use case and should not live in a procedural catalog/lib utility.
 * Role: Infrastructure import service preserving the historical CatalogImport behavior used by federation imports.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;

final class CatalogPackageImportService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/GameProfiles.php';
    }

    /** @return array<string,mixed>|null */
    public function detectGame(string $extension): ?array
    {
        $extension = \catalog_clean_unreal_extension($extension);
        $rows = \catalog_all(
            $this->db,
            'SELECT g.*,p.engine_key profile_engine,p.allowed_extensions_json '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.id'
        );
        foreach ($rows as $row) {
            $extensions = \gp_extensions($row);
            if ($extensions === [] || in_array($extension, $extensions, true)) {
                return $row;
            }
        }
        return $rows[0] ?? null;
    }

    /** @return array{status:string,file_id:int|null,message:string} */
    public function importFile(
        string $sourcePath,
        string $originalName,
        ?int $preferredGameId = null,
        ?int $uploadedBy = null
    ): array {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Import source file missing: ' . $sourcePath);
        }

        $cleanName = \scanner_clean_original_filename($originalName);
        $extension = \catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
        $md5 = md5_file($sourcePath);
        if (!is_string($md5) || $md5 === '') {
            throw new RuntimeException('Could not hash import source file.');
        }

        $duplicate = \catalog_one(
            $this->db,
            'SELECT id,original_name FROM ue_files WHERE md5=? AND scan_status="verified" ORDER BY id LIMIT 1',
            [$md5]
        );
        if ($duplicate) {
            return [
                'status' => 'duplicate_md5',
                'file_id' => (int)$duplicate['id'],
                'message' => 'Duplicate MD5: ' . (string)$duplicate['original_name'],
            ];
        }

        $game = $preferredGameId !== null
            ? \catalog_one($this->db, 'SELECT * FROM ue_games WHERE id=?', [$preferredGameId])
            : $this->detectGame($extension);
        if (!$game) {
            throw new RuntimeException('Could not detect target game for extension: ' . $extension);
        }

        $sourceRelativePath = \scanner_normalize_source_relative_path($originalName);
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $cleanName;
        }

        $result = \scanner_scan_uploaded_file(
            $this->db,
            $this->config,
            (int)$game['id'],
            $sourcePath,
            $cleanName,
            $uploadedBy,
            true,
            null,
            false,
            ['source_relative_path' => $sourceRelativePath]
        );

        $status = (string)($result[0] ?? 'failed');
        $fileId = isset($result[1]) ? (int)$result[1] : null;
        $message = (string)($result[2] ?? $status);

        if ($status === 'duplicate') {
            $status = 'duplicate_md5';
        } elseif ($status === 'alias') {
            $status = 'verified';
        }

        return [
            'status' => $status,
            'file_id' => $fileId,
            'message' => $message,
        ];
    }
}
