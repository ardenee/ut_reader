<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Converts one verified file from the legacy expanded SQL metadata into a
 * compact gzip-compressed per-file payload plus fixed-width MySQL lookups.
 *
 * This first migration path is intentionally non-destructive: legacy Names,
 * Imports, Exports and dependency rows remain untouched for comparison.
 */
final class CompressedFileMetadataConverter
{
    public const FORMAT_VERSION = 1;
    public const CODEC_GZIP = 1;

    private const STATUS_MISSING = 0;
    private const STATUS_RESOLVED = 1;
    private const STATUS_PACKAGE_ONLY = 2;
    private const STATUS_COMMON = 3;

    private const SOURCE_NONE = 0;
    private const SOURCE_EXACT_EXPORT = 1;
    private const SOURCE_PACKAGE_ONLY = 2;
    private const SOURCE_COMMON = 3;

    /** @var array<string,int> */
    private array $termCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compressed metadata.');
        }
    }

    /** @return array<string,mixed> */
    public function convert(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        if (!function_exists('gzencode') || !function_exists('gzdecode')) {
            throw new RuntimeException('The PHP zlib extension is required for compressed metadata.');
        }

        $this->assertSchema();
        $file = $this->file($fileId);
        if ($file === null) {
            throw new RuntimeException('File #' . $fileId . ' was not found.');
        }
        if ((string)$file['scan_status'] !== 'verified') {
            throw new RuntimeException('File #' . $fileId . ' is not verified.');
        }
        if ($this->metadataRow($fileId) !== null) {
            throw new RuntimeException('File #' . $fileId . ' already has compressed metadata.');
        }

        $names = $this->rows('SELECT name_index,name_text,flags FROM ue_names WHERE file_id=? ORDER BY name_index', $fileId);
        $imports = $this->rows(
            'SELECT id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common '
            . 'FROM ue_imports WHERE file_id=? ORDER BY import_index',
            $fileId
        );
        $exports = $this->rows(
            'SELECT id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset '
            . 'FROM ue_exports WHERE file_id=? ORDER BY export_index',
            $fileId
        );
        $dependencies = $this->rows(
            'SELECT i.import_index,d.required_package,d.required_object_path,d.resolved_file_id,'
            . 're.export_index resolved_export_index,d.status '
            . 'FROM ue_dependencies d '
            . 'JOIN ue_imports i ON i.id=d.import_id '
            . 'LEFT JOIN ue_exports re ON re.id=d.resolved_export_id '
            . 'WHERE d.file_id=? ORDER BY i.import_index',
            $fileId
        );

        $this->assertCounts($file, $names, $imports, $exports);
        $paths = $this->validateLegacyPaths((string)$file['package_name'], $imports, $exports);
        $this->validateDependencies($imports, $dependencies);

        $payload = $this->buildPayload($file, $names, $imports, $exports, $dependencies);
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode compact metadata JSON: ' . $error->getMessage(), 0, $error);
        }
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Compact metadata JSON was empty.');
        }

        $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed) || $compressed === '') {
            throw new RuntimeException('Could not gzip compact metadata.');
        }

        $finalPath = $this->metadataPath((int)$file['game_id'], $fileId);
        $directory = dirname($finalPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create metadata directory: ' . $directory);
        }
        if (is_file($finalPath)) {
            // No database row points to it, so this is an orphan from an interrupted pilot.
            if (!unlink($finalPath)) {
                throw new RuntimeException('Could not replace orphan metadata file: ' . $finalPath);
            }
        }

        $temporaryPath = $finalPath . '.tmp.' . bin2hex(random_bytes(6));
        $written = file_put_contents($temporaryPath, $compressed, LOCK_EX);
        if ($written !== strlen($compressed)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not completely write compact metadata: ' . $temporaryPath);
        }

        try {
            $this->verifyWrittenPayload($temporaryPath, $json, $fileId, count($names), count($imports), count($exports));
            if (!rename($temporaryPath, $finalPath)) {
                throw new RuntimeException('Could not publish compact metadata file: ' . $finalPath);
            }
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }

        $compressedHash = hash('sha256', $compressed, true);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);

            $insertExport = $this->db->prepare(
                'INSERT INTO ue_export_lookup(file_id,export_index,object_term_id,class_term_id,path_hash) '
                . 'VALUES(?,?,?,?,?)'
            );
            foreach ($exports as $row) {
                $exportIndex = (int)$row['export_index'];
                $objectName = (string)$row['object_name'];
                $className = trim((string)($row['class_name'] ?? ''));
                $insertExport->execute([
                    $fileId,
                    $exportIndex,
                    $this->termId($objectName),
                    $className !== '' ? $this->termId($className) : null,
                    md5((string)$paths['exports'][$exportIndex]['local'], true),
                ]);
            }

            $insertDependency = $this->db->prepare(
                'INSERT INTO ue_dependency_links('
                . 'file_id,import_index,required_package_term_id,required_path_hash,'
                . 'resolved_file_id,resolved_export_index,status,resolution_source,resolution_confidence'
                . ') VALUES(?,?,?,?,?,?,?,?,?)'
            );
            foreach ($dependencies as $row) {
                $importIndex = (int)$row['import_index'];
                $legacyStatus = strtolower(trim((string)$row['status']));
                [$status, $source, $confidence] = $this->dependencyCodes($legacyStatus);
                $relative = (string)$paths['imports'][$importIndex]['relative'];
                $insertDependency->execute([
                    $fileId,
                    $importIndex,
                    $this->termId((string)$row['required_package']),
                    md5($relative, true),
                    $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                    $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                    $status,
                    $source,
                    $confidence,
                ]);
            }

            $timestamp = gmdate('Y-m-d H:i:s');
            $insertMetadata = $this->db->prepare(
                'INSERT INTO ue_file_metadata('
                . 'file_id,format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
                . 'name_count,import_count,export_count,created_at,updated_at'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insertMetadata->execute([
                $fileId,
                self::FORMAT_VERSION,
                self::CODEC_GZIP,
                strlen($compressed),
                strlen($json),
                $compressedHash,
                count($names),
                count($imports),
                count($exports),
                $timestamp,
                $timestamp,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($finalPath);
            throw $error;
        }

        $verification = $this->verify($fileId);
        return [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'metadata_path' => $finalPath,
            'format_version' => self::FORMAT_VERSION,
            'codec' => 'gzip',
            'name_count' => count($names),
            'import_count' => count($imports),
            'export_count' => count($exports),
            'dependency_count' => count($dependencies),
            'string_count' => count((array)$payload['strings']),
            'uncompressed_size' => strlen($json),
            'compressed_size' => strlen($compressed),
            'compression_ratio' => strlen($json) > 0 ? round(strlen($compressed) / strlen($json), 4) : 0,
            'verified' => (bool)$verification['verified'],
            'legacy_rows_deleted' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $row = $this->metadataRow($fileId);
        if ($row === null) {
            throw new RuntimeException('File #' . $fileId . ' has no compressed metadata row.');
        }
        $file = $this->file($fileId);
        if ($file === null) {
            throw new RuntimeException('File #' . $fileId . ' was not found.');
        }

        $path = $this->metadataPath((int)$file['game_id'], $fileId);
        $compressed = @file_get_contents($path);
        if (!is_string($compressed) || $compressed === '') {
            throw new RuntimeException('Compressed metadata file is missing or empty: ' . $path);
        }
        if (strlen($compressed) !== (int)$row['compressed_size']) {
            throw new RuntimeException('Compressed metadata size mismatch for file #' . $fileId . '.');
        }
        $expectedHash = (string)$row['payload_sha256'];
        $actualHash = hash('sha256', $compressed, true);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Compressed metadata SHA-256 mismatch for file #' . $fileId . '.');
        }

        $json = gzdecode($compressed);
        if (!is_string($json)) {
            throw new RuntimeException('Could not decode compressed metadata for file #' . $fileId . '.');
        }
        if (strlen($json) !== (int)$row['uncompressed_size']) {
            throw new RuntimeException('Uncompressed metadata size mismatch for file #' . $fileId . '.');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Compressed metadata JSON is invalid: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($payload) || (int)($payload['file']['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('Compressed metadata file identity mismatch for file #' . $fileId . '.');
        }

        $counts = [
            'names' => count((array)($payload['names'] ?? [])),
            'imports' => count((array)($payload['imports'] ?? [])),
            'exports' => count((array)($payload['exports'] ?? [])),
        ];
        if (
            $counts['names'] !== (int)$row['name_count']
            || $counts['imports'] !== (int)$row['import_count']
            || $counts['exports'] !== (int)$row['export_count']
        ) {
            throw new RuntimeException('Compressed metadata row-count mismatch for file #' . $fileId . '.');
        }

        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => $path,
            'compressed_size' => strlen($compressed),
            'uncompressed_size' => strlen($json),
            'name_count' => $counts['names'],
            'import_count' => $counts['imports'],
            'export_count' => $counts['exports'],
        ];
    }

    private function assertSchema(): void
    {
        $required = [
            'ue_file_metadata' => [
                'file_id', 'format_version', 'codec', 'compressed_size', 'uncompressed_size', 'payload_sha256',
                'name_count', 'import_count', 'export_count', 'created_at', 'updated_at',
            ],
            'ue_terms' => ['id', 'value_hash', 'value_length', 'value_prefix', 'is_overflow'],
            'ue_export_lookup' => ['file_id', 'export_index', 'object_term_id', 'class_term_id', 'path_hash'],
            'ue_dependency_links' => [
                'file_id', 'import_index', 'required_package_term_id', 'required_path_hash',
                'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source', 'resolution_confidence',
            ],
        ];

        $statement = $this->db->prepare(
            'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($required), '?')) . ')'
        );
        $statement->execute(array_keys($required));
        $actual = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = true;
        }

        $missing = [];
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (empty($actual[$table][$column])) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Compact metadata schema is incomplete: ' . implode(', ', $missing));
        }
    }

    /** @return array<string,mixed>|null */
    private function file(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,name_count,import_count,export_count,scan_status '
            . 'FROM ue_files WHERE id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function metadataRow(int $fileId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ue_file_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, int $fileId): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute([$fileId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string,mixed>> $names @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $exports */
    private function assertCounts(array $file, array $names, array $imports, array $exports): void
    {
        $expected = [
            'names' => (int)$file['name_count'],
            'imports' => (int)$file['import_count'],
            'exports' => (int)$file['export_count'],
        ];
        $actual = [
            'names' => count($names),
            'imports' => count($imports),
            'exports' => count($exports),
        ];
        foreach ($expected as $type => $count) {
            if ($count !== $actual[$type]) {
                throw new RuntimeException(
                    'File #' . (int)$file['id'] . ' ' . $type . ' count mismatch: ue_files=' . $count
                    . ', legacy rows=' . $actual[$type] . '.'
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @return array{imports:array<int,array{full:string,root:string,relative:string}>,exports:array<int,array{local:string,full:string}>}
     */
    private function validateLegacyPaths(string $packageName, array $imports, array $exports): array
    {
        $importMap = [];
        foreach ($imports as $row) {
            $importMap[(int)$row['import_index']] = $row;
        }
        $exportMap = [];
        foreach ($exports as $row) {
            $exportMap[(int)$row['export_index']] = $row;
        }
        $cache = [];

        $resolve = function (int $reference, array $seen = []) use (&$resolve, &$cache, $importMap, $exportMap): string {
            if ($reference === 0) {
                return '';
            }
            if (isset($cache[$reference])) {
                return $cache[$reference];
            }
            if (isset($seen[$reference])) {
                throw new RuntimeException('Cycle detected while reconstructing package path reference ' . $reference . '.');
            }
            $seen[$reference] = true;

            if ($reference < 0) {
                $index = -$reference - 1;
                $row = $importMap[$index] ?? null;
                if (!is_array($row)) {
                    throw new RuntimeException('Import reference ' . $reference . ' points to missing import index ' . $index . '.');
                }
            } else {
                $index = $reference - 1;
                $row = $exportMap[$index] ?? null;
                if (!is_array($row)) {
                    throw new RuntimeException('Export reference ' . $reference . ' points to missing export index ' . $index . '.');
                }
            }

            $parent = $resolve((int)$row['outer_index'], $seen);
            $name = $this->cleanName((string)$row['object_name']);
            return $cache[$reference] = $this->joinPath([$parent, $name]);
        };

        $result = ['imports' => [], 'exports' => []];
        foreach ($imports as $row) {
            $index = (int)$row['import_index'];
            $full = $resolve(-($index + 1));
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = (string)($parts[0] ?? '');
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $this->assertSamePath('import #' . $index . ' full_path', (string)$row['full_path'], $full);
            $this->assertSamePath('import #' . $index . ' root_package', (string)$row['root_package'], $root);
            $this->assertSamePath('import #' . $index . ' relative_object_path', (string)$row['relative_object_path'], $relative);
            $result['imports'][$index] = ['full' => $full, 'root' => $root, 'relative' => $relative];
        }

        foreach ($exports as $row) {
            $index = (int)$row['export_index'];
            $local = $resolve($index + 1);
            $full = $this->joinPath([$packageName, $local]);
            $this->assertSamePath('export #' . $index . ' local_path', (string)$row['local_path'], $local);
            $this->assertSamePath('export #' . $index . ' full_path', (string)$row['full_path'], $full);
            $result['exports'][$index] = ['local' => $local, 'full' => $full];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $dependencies */
    private function validateDependencies(array $imports, array $dependencies): void
    {
        if (count($imports) !== count($dependencies)) {
            throw new RuntimeException(
                'Dependency row count mismatch: imports=' . count($imports) . ', dependencies=' . count($dependencies) . '.'
            );
        }
        $importMap = [];
        foreach ($imports as $row) {
            $importMap[(int)$row['import_index']] = $row;
        }
        foreach ($dependencies as $row) {
            $index = (int)$row['import_index'];
            $import = $importMap[$index] ?? null;
            if (!is_array($import)) {
                throw new RuntimeException('Dependency references missing import index ' . $index . '.');
            }
            $this->assertSamePath(
                'dependency import #' . $index . ' required_package',
                (string)$import['root_package'],
                (string)$row['required_package']
            );
            $this->assertSamePath(
                'dependency import #' . $index . ' required_object_path',
                (string)$import['full_path'],
                (string)$row['required_object_path']
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $names
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @param list<array<string,mixed>> $dependencies
     * @return array<string,mixed>
     */
    private function buildPayload(array $file, array $names, array $imports, array $exports, array $dependencies): array
    {
        $strings = [];
        $stringIds = [];
        $intern = static function (?string $value, bool $emptyAsNull = false) use (&$strings, &$stringIds): ?int {
            if ($value === null || ($emptyAsNull && $value === '')) {
                return null;
            }
            $key = 's:' . $value;
            if (array_key_exists($key, $stringIds)) {
                return $stringIds[$key];
            }
            $id = count($strings);
            $strings[] = $value;
            $stringIds[$key] = $id;
            return $id;
        };

        $nameRows = [];
        foreach ($names as $row) {
            $nameRows[] = [
                (int)$row['name_index'],
                $intern((string)$row['name_text']),
                $row['flags'] !== null ? (string)$row['flags'] : null,
            ];
        }

        $importRows = [];
        foreach ($imports as $row) {
            $importRows[] = [
                (int)$row['import_index'],
                $intern((string)($row['class_package'] ?? ''), true),
                $intern((string)($row['class_name'] ?? ''), true),
                $intern((string)$row['object_name']),
                (int)$row['outer_index'],
                (int)$row['is_common'],
            ];
        }

        $exportRows = [];
        foreach ($exports as $row) {
            $exportRows[] = [
                (int)$row['export_index'],
                $intern((string)($row['class_name'] ?? ''), true),
                $intern((string)$row['object_name']),
                (int)$row['outer_index'],
                $row['object_flags'] !== null ? (string)$row['object_flags'] : null,
                $row['serial_size'] !== null ? (string)$row['serial_size'] : null,
                $row['serial_offset'] !== null ? (string)$row['serial_offset'] : null,
            ];
        }

        $dependencyRows = [];
        foreach ($dependencies as $row) {
            [$status, $source, $confidence] = $this->dependencyCodes(strtolower(trim((string)$row['status'])));
            $dependencyRows[] = [
                (int)$row['import_index'],
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $source,
                $confidence,
            ];
        }

        return [
            'format' => 'unrealdb.file-metadata',
            'format_version' => self::FORMAT_VERSION,
            'source_format' => 'legacy-sql-v1',
            'file' => [
                'id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
                'original_name' => (string)$file['original_name'],
            ],
            'schema' => [
                'names' => ['name_index', 'string_id', 'flags'],
                'imports' => ['import_index', 'class_package_string_id', 'class_name_string_id', 'object_name_string_id', 'outer_index', 'is_common'],
                'exports' => ['export_index', 'class_name_string_id', 'object_name_string_id', 'outer_index', 'object_flags', 'serial_size', 'serial_offset'],
                'dependencies' => ['import_index', 'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source', 'resolution_confidence'],
            ],
            'strings' => $strings,
            'names' => $nameRows,
            'imports' => $importRows,
            'exports' => $exportRows,
            'dependencies' => $dependencyRows,
        ];
    }

    private function verifyWrittenPayload(
        string $path,
        string $expectedJson,
        int $fileId,
        int $nameCount,
        int $importCount,
        int $exportCount
    ): void {
        $compressed = file_get_contents($path);
        if (!is_string($compressed) || $compressed === '') {
            throw new RuntimeException('Could not read the temporary compressed metadata file.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || !hash_equals(hash('sha256', $expectedJson), hash('sha256', $json))) {
            throw new RuntimeException('Temporary compact metadata failed its read-back checksum.');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Temporary compact metadata JSON is invalid.', 0, $error);
        }
        if (
            !is_array($payload)
            || (int)($payload['file']['id'] ?? 0) !== $fileId
            || count((array)($payload['names'] ?? [])) !== $nameCount
            || count((array)($payload['imports'] ?? [])) !== $importCount
            || count((array)($payload['exports'] ?? [])) !== $exportCount
        ) {
            throw new RuntimeException('Temporary compact metadata failed its identity/count verification.');
        }
    }

    private function metadataPath(int $gameId, int $fileId): string
    {
        $root = rtrim($this->storageRoot, "\\/");
        $shard = str_pad((string)intdiv($fileId, 1000), 6, '0', STR_PAD_LEFT);
        return $root . DIRECTORY_SEPARATOR . 'metadata'
            . DIRECTORY_SEPARATOR . $gameId
            . DIRECTORY_SEPARATOR . $shard
            . DIRECTORY_SEPARATOR . $fileId . '.uedb.json.gz';
    }

    private function termId(string $value): int
    {
        $cacheKey = strlen($value) . ':' . $value;
        if (isset($this->termCache[$cacheKey])) {
            return $this->termCache[$cacheKey];
        }
        if (strlen($value) > 65535) {
            throw new RuntimeException('Compact lookup term exceeds 65,535 bytes.');
        }

        $hash = md5($value, true);
        $length = strlen($value);
        $prefix = substr($value, 0, 200);
        $overflow = $length > 200 ? 1 : 0;

        $insert = $this->db->prepare(
            'INSERT INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES(?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $insert->execute([$hash, $length, $prefix, $overflow]);
        $id = (int)$this->db->lastInsertId();
        if ($id < 1) {
            $selectId = $this->db->prepare('SELECT id FROM ue_terms WHERE value_hash=? AND value_length=?');
            $selectId->execute([$hash, $length]);
            $id = (int)($selectId->fetchColumn() ?: 0);
        }
        if ($id < 1) {
            throw new RuntimeException('Could not resolve compact lookup term ID.');
        }

        $verify = $this->db->prepare('SELECT value_prefix,is_overflow FROM ue_terms WHERE id=?');
        $verify->execute([$id]);
        $row = $verify->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($row)
            || !hash_equals((string)$row['value_prefix'], $prefix)
            || (int)$row['is_overflow'] !== $overflow
        ) {
            throw new RuntimeException('Compact lookup term hash collision or stored-prefix mismatch.');
        }

        return $this->termCache[$cacheKey] = $id;
    }

    /** @return array{0:int,1:int,2:int} */
    private function dependencyCodes(string $status): array
    {
        return match ($status) {
            'resolved' => [self::STATUS_RESOLVED, self::SOURCE_EXACT_EXPORT, 100],
            'package_only' => [self::STATUS_PACKAGE_ONLY, self::SOURCE_PACKAGE_ONLY, 75],
            'common' => [self::STATUS_COMMON, self::SOURCE_COMMON, 100],
            default => [self::STATUS_MISSING, self::SOURCE_NONE, 0],
        };
    }

    private function cleanName(string $value): string
    {
        return trim(str_replace(["\0", '/', '\\'], ['', '.', '.'], $value));
    }

    /** @param list<string> $parts */
    private function joinPath(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = $this->cleanName($part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode('.', $clean);
    }

    private function assertSamePath(string $label, string $legacy, string $reconstructed): void
    {
        if (!hash_equals($legacy, $reconstructed)) {
            throw new RuntimeException(
                $label . ' mismatch: legacy="' . $legacy . '", reconstructed="' . $reconstructed . '".'
            );
        }
    }
}
