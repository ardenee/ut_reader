<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;

/**
 * Stores one already-uploaded package in the neutral Upload Bucket and builds
 * its unverified database inventory with granular, heartbeat-friendly stages.
 */
final class CatalogBucketUploadProcessor
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogUnverifiedIndex.php';
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string,md5:string,sha1:string}
     */
    public function stage(
        string $temporaryPath,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        ?callable $progress = null
    ): array {
        if (!is_file($temporaryPath)) {
            throw new \RuntimeException('Prepared Upload Bucket file is missing.');
        }
        if ($uploadedBy < 1) {
            throw new \RuntimeException('Administrator identity is missing from the Upload Bucket job.');
        }

        $size = (int)(filesize($temporaryPath) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('Prepared Upload Bucket file is empty.');
        }

        $this->emit($progress, 'hash_identity', 45, 'Calculating MD5 and SHA-1.', ['bytes_done' => 0, 'bytes_total' => $size]);
        $identity = $this->hashIdentity($temporaryPath, $size, $progress);
        $md5 = $identity['md5'];
        $sha1 = $identity['sha1'];

        $lockName = 'unrealdb-bucket-md5-' . $md5;
        $this->emit($progress, 'duplicate_lock', 56, 'Waiting for the Upload Bucket duplicate lock.');
        $lock = \catalog_one($this->db, 'SELECT GET_LOCK(?, 30) acquired', [$lockName]);
        if ((int)($lock['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Timed out while waiting for the Upload Bucket duplicate lock.');
        }

        $storedPath = '';
        try {
            $this->emit($progress, 'duplicate_check', 58, 'Checking size and MD5 against existing Upload Bucket files.');
            $duplicate = $this->findBucketDuplicate($size, $md5);
            if ($duplicate !== null) {
                @unlink($temporaryPath);
                $existingName = trim((string)($duplicate['original_name'] ?? ''));
                if ($existingName === '') {
                    $existingName = (string)($duplicate['unverified_queue_name'] ?? 'existing bucket file');
                }
                $message = 'Duplicate size and MD5 already exist in the Upload Bucket as '
                    . $existingName . ' (file #' . (int)$duplicate['id'] . ', MD5 ' . $md5 . '). Uploaded copy discarded.';
                $this->emit($progress, 'duplicate', 100, $message, ['file_id' => (int)$duplicate['id']]);
                return [
                    'status' => 'duplicate',
                    'file_id' => (int)$duplicate['id'],
                    'queue_name' => (string)$duplicate['unverified_queue_name'],
                    'original_name' => $existingName,
                    'path' => (string)$duplicate['physical_path'],
                    'size' => (int)$duplicate['file_size'],
                    'message' => $message,
                    'parse_error' => null,
                    'md5' => $md5,
                    'sha1' => $sha1,
                ];
            }

            $this->emit($progress, 'bucket_store', 60, 'Moving the prepared package into Upload Bucket storage.');
            $stored = \uvf_store_bucket_upload($this->config, $temporaryPath, $originalName, $reason);
            $storedPath = (string)$stored['path'];

            try {
                $indexed = $this->indexStored(
                    (string)$stored['queue_name'],
                    $storedPath,
                    (string)$stored['original_name'],
                    $reason,
                    $uploadedBy,
                    $sourceRelativePath,
                    (int)$stored['size'],
                    $md5,
                    $sha1,
                    $progress
                );
            } catch (Throwable $error) {
                // The durable browser source is retained by the job until success,
                // so remove a half-staged physical bucket file before retrying.
                @unlink($storedPath . '.txt');
                @unlink($storedPath);
                throw $error;
            }

            return $indexed + ['md5' => $md5, 'sha1' => $sha1];
        } finally {
            try {
                \catalog_one($this->db, 'SELECT RELEASE_LOCK(?) released', [$lockName]);
            } catch (Throwable $error) {
                error_log('[UnrealDB bucket duplicate lock] ' . $error->getMessage());
            }
        }
    }

    /** @param callable(array<string,mixed>):void|null $progress @return array{md5:string,sha1:string} */
    private function hashIdentity(string $path, int $size, ?callable $progress): array
    {
        $input = fopen($path, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Could not open the prepared package for hashing.');
        }
        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');
        $done = 0;
        $lastReport = microtime(true);
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read the prepared package while hashing.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new \RuntimeException('Package hashing stopped before end of file.');
                }
                hash_update($md5Context, $buffer);
                hash_update($sha1Context, $buffer);
                $done += strlen($buffer);
                $now = microtime(true);
                if (($now - $lastReport) >= 1.0 || $done >= $size) {
                    $fraction = $size > 0 ? min(1, $done / $size) : 1;
                    $this->emit(
                        $progress,
                        'hash_identity',
                        45 + (int)floor($fraction * 10),
                        'Calculating MD5 and SHA-1: ' . $done . ' of ' . $size . ' bytes.',
                        ['bytes_done' => $done, 'bytes_total' => $size]
                    );
                    $lastReport = $now;
                }
            }
        } finally {
            fclose($input);
        }
        if ($done !== $size) {
            throw new \RuntimeException('Prepared package size changed while hashing.');
        }
        return ['md5' => hash_final($md5Context), 'sha1' => hash_final($sha1Context)];
    }

    /** @return array<string,mixed>|null */
    private function findBucketDuplicate(int $size, string $md5): ?array
    {
        \catalog_unverified_schema_ensure($this->db);
        $rows = \catalog_all(
            $this->db,
            'SELECT id,original_name,unverified_queue_name,file_size,md5 '
                . 'FROM ue_files WHERE scan_status="unverified" AND unverified_queue_game_id=0 '
                . 'AND file_size=? AND LOWER(md5)=? ORDER BY id LIMIT 50',
            [$size, strtolower($md5)]
        );
        if ($rows === []) {
            return null;
        }
        $bucketRoot = \uvf_upload_bucket_dir($this->config, false);
        foreach ($rows as $row) {
            $queueName = basename((string)($row['unverified_queue_name'] ?? ''));
            if ($queueName === '') {
                continue;
            }
            $path = $bucketRoot . DIRECTORY_SEPARATOR . $queueName;
            if (!is_file($path) || !\uvf_path_inside($path, $bucketRoot)) {
                continue;
            }
            $physicalSize = filesize($path);
            if ($physicalSize === false || (int)$physicalSize !== $size) {
                continue;
            }
            $row['physical_path'] = $path;
            return $row;
        }
        return null;
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
    private function indexStored(
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        int $size,
        string $md5,
        string $sha1,
        ?callable $progress
    ): array {
        \catalog_unverified_schema_ensure($this->db);
        $queueName = basename($queueName);
        if ($queueName === '' || !is_file($path)) {
            throw new \RuntimeException('Stored Upload Bucket file is unavailable.');
        }

        $key = \catalog_unverified_queue_key(0, $queueName);
        $existing = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE unverified_queue_key=? LIMIT 1', [$key]);
        $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);

        $this->emit($progress, 'engine_detect', 64, 'Detecting Unreal Engine generation and package summary.');
        [$detectedEngine, $summary] = \catalog_unverified_detect_engine($path, $originalName);
        $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath !== ''
            ? \scanner_ue_package_name_from_source_relative($sourceRelativePath)
            : \scanner_logical_package_name($originalName);
        if ($packageName === '') {
            $packageName = \scanner_logical_package_name($originalName);
        }

        $header = [];
        $names = [];
        $imports = [];
        $exports = [];
        $notes = [];
        $parseError = null;
        try {
            $readerClass = \scanner_load_reader_class($this->config, $detectedEngine);
            $reader = new $readerClass($path);

            $this->emit($progress, 'reader_validate', 66, 'Validating the Unreal package reader.');
            $issues = method_exists($reader, 'validatePackage')
                ? $reader->validatePackage()
                : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
            [$fatal, $readerNotes] = \scanner_split_reader_issues(is_array($issues) ? $issues : []);
            if ($fatal !== []) {
                throw new \RuntimeException(implode("\n", $fatal));
            }
            $notes = array_values($readerNotes);
            foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
                if (!method_exists($reader, $method)) {
                    throw new \RuntimeException('Reader is missing method: ' . $method);
                }
            }

            $this->emit($progress, 'read_header', 69, 'Reading the package header.');
            $header = $reader->getHeader();
            if (!is_array($header)) {
                throw new \RuntimeException('Reader returned an invalid package header.');
            }

            $this->emit($progress, 'read_names', 73, 'Reading the Names table.');
            $names = $reader->getNames();
            if (!is_array($names)) {
                throw new \RuntimeException('Reader returned an invalid Names table.');
            }

            $this->emit($progress, 'read_imports', 77, 'Reading the Imports table.');
            $imports = $reader->getImports();
            if (!is_array($imports)) {
                throw new \RuntimeException('Reader returned an invalid Imports table.');
            }

            $this->emit($progress, 'read_exports', 81, 'Reading the Exports table.');
            $exports = $reader->getExports();
            if (!is_array($exports)) {
                throw new \RuntimeException('Reader returned an invalid Exports table.');
            }
        } catch (Throwable $error) {
            $parseError = trim($error->getMessage()) ?: 'Package tables could not be read.';
            $notes[] = 'Unverified table parse failed: ' . $parseError;
            $this->emit($progress, 'reader_warning', 82, 'Package table parsing failed; indexing basic metadata: ' . $parseError);
            $header = [];
            $names = [];
            $imports = [];
            $exports = [];
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
        $relativePath = \catalog_unverified_storage_relative($this->config, $path);
        $scanNotes = implode("\n", array_values(array_filter(
            array_map('trim', $notes),
            static fn(string $value): bool => $value !== ''
        )));

        $this->emit($progress, 'database_file', 84, 'Writing the unverified file record.');
        $this->db->beginTransaction();
        try {
            if ($existing) {
                $fileId = (int)$existing['id'];
                $this->db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_exports WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_imports WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_names WHERE file_id=?')->execute([$fileId]);
                $statement = $this->db->prepare('UPDATE ue_files SET game_id=NULL,package_name=?,original_name=?,source_relative_path=?,stored_name=?,relative_path=?,extension=?,detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,detection_confidence=?,compatibility_status="unverified",compatibility_label=NULL,detection_notes=?,file_size=?,md5=?,sha1=?,package_guid=?,is_compressed=?,compression_flags=?,package_version=?,licensee_version=?,name_count=?,import_count=?,export_count=?,scan_status="unverified",scan_notes=?,uploaded_by=COALESCE(?,uploaded_by),unverified_queue_game_id=0,unverified_queue_name=?,unverified_reason=? WHERE id=?');
                $statement->execute([$packageName, $originalName, $sourceRelativePath !== '' ? $sourceRelativePath : null, $queueName, $relativePath, \catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION)), $detectedEngine, !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null, ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null, $confidence, $scanNotes, $size, strtolower($md5), strtolower($sha1), $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0, (int)($header['compressionFlags'] ?? 0), $version, $licensee, count($names), count($imports), count($exports), $scanNotes, $uploadedBy, $queueName, $reason, $fileId]);
            } else {
                $statement = $this->db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,is_compressed,compression_flags,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by,unverified_queue_key,unverified_queue_game_id,unverified_queue_name,unverified_reason) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,"unverified",?,?,?,?,?,?)');
                $statement->execute([$packageName, $originalName, $sourceRelativePath !== '' ? $sourceRelativePath : null, $queueName, $relativePath, \catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION)), $detectedEngine, !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null, ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null, $confidence, 'unverified', null, $scanNotes, $size, strtolower($md5), strtolower($sha1), $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0, (int)($header['compressionFlags'] ?? 0), $version, $licensee, count($names), count($imports), count($exports), $scanNotes, $uploadedBy, $key, 0, $queueName, $reason]);
                $fileId = (int)$this->db->lastInsertId();
            }

            $nameTotal = count($names);
            $insertName = $this->db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
            foreach ($names as $index => $name) {
                $insertName->execute([$fileId, (int)$index, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]);
                if (($index + 1) % 250 === 0 || $index + 1 === $nameTotal) {
                    $fraction = $nameTotal > 0 ? ($index + 1) / $nameTotal : 1;
                    $this->emit($progress, 'database_names', 85 + (int)floor($fraction * 4), 'Writing Names: ' . ($index + 1) . ' of ' . $nameTotal . '.', ['rows_done' => $index + 1, 'rows_total' => $nameTotal]);
                }
            }
            if ($nameTotal === 0) {
                $this->emit($progress, 'database_names', 89, 'Names table is empty.');
            }

            $cache = [];
            $common = array_map('strtolower', $this->config['common_packages'] ?? []);
            $importTotal = count($imports);
            $insertImport = $this->db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($imports as $index => $import) {
                $full = \scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
                $parts = $full !== '' ? explode('.', $full) : [];
                $root = (string)($parts[0] ?? '');
                $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
                $insertImport->execute([$fileId, (int)$index, (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')), (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')), (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')), (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0), $full, $root, $relative, in_array(strtolower($root), $common, true) ? 1 : 0]);
                if (($index + 1) % 250 === 0 || $index + 1 === $importTotal) {
                    $fraction = $importTotal > 0 ? ($index + 1) / $importTotal : 1;
                    $this->emit($progress, 'database_imports', 89 + (int)floor($fraction * 4), 'Writing Imports: ' . ($index + 1) . ' of ' . $importTotal . '.', ['rows_done' => $index + 1, 'rows_total' => $importTotal]);
                }
            }
            if ($importTotal === 0) {
                $this->emit($progress, 'database_imports', 93, 'Imports table is empty.');
            }

            $exportTotal = count($exports);
            $insertExport = $this->db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($exports as $index => $export) {
                $local = \scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
                $classRef = (int)($export['classIndex'] ?? $export['class'] ?? 0);
                $insertExport->execute([$fileId, (int)$index, $classRef !== 0 ? \scanner_ref_path($classRef, $imports, $exports, $cache) : '', (string)($export['objectNameText'] ?? ''), (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0), $local, \scanner_join_path_parts([$packageName, $local]), isset($export['objectFlags']) ? (int)$export['objectFlags'] : null, isset($export['serialSize']) ? (int)$export['serialSize'] : null, isset($export['serialOffset']) ? (int)$export['serialOffset'] : null]);
                if (($index + 1) % 250 === 0 || $index + 1 === $exportTotal) {
                    $fraction = $exportTotal > 0 ? ($index + 1) / $exportTotal : 1;
                    $this->emit($progress, 'database_exports', 93 + (int)floor($fraction * 5), 'Writing Exports: ' . ($index + 1) . ' of ' . $exportTotal . '.', ['rows_done' => $index + 1, 'rows_total' => $exportTotal]);
                }
            }
            if ($exportTotal === 0) {
                $this->emit($progress, 'database_exports', 98, 'Exports table is empty.');
            }

            $this->emit($progress, 'database_commit', 99, 'Committing the unverified package inventory.');
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
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $size,
            'message' => $parseError === null ? 'Stored and indexed package tables.' : 'Stored and indexed basic metadata; package tables could not be read.',
            'parse_error' => $parseError,
        ];
    }

    /** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $meta */
    private function emit(?callable $progress, string $stage, int $percent, string $message, array $meta = []): void
    {
        if ($progress === null) {
            return;
        }
        $progress($meta + [
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
