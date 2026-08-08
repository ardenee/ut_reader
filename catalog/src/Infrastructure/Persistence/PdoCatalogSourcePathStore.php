<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies and persists ue_files.source_relative_path.
 * Why: Source-path schema checks and file-row writes should not live in scanner helper functions.
 * Role: Small PDO persistence collaborator shared by imports and source scans.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class PdoCatalogSourcePathStore
{
    private static bool $schemaVerified = false;

    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerPath.php';
    }

    public function ensureSchema(): void
    {
        if (self::$schemaVerified) {
            return;
        }

        $exists = \catalog_one(
            $this->db,
            'SELECT COUNT(*) c FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="source_relative_path"'
        );
        if ((int)($exists['c'] ?? 0) === 0) {
            throw new RuntimeException(
                'The database schema is not migrated. Missing: ue_files.source_relative_path. '
                . 'Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }

        self::$schemaVerified = true;
    }

    public function recordIfMissing(int $fileId, string $sourceRelativePath): void
    {
        $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);
        if ($sourceRelativePath === '') {
            return;
        }

        $this->ensureSchema();
        $this->db->prepare(
            'UPDATE ue_files SET source_relative_path=CASE '
            . 'WHEN source_relative_path IS NULL OR source_relative_path="" THEN ? ELSE source_relative_path END '
            . 'WHERE id=?'
        )->execute([$sourceRelativePath, $fileId]);
    }
}
