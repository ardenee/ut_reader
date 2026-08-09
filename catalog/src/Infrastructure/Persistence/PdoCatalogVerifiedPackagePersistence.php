<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists one newly verified package and its recovery staging projections atomically.
 * Why: The package importer should orchestrate parsing/storage, while this collaborator owns the ue_files insert and
 *      temporary N/I/E staging writes. Current format-2 metadata is published directly from parser output afterwards.
 * Role: Infrastructure persistence collaborator for verified package import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoCatalogVerifiedPackagePersistence
{
    private readonly PdoCatalogPackageTableWriter $tables;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerSupport.php';
        $this->tables = new PdoCatalogPackageTableWriter($db);
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
        $nameCount = count($names);
        $importCount = count($imports);
        $exportCount = count($exports);
        $totalRows = max(1, $nameCount + $importCount + $exportCount + 1);
        $writtenRows = 0;
        $progressDb = static function (string $message, int $rowsDone = 1) use (
            $progress,
            &$writtenRows,
            $totalRows
        ): void {
            $writtenRows = min($totalRows, $writtenRows + max(1, $rowsDone));
            \scanner_emit_percent(
                $progress,
                'database',
                \scanner_range_percent(23, 35, $writtenRows, $totalRows),
                $message
            );
        };

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
                $nameCount,
                $importCount,
                $exportCount,
                'verified',
                $scanNotes,
                $userId,
            ]);
            $fileId = (int)$this->db->lastInsertId();
            $progressDb('Writing file row');

            // Keep the historical N/I/E rows only as recovery staging until the
            // direct format-2 publication succeeds. No verified runtime reader is
            // allowed to consume them.
            $this->tables->insert(
                $fileId,
                $packageName,
                $names,
                $imports,
                $exports,
                array_values($this->config['common_packages'] ?? []),
                static function (string $section, int $done, int $total, int $rowsWritten) use ($progressDb): void {
                    $label = match ($section) {
                        'names' => 'names',
                        'imports' => 'imports',
                        default => 'exports',
                    };
                    $progressDb('Writing ' . $label . ' recovery staging ' . $done . '/' . $total, $rowsWritten);
                }
            );

            $this->db->commit();
            return $fileId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
