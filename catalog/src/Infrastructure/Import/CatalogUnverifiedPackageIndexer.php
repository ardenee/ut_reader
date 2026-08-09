<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Parses a stored Upload Bucket package and writes its unverified file/inventory snapshot.
 * Why: Reader orchestration and compressed staging are independent from hashing and filesystem placement.
 * Role: Infrastructure persistence/parser collaborator for Upload Bucket package operations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;

final class CatalogUnverifiedPackageIndexer
{
    private readonly CatalogUnverifiedMetadataStore $metadata;
    private readonly CatalogUnverifiedMetadataSnapshotBuilder $snapshots;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly CatalogUnverifiedPackageRuntime $runtime
    ) {
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
        $this->snapshots = new CatalogUnverifiedMetadataSnapshotBuilder($db);
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
        $this->metadata->ensureSchema();
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
            $this->emit($progress, 'read_imports', 77, 'Reading the Imports table.');
            $imports = $reader->getImports();
            $this->emit($progress, 'read_exports', 81, 'Reading the Exports table.');
            $exports = $reader->getExports();
            if (!is_array($names) || !is_array($imports) || !is_array($exports)) {
                throw new \RuntimeException('Reader returned an invalid package table.');
            }
        } catch (Throwable $error) {
            $parseError = trim($error->getMessage()) ?: 'Package tables could not be read.';
            $notes[] = 'Unverified table parse failed: ' . $parseError;
            $this->emit(
                $progress,
                'reader_warning',
                82,
                'Package table parsing failed; indexing basic metadata: ' . $parseError
            );
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

            $this->emit($progress, 'database_metadata', 90, 'Compressing staged package metadata.');
            $snapshot = $this->snapshots->fromParsed(
                $fileId,
                $packageName,
                $names,
                $imports,
                $exports,
                array_values((array)($this->config['common_packages'] ?? []))
            );
            $stored = $this->metadata->write($snapshot);
            $this->emit(
                $progress,
                'database_metadata',
                97,
                'Stored compressed staging metadata.',
                [
                    'rows_done' => count($names) + count($imports) + count($exports),
                    'rows_total' => count($names) + count($imports) + count($exports),
                    'compressed_size' => (int)$stored['compressed_size'],
                ]
            );

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
                ? 'Stored and indexed compressed package metadata.'
                : 'Stored and indexed basic metadata; package tables could not be read.',
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
