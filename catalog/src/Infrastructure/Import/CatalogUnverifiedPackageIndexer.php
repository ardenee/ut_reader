<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Parses a stored Upload Bucket package and writes its unverified file/table inventory.
 * Why: Reader orchestration and database projection are independent from hashing and filesystem placement.
 * Role: Infrastructure persistence/parser collaborator for Upload Bucket package operations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;

final class CatalogUnverifiedPackageIndexer
{
    private const INSERT_BATCH_SIZE = 250;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly CatalogUnverifiedPackageRuntime $runtime
    ) {
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
    public function index(
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        int $size,
        string $md5,
        string $sha1,
        ?callable $progress = null
    ): array {
        $this->runtime->ensureSchema();
        $queueName = basename($queueName);
        if ($queueName === '' || !is_file($path)) {
            throw new \RuntimeException('Stored Upload Bucket file is unavailable.');
        }

        $key = $this->runtime->queueKey(0, $queueName);
        $existingStatement = $this->db->prepare('SELECT * FROM ue_files WHERE unverified_queue_key=? LIMIT 1');
        $existingStatement->execute([$key]);
        $existing = $existingStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        $sourceRelativePath = $this->runtime->normalizeSourceRelativePath($sourceRelativePath);

        $this->emit($progress, 'engine_detect', 64, 'Detecting Unreal Engine generation and package summary.');
        [$detectedEngine, $summary] = $this->runtime->detectEngine($path, $originalName);
        $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath !== ''
            ? $this->runtime->uePackageNameFromSourceRelative($sourceRelativePath)
            : $this->runtime->logicalPackageName($originalName);
        if ($packageName === '') {
            $packageName = $this->runtime->logicalPackageName($originalName);
        }

        $header = [];
        $names = [];
        $imports = [];
        $exports = [];
        $notes = [];
        $parseError = null;
        try {
            $readerClass = $this->runtime->readerClass($detectedEngine);
            $reader = new $readerClass($path);

            $this->emit($progress, 'reader_validate', 66, 'Validating the Unreal package reader.');
            $issues = method_exists($reader, 'validatePackage')
                ? $reader->validatePackage()
                : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
            [$fatal, $readerNotes] = $this->runtime->splitReaderIssues(is_array($issues) ? $issues : []);
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
        $relativePath = $this->runtime->storageRelative($path);
        $scanNotes = implode("\n", array_values(array_filter(
            array_map('trim', $notes),
            static fn(string $value): bool => $value !== ''
        )));
        $extension = $this->runtime->cleanExtension((string)pathinfo($originalName, PATHINFO_EXTENSION));

        $this->emit($progress, 'database_file', 84, 'Writing the unverified file record.');
        $this->db->beginTransaction();
        try {
            if (is_array($existing)) {
                $fileId = (int)$existing['id'];
                $this->db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_exports WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_imports WHERE file_id=?')->execute([$fileId]);
                $this->db->prepare('DELETE FROM ue_names WHERE file_id=?')->execute([$fileId]);
                $statement = $this->db->prepare(
                    'UPDATE ue_files SET game_id=NULL,package_name=?,original_name=?,source_relative_path=?,stored_name=?,relative_path=?,extension=?,'
                    . 'detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,detection_confidence=?,'
                    . 'compatibility_status="unverified",compatibility_label=NULL,detection_notes=?,file_size=?,md5=?,sha1=?,package_guid=?,'
                    . 'is_compressed=?,compression_flags=?,package_version=?,licensee_version=?,name_count=?,import_count=?,export_count=?,'
                    . 'scan_status="unverified",scan_notes=?,uploaded_by=COALESCE(?,uploaded_by),unverified_queue_game_id=0,'
                    . 'unverified_queue_name=?,unverified_reason=? WHERE id=?'
                );
                $statement->execute([
                    $packageName, $originalName, $sourceRelativePath !== '' ? $sourceRelativePath : null,
                    $queueName, $relativePath, $extension, $detectedEngine,
                    !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null,
                    ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null,
                    $confidence, $scanNotes, $size, strtolower($md5), strtolower($sha1),
                    $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0,
                    (int)($header['compressionFlags'] ?? 0), $version, $licensee,
                    count($names), count($imports), count($exports), $scanNotes, $uploadedBy,
                    $queueName, $reason, $fileId,
                ]);
            } else {
                $statement = $this->db->prepare(
                    'INSERT INTO ue_files(game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
                    . 'detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,compatibility_status,'
                    . 'compatibility_label,detection_notes,file_size,md5,sha1,package_guid,is_compressed,compression_flags,package_version,'
                    . 'licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by,unverified_queue_key,'
                    . 'unverified_queue_game_id,unverified_queue_name,unverified_reason) '
                    . 'VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,"unverified",?,?,?,?,?,?)'
                );
                $statement->execute([
                    $packageName, $originalName, $sourceRelativePath !== '' ? $sourceRelativePath : null,
                    $queueName, $relativePath, $extension, $detectedEngine,
                    !empty($summary['ok']) ? (int)($summary['version'] ?? 0) : null,
                    ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null,
                    $confidence, 'unverified', null, $scanNotes, $size, strtolower($md5), strtolower($sha1),
                    $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0,
                    (int)($header['compressionFlags'] ?? 0), $version, $licensee,
                    count($names), count($imports), count($exports), $scanNotes, $uploadedBy,
                    $key, 0, $queueName, $reason,
                ]);
                $fileId = (int)$this->db->lastInsertId();
            }

            $this->insertNames($fileId, $names, $progress);
            $cache = [];
            $this->insertImports($fileId, $imports, $exports, $cache, $progress);
            $this->insertExports($fileId, $packageName, $imports, $exports, $cache, $progress);

            $this->emit($progress, 'database_commit', 99, 'Committing the unverified package inventory.');
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return [
            'status' => is_array($existing) ? 'updated' : 'indexed',
            'file_id' => $fileId,
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $size,
            'message' => $parseError === null
                ? 'Stored and indexed package tables.'
                : 'Stored and indexed basic metadata; package tables could not be read.',
            'parse_error' => $parseError,
        ];
    }

    /** @param list<array<string,mixed>> $names @param callable(array<string,mixed>):void|null $progress */
    private function insertNames(int $fileId, array $names, ?callable $progress): void
    {
        $total = count($names);
        if ($total === 0) {
            $this->emit($progress, 'database_names', 89, 'Names table is empty.');
            return;
        }
        $done = 0;
        foreach (array_chunk($names, self::INSERT_BATCH_SIZE, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $index => $name) {
                $values[] = '(?,?,?,?)';
                array_push($params, $fileId, (int)$index, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null);
            }
            $statement = $this->db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES ' . implode(',', $values));
            $statement->execute($params);
            $done += count($chunk);
            $this->emit($progress, 'database_names', 85 + (int)floor(($done / $total) * 4), 'Writing Names: ' . $done . ' of ' . $total . '.', [
                'rows_done' => $done, 'rows_total' => $total, 'batch_size' => count($chunk),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @param array<int,string> $cache
     * @param callable(array<string,mixed>):void|null $progress
     */
    private function insertImports(int $fileId, array $imports, array $exports, array &$cache, ?callable $progress): void
    {
        $total = count($imports);
        if ($total === 0) {
            $this->emit($progress, 'database_imports', 93, 'Imports table is empty.');
            return;
        }

        $common = array_map('strtolower', $this->config['common_packages'] ?? []);
        $rows = [];
        foreach ($imports as $index => $import) {
            $full = $this->runtime->referencePath(-((int)$index + 1), $imports, $exports, $cache);
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = (string)($parts[0] ?? '');
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $rows[] = [
                $fileId,
                (int)$index,
                (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')),
                (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')),
                (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')),
                (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0),
                $full,
                $root,
                $relative,
                in_array(strtolower($root), $common, true) ? 1 : 0,
            ];
        }

        $done = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?,?,?,?,?,?,?,?,?,?)';
                array_push($params, ...$row);
            }
            $statement = $this->db->prepare(
                'INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES '
                . implode(',', $values)
            );
            $statement->execute($params);
            $done += count($chunk);
            $this->emit($progress, 'database_imports', 89 + (int)floor(($done / $total) * 4), 'Writing Imports: ' . $done . ' of ' . $total . '.', [
                'rows_done' => $done, 'rows_total' => $total, 'batch_size' => count($chunk),
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @param array<int,string> $cache
     * @param callable(array<string,mixed>):void|null $progress
     */
    private function insertExports(int $fileId, string $packageName, array $imports, array $exports, array &$cache, ?callable $progress): void
    {
        $total = count($exports);
        if ($total === 0) {
            $this->emit($progress, 'database_exports', 98, 'Exports table is empty.');
            return;
        }

        $rows = [];
        foreach ($exports as $index => $export) {
            $local = $this->runtime->referencePath((int)$index + 1, $imports, $exports, $cache);
            $classRef = (int)($export['classIndex'] ?? $export['class'] ?? 0);
            $rows[] = [
                $fileId,
                (int)$index,
                $classRef !== 0 ? $this->runtime->referencePath($classRef, $imports, $exports, $cache) : '',
                (string)($export['objectNameText'] ?? ''),
                (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0),
                $local,
                $this->runtime->joinPathParts([$packageName, $local]),
                isset($export['objectFlags']) ? (int)$export['objectFlags'] : null,
                isset($export['serialSize']) ? (int)$export['serialSize'] : null,
                isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
            ];
        }

        $done = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?,?,?,?,?,?,?,?,?,?)';
                array_push($params, ...$row);
            }
            $statement = $this->db->prepare(
                'INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES '
                . implode(',', $values)
            );
            $statement->execute($params);
            $done += count($chunk);
            $this->emit($progress, 'database_exports', 93 + (int)floor(($done / $total) * 5), 'Writing Exports: ' . $done . ' of ' . $total . '.', [
                'rows_done' => $done, 'rows_total' => $total, 'batch_size' => count($chunk),
            ]);
        }
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
