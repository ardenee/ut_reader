<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns database-backed indexing for files retained in unverified storage.
 * Why: Package parsing, staging identity and compressed temporary metadata are one infrastructure concern.
 * Role: Infrastructure service behind the legacy CatalogUnverifiedIndex compatibility facade.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogUnverifiedStagingIndex
{
    private static bool $schemaVerified = false;
    private readonly CatalogUnverifiedMetadataStore $metadata;
    private readonly CatalogUnverifiedMetadataSnapshotBuilder $snapshots;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/CatalogRedirectArchive.php';
        require_once $root . '/lib/GameProfiles.php';
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
        $this->snapshots = new CatalogUnverifiedMetadataSnapshotBuilder($db);
    }

    public function ensureSchema(): void
    {
        if (self::$schemaVerified) {
            return;
        }

        $missing = [];
        $gameId = \catalog_one(
            $this->db,
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="game_id"'
        );
        if (!$gameId || strtoupper((string)$gameId['IS_NULLABLE']) !== 'YES') {
            $missing[] = 'ue_files.game_id nullable';
        }

        $status = \catalog_one(
            $this->db,
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="scan_status"'
        );
        if (!$status || !str_contains(strtolower((string)$status['COLUMN_TYPE']), "'unverified'")) {
            $missing[] = 'ue_files.scan_status unverified value';
        }

        foreach ([
            'source_relative_path',
            'unverified_queue_key',
            'unverified_queue_game_id',
            'unverified_queue_name',
            'unverified_reason',
        ] as $column) {
            $exists = \catalog_one(
                $this->db,
                'SELECT COUNT(*) c FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME=?',
                [$column]
            );
            if ((int)($exists['c'] ?? 0) === 0) {
                $missing[] = 'ue_files.' . $column;
            }
        }

        foreach ([
            'uq_ue_files_unverified_queue_key',
            'idx_ue_files_scan_status',
            'idx_ue_files_unverified_queue',
        ] as $index) {
            $exists = \catalog_one(
                $this->db,
                'SELECT COUNT(*) c FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND INDEX_NAME=?',
                [$index]
            );
            if ((int)($exists['c'] ?? 0) === 0) {
                $missing[] = 'index ' . $index;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The database schema is not migrated. Missing: ' . implode(', ', $missing)
                . '. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }

        $this->metadata->ensureSchema();
        \scanner_source_path_schema_ensure($this->db);
        self::$schemaVerified = true;
    }

    public static function queueKey(int $queueGameId, string $queueName): string
    {
        return hash('sha256', $queueGameId . "\0" . basename($queueName));
    }

    /** @param array<string,mixed> $config */
    public static function storageRelative(array $config, string $path): string
    {
        $storage = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        $real = realpath($path);
        if ($storage !== false && $real !== false) {
            $prefix = rtrim(str_replace('\\', '/', $storage), '/') . '/';
            $normalized = str_replace('\\', '/', $real);
            if (str_starts_with($normalized, $prefix)) {
                return 'storage/' . ltrim(substr($normalized, strlen($prefix)), '/');
            }
        }
        return str_replace('\\', '/', $path);
    }

    /** @return array{path:string,name:string,temporary:bool,source_name:string} */
    public static function preparePath(string $path, string $originalName): array
    {
        $clean = \scanner_clean_original_filename($originalName);
        if (!\catalog_redirect_archive_is_supported_filename($originalName)) {
            return [
                'path' => $path,
                'name' => $clean,
                'temporary' => false,
                'source_name' => $originalName,
            ];
        }

        $decoded = \catalog_redirect_archive_decompress_to_temp($path, $originalName);
        return [
            'path' => (string)$decoded['path'],
            'name' => \scanner_clean_original_filename((string)$decoded['filename']),
            'temporary' => true,
            'source_name' => $originalName,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    public static function detectEngine(string $path, string $name): array
    {
        $summary = \gp_read_legacy_summary($path);
        $engine = strtoupper((string)(
            $summary['engine_hint']
            ?? \gp_detect_from_extension((string)pathinfo($name, PATHINFO_EXTENSION))
            ?? 'UNKNOWN'
        ));
        return [$engine, $summary];
    }

    /**
     * @return array{header:array<string,mixed>,names:array<int,mixed>,imports:array<int,mixed>,exports:array<int,mixed>,notes:list<string>}
     */
    public function parse(
        string $path,
        string $name,
        int $queueGameId,
        string $sourceRelativePath
    ): array {
        [$engine] = self::detectEngine($path, $name);
        if (!in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            throw new RuntimeException('No Unreal package reader could be selected from the file header or extension.');
        }

        if (in_array($engine, ['UE4', 'UE5'], true) && $queueGameId > 0) {
            try {
                $game = \catalog_one($this->db, 'SELECT * FROM ue_games WHERE id=?', [$queueGameId]) ?: [];
                $profile = \gp_profile_for_game($this->db, $queueGameId) ?: [];
                \catalog_ue4_set_next_reader_options(
                    \catalog_ue4_reader_options($this->config, $game, $profile)
                );
            } catch (Throwable $error) {
                error_log('[UnrealDB unverified index] UE4 profile options: ' . $error->getMessage());
            }
        }

        $readerClass = \scanner_load_reader_class($this->config, $engine);
        $reader = new $readerClass($path);
        $issues = method_exists($reader, 'validatePackage')
            ? $reader->validatePackage()
            : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
        [$fatal, $notes] = \scanner_split_reader_issues(is_array($issues) ? $issues : []);
        if ($fatal !== []) {
            throw new RuntimeException(implode("\n", $fatal));
        }

        foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
            if (!method_exists($reader, $method)) {
                throw new RuntimeException('Reader is missing method: ' . $method);
            }
        }

        $header = $reader->getHeader();
        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();
        if (!is_array($header) || !is_array($names) || !is_array($imports) || !is_array($exports)) {
            throw new RuntimeException('Reader returned an invalid package table.');
        }

        return [
            'header' => $header,
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'notes' => array_values($notes),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $queueGameId, string $queueName): ?array
    {
        $this->ensureSchema();
        return \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1',
            [self::queueKey($queueGameId, $queueName)]
        );
    }

    /** @return array{status:string,file_id:int,message:string,parse_error:?string} */
    public function indexPath(
        int $queueGameId,
        string $queueName,
        string $path,
        string $originalName,
        string $reason = '',
        ?int $uploadedBy = null,
        string $sourceRelativePath = '',
        bool $force = false
    ): array {
        $this->ensureSchema();
        $queueName = basename($queueName);
        if ($queueName === '' || !is_file($path)) {
            throw new RuntimeException('The queued file is unavailable.');
        }

        $key = self::queueKey($queueGameId, $queueName);
        $existing = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE unverified_queue_key=? LIMIT 1', [$key]);
        if ($existing && !$force) {
            return [
                'status' => 'existing',
                'file_id' => (int)$existing['id'],
                'message' => 'Already indexed',
                'parse_error' => null,
            ];
        }

        $prepared = self::preparePath($path, $originalName);
        $parsePath = (string)$prepared['path'];
        $parsedName = (string)$prepared['name'];
        $temporary = !empty($prepared['temporary']);

        try {
            $size = (int)(filesize($parsePath) ?: 0);
            if ($size <= 0) {
                throw new RuntimeException('Queued file is empty.');
            }
            $md5 = md5_file($parsePath);
            $sha1 = sha1_file($parsePath);
            if (!is_string($md5) || !is_string($sha1)) {
                throw new RuntimeException('Could not hash the queued file.');
            }

            [$detectedEngine, $summary] = self::detectEngine($parsePath, $parsedName);
            $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);
            $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath !== ''
                ? \scanner_ue_package_name_from_source_relative($sourceRelativePath)
                : \scanner_logical_package_name($parsedName);
            if ($packageName === '') {
                $packageName = \scanner_logical_package_name($parsedName);
            }

            $header = [];
            $names = [];
            $imports = [];
            $exports = [];
            $notes = [];
            $parseError = null;
            try {
                $parsed = $this->parse($parsePath, $parsedName, $queueGameId, $sourceRelativePath);
                $header = $parsed['header'];
                $names = $parsed['names'];
                $imports = $parsed['imports'];
                $exports = $parsed['exports'];
                $notes = $parsed['notes'];
            } catch (Throwable $error) {
                $parseError = trim($error->getMessage()) ?: 'Package tables could not be read.';
                $notes[] = 'Unverified table parse failed: ' . $parseError;
            }

            if (in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath === '') {
                $notes[] = 'UE4 long package identity is provisional because no mounted source-relative path was recorded.';
            }
            if ($reason !== '') {
                $notes[] = 'Queue reason: ' . $reason;
            }

            $guid = trim((string)($header['guid'] ?? ''));
            $version = array_key_exists('version', $header)
                ? (int)$header['version']
                : (!empty($summary['ok']) ? (int)($summary['version'] ?? 0) : 0);
            $licensee = array_key_exists('licensee', $header)
                ? (int)$header['licensee']
                : (array_key_exists('licenseeVersion', $header)
                    ? (int)$header['licenseeVersion']
                    : (($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : 0));
            $confidence = $parseError === null ? 'high' : (!empty($summary['ok']) ? 'medium' : 'unknown');
            $relativePath = self::storageRelative($this->config, $path);
            $scanNotes = implode("\n", array_values(array_filter(
                array_map('trim', $notes),
                static fn(string $value): bool => $value !== ''
            )));

            $this->db->beginTransaction();
            try {
                if ($existing) {
                    $fileId = (int)$existing['id'];
                    $statement = $this->db->prepare(
                        'UPDATE ue_files SET game_id=NULL,package_name=?,original_name=?,source_relative_path=?,'
                        . 'stored_name=?,relative_path=?,extension=?,detected_engine_key=?,detected_package_version=?,'
                        . 'detected_licensee_version=?,detection_confidence=?,compatibility_status="unverified",'
                        . 'compatibility_label=NULL,detection_notes=?,file_size=?,md5=?,sha1=?,package_guid=?,'
                        . 'is_compressed=?,compression_flags=?,package_version=?,licensee_version=?,name_count=?,'
                        . 'import_count=?,export_count=?,scan_status="unverified",scan_notes=?,'
                        . 'uploaded_by=COALESCE(?,uploaded_by),unverified_queue_game_id=?,unverified_queue_name=?,'
                        . 'unverified_reason=? WHERE id=?'
                    );
                    $statement->execute([
                        $packageName, $parsedName, $sourceRelativePath !== '' ? $sourceRelativePath : null,
                        $queueName, $relativePath,
                        \catalog_clean_unreal_extension((string)pathinfo($parsedName, PATHINFO_EXTENSION)),
                        $detectedEngine,
                        !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null,
                        ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null,
                        $confidence, $scanNotes, $size, strtolower($md5), strtolower($sha1),
                        $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0,
                        (int)($header['compressionFlags'] ?? 0), $version, $licensee,
                        count($names), count($imports), count($exports), $scanNotes, $uploadedBy,
                        $queueGameId, $queueName, $reason, $fileId,
                    ]);
                } else {
                    $statement = $this->db->prepare(
                        'INSERT INTO ue_files('
                        . 'game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
                        . 'detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,'
                        . 'compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,'
                        . 'is_compressed,compression_flags,package_version,licensee_version,name_count,import_count,'
                        . 'export_count,scan_status,scan_notes,uploaded_by,unverified_queue_key,'
                        . 'unverified_queue_game_id,unverified_queue_name,unverified_reason'
                        . ') VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,"unverified",?,?,?,?,?,?)'
                    );
                    $statement->execute([
                        $packageName, $parsedName, $sourceRelativePath !== '' ? $sourceRelativePath : null,
                        $queueName, $relativePath,
                        \catalog_clean_unreal_extension((string)pathinfo($parsedName, PATHINFO_EXTENSION)),
                        $detectedEngine,
                        !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null,
                        ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null,
                        $confidence, 'unverified', null, $scanNotes, $size, strtolower($md5), strtolower($sha1),
                        $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0,
                        (int)($header['compressionFlags'] ?? 0), $version, $licensee,
                        count($names), count($imports), count($exports), $scanNotes, $uploadedBy,
                        $key, $queueGameId, $queueName, $reason,
                    ]);
                    $fileId = (int)$this->db->lastInsertId();
                }

                $snapshot = $this->snapshots->fromParsed(
                    $fileId,
                    $packageName,
                    $names,
                    $imports,
                    $exports,
                    array_values((array)($this->config['common_packages'] ?? []))
                );
                $this->metadata->write($snapshot);
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            return [
                'status' => $existing ? 'updated' : 'indexed',
                'file_id' => $fileId,
                'message' => $parseError === null
                    ? 'Indexed compressed package metadata'
                    : 'Indexed basic metadata; package tables could not be read',
                'parse_error' => $parseError,
            ];
        } finally {
            if ($temporary && is_file($parsePath)) {
                @unlink($parsePath);
            }
        }
    }

    /** @param array<string,mixed> $item */
    public function indexItem(array $item, ?int $uploadedBy = null, bool $force = false): array
    {
        return $this->indexPath(
            (int)($item['game']['id'] ?? 0),
            (string)$item['queue_name'],
            (string)$item['path'],
            (string)$item['original_name'],
            (string)($item['reason'] ?? ''),
            $uploadedBy,
            (string)($item['source_relative_path'] ?? ''),
            $force
        );
    }

    public function deleteDatabaseRow(int $queueGameId, string $queueName): void
    {
        $this->ensureSchema();
        $this->db->prepare(
            'DELETE FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=?'
        )->execute([self::queueKey($queueGameId, $queueName)]);
    }

    /** @param array<string,mixed> $sourceItem */
    public function updateQueue(
        array $sourceItem,
        int $newQueueGameId,
        string $newQueueName,
        string $newPath
    ): void {
        $this->ensureSchema();
        $oldKey = self::queueKey((int)$sourceItem['game']['id'], (string)$sourceItem['queue_name']);
        $newKey = self::queueKey($newQueueGameId, $newQueueName);
        $this->db->prepare(
            'UPDATE ue_files SET unverified_queue_key=?,unverified_queue_game_id=?,'
            . 'unverified_queue_name=?,stored_name=?,relative_path=? '
            . 'WHERE scan_status="unverified" AND unverified_queue_key=?'
        )->execute([
            $newKey,
            $newQueueGameId,
            $newQueueName,
            $newQueueName,
            self::storageRelative($this->config, $newPath),
            $oldKey,
        ]);
    }
}
