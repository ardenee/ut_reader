<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `BlockedCompressedMetadataSnapshotWriter` for blocked compressed metadata
 *          snapshot writer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

/**
 * Publishes the current metadata format and its MySQL projections atomically.
 * Future v5+ changes must advance the container format/magic/extension and use
 * an offline prior-format migrator rather than compatibility branches here.
 */
final class BlockedCompressedMetadataSnapshotWriter
{
    private const CONTENTION_ATTEMPTS = 5;

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact metadata writing.');
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function write(array $snapshot): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact metadata snapshot writing requires ownership of the database transaction.');
        }

        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Compact metadata snapshot has no valid file or game identity.');
        }

        $this->assertSnapshotCounts($snapshot);
        $this->assertCurrentFormatReferences($snapshot);
        $path = BlockedCompressedMetadataContainer::path($this->storageRoot, $gameId, $fileId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create compact metadata directory: ' . $directory);
        }
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        // ue_terms is shared by every file. Resolve it once before the file-owned
        // transaction, then reuse those stable IDs for every contention retry and
        // for both lookup/search projections.
        $dictionarySqlBatches = 0;
        $lookupWriter = new CompressedMetadataLookupWriter($this->db);
        $resolvedTermIds = $lookupWriter->primeSnapshotTerms($snapshot, $dictionarySqlBatches);
        (new CompactTermOverflowWriter($this->db))->write($snapshot, $dictionarySqlBatches);

        $built = null;
        try {
            for ($attempt = 1; ; $attempt++) {
                clearstatcache(true, $temporaryPath);
                if (!is_array($built) || !is_file($temporaryPath)) {
                    // If a prior attempt failed before rename, the verified file
                    // and its build metadata are reused. If it reached rename and
                    // then rolled back its DB transaction, the temp path no longer
                    // exists and is rebuilt here.
                    $built = BlockedCompressedMetadataContainer::buildToFile(
                        $snapshot,
                        $temporaryPath
                    );
                }

                try {
                    return $this->publishAttempt(
                        $snapshot,
                        $built,
                        $temporaryPath,
                        $path,
                        $fileId,
                        $dictionarySqlBatches,
                        $lookupWriter,
                        $resolvedTermIds
                    );
                } catch (Throwable $error) {
                    if (!PdoContention::retryable($error) || $attempt >= self::CONTENTION_ATTEMPTS) {
                        throw $error;
                    }
                    usleep(PdoContention::backoffMicros($attempt, 25000));
                }
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Full Sync source-pass fast path for audited UT3/UE3 packages whose parsed
     * structure is unchanged. The container is replaced atomically and registered,
     * while all per-object SQL projections are retained. Pass 2 owns dependency
     * SQL publication; UT3 VerifyImport reads source identity from UEDB4.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function writeUe3FullSyncSourceRefresh(
        array $snapshot,
        ?int $packageVersion = null
    ): array {
        return $this->writeSelective($snapshot, 'ue3-full-sync-source', $packageVersion);
    }

    /**
     * Dependency-only publication. Names, exports and search projections are
     * package-owned and unchanged, so dependency maintenance must not delete and
     * rebuild them merely because the dependency section changed.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function writeDependencyRefresh(array $snapshot): array
    {
        return $this->writeSelective($snapshot, 'dependency-only', null);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function writeSelective(
        array $snapshot,
        string $mode,
        ?int $packageVersion
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Selective compact metadata publication requires ownership of the database transaction.');
        }
        if (!in_array($mode, ['ue3-full-sync-source', 'dependency-only'], true)) {
            throw new RuntimeException('Unknown selective compact metadata publication mode.');
        }

        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Selective compact metadata snapshot has no valid file or game identity.');
        }
        $this->assertSnapshotCounts($snapshot);
        $this->assertCurrentFormatReferences($snapshot);

        $path = BlockedCompressedMetadataContainer::path($this->storageRoot, $gameId, $fileId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create compact metadata directory: ' . $directory);
        }
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        $lookupWriter = new CompressedMetadataLookupWriter($this->db);
        $sqlBatches = 0;
        $dependencyPrepared = null;
        if ($mode === 'dependency-only') {
            $dependencyPrepared = $lookupWriter->prepareDependencyProjection($snapshot);
            $sqlBatches += (int)($dependencyPrepared['sql_batches'] ?? 0);

            // Dependency terms may be new after provider resolution. Phase-1 UT3
            // source refresh retains all structural SQL and needs no term writes.
            (new CompactTermOverflowWriter($this->db))->write($snapshot, $sqlBatches);
        }

        $built = null;
        try {
            for ($attempt = 1; ; $attempt++) {
                clearstatcache(true, $temporaryPath);
                if (!is_array($built) || !is_file($temporaryPath)) {
                    $built = BlockedCompressedMetadataContainer::buildToFile($snapshot, $temporaryPath);
                }
                try {
                    return $this->publishSelectiveAttempt(
                        $snapshot,
                        $built,
                        $temporaryPath,
                        $path,
                        $fileId,
                        $mode,
                        $sqlBatches,
                        $lookupWriter,
                        $dependencyPrepared
                    );
                } catch (Throwable $error) {
                    if (!PdoContention::retryable($error) || $attempt >= self::CONTENTION_ATTEMPTS) {
                        throw $error;
                    }
                    usleep(PdoContention::backoffMicros($attempt, 25000));
                }
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $built
     * @param array<string,mixed>|null $dependencyPrepared
     * @return array<string,mixed>
     */
    private function publishSelectiveAttempt(
        array $snapshot,
        array $built,
        string $temporaryPath,
        string $path,
        int $fileId,
        string $mode,
        int $preparedSqlBatches,
        CompressedMetadataLookupWriter $lookupWriter,
        ?array $dependencyPrepared
    ): array {
        $backupPath = $path . '.bak.' . bin2hex(random_bytes(8));
        $compressedSize = (int)($built['compressed_size'] ?? 0);
        $payloadSha256 = (string)($built['payload_sha256'] ?? '');
        $uncompressedSize = (int)($built['uncompressed_size'] ?? 0);
        $blockCount = (int)($built['block_count'] ?? 0);
        if ($compressedSize < 1 || strlen($payloadSha256) !== 32 || $uncompressedSize < 1) {
            throw new RuntimeException('Selective compact metadata build returned incomplete publication metadata.');
        }

        clearstatcache(true, $path);
        $hadExistingFile = is_file($path);
        $published = false;
        $backedUp = false;
        $sqlBatches = $preparedSqlBatches;

        $this->db->beginTransaction();
        try {
            if ($mode === 'dependency-only') {
                if (!is_array($dependencyPrepared)) {
                    throw new RuntimeException('Dependency-only publication is missing its prepared projection rows.');
                }
                $lookupWriter->writePreparedDependencyProjection(
                    $fileId,
                    $dependencyPrepared,
                    $sqlBatches
                );
            }
            $this->registerMetadata(
                $snapshot,
                $compressedSize,
                $payloadSha256,
                $uncompressedSize,
                $sqlBatches
            );

            if ($hadExistingFile) {
                if (!rename($path, $backupPath)) {
                    throw new RuntimeException('Could not stage the existing compact metadata file for selective replacement.');
                }
                $backedUp = true;
            }
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not publish selective replacement compact metadata file.');
            }
            $published = true;
            clearstatcache(true, $path);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($published && is_file($path)) {
                @unlink($path);
            }
            if ($backedUp && is_file($backupPath)) {
                @rename($backupPath, $path);
            }
            clearstatcache(true, $path);
            throw $error;
        }
        if ($backedUp && is_file($backupPath)) {
            @unlink($backupPath);
        }

        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => $path,
            'compressed_size' => $compressedSize,
            'uncompressed_size' => $uncompressedSize,
            'name_count' => count((array)($snapshot['names'] ?? [])),
            'import_count' => count((array)($snapshot['imports'] ?? [])),
            'export_count' => count((array)($snapshot['exports'] ?? [])),
            'block_count' => $blockCount,
            'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
            'sql_batches' => $sqlBatches,
            'container_rewritten' => true,
            'dependency_count' => count((array)($snapshot['dependencies'] ?? [])),
            'projection_mode' => $mode,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function registerMetadata(
        array $snapshot,
        int $compressedSize,
        string $payloadSha256,
        int $uncompressedSize,
        int &$sqlBatches
    ): void {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_file_metadata('
            . 'file_id,format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
            . 'name_count,import_count,export_count,created_at,updated_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'compressed_size=VALUES(compressed_size),uncompressed_size=VALUES(uncompressed_size),'
            . 'payload_sha256=VALUES(payload_sha256),name_count=VALUES(name_count),'
            . 'import_count=VALUES(import_count),export_count=VALUES(export_count),'
            . 'updated_at=VALUES(updated_at)'
        );
        $statement->execute([
            $fileId,
            BlockedCompressedMetadataContainer::FORMAT_VERSION,
            BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,
            $compressedSize,
            $uncompressedSize,
            $payloadSha256,
            count((array)($snapshot['names'] ?? [])),
            count((array)($snapshot['imports'] ?? [])),
            count((array)($snapshot['exports'] ?? [])),
            $timestamp,
            $timestamp,
        ]);
        $sqlBatches++;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $built
     * @param array<string,int> $resolvedTermIds
     * @return array<string,mixed>
     */
    private function publishAttempt(
        array $snapshot,
        array $built,
        string $temporaryPath,
        string $path,
        int $fileId,
        int $dictionarySqlBatches,
        CompressedMetadataLookupWriter $lookupWriter,
        array $resolvedTermIds
    ): array {
        $backupPath = $path . '.bak.' . bin2hex(random_bytes(8));
        $compressedSize = (int)($built['compressed_size'] ?? 0);
        $payloadSha256 = (string)($built['payload_sha256'] ?? '');
        $uncompressedSize = (int)($built['uncompressed_size'] ?? 0);
        $blockCount = (int)($built['block_count'] ?? 0);
        if ($compressedSize < 1 || strlen($payloadSha256) !== 32 || $uncompressedSize < 1) {
            throw new RuntimeException('Streamed compact metadata build returned incomplete publication metadata.');
        }

        clearstatcache(true, $path);
        $hadExistingFile = is_file($path);
        $published = false;
        $backedUp = false;
        $sqlBatches = $dictionarySqlBatches;

        $this->db->beginTransaction();
        try {
            $lookupWriter->writeVersionedMetadata(
                $snapshot,
                $compressedSize,
                $payloadSha256,
                $uncompressedSize,
                BlockedCompressedMetadataContainer::FORMAT_VERSION,
                BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,
                $sqlBatches,
                $resolvedTermIds
            );
            (new CompactSearchProjectionWriter($this->db))->write(
                $snapshot,
                $sqlBatches,
                $resolvedTermIds
            );

            if ($hadExistingFile) {
                if (!rename($path, $backupPath)) {
                    throw new RuntimeException('Could not stage the existing compact metadata file for replacement.');
                }
                $backedUp = true;
            }
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not publish replacement compact metadata file.');
            }
            $published = true;
            clearstatcache(true, $path);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($published && is_file($path)) {
                @unlink($path);
            }
            if ($backedUp && is_file($backupPath)) {
                @rename($backupPath, $path);
            }
            clearstatcache(true, $path);
            throw $error;
        }

        if ($backedUp && is_file($backupPath)) {
            @unlink($backupPath);
        }

        // buildToFile() verified the exact temp file before rename. The atomic
        // rename cannot alter bytes, and the committed DB row contains the same
        // size/SHA, so a second full read/decompress pass adds I/O but no stronger
        // publication guarantee.
        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => $path,
            'compressed_size' => $compressedSize,
            'uncompressed_size' => $uncompressedSize,
            'name_count' => count((array)($snapshot['names'] ?? [])),
            'import_count' => count((array)($snapshot['imports'] ?? [])),
            'export_count' => count((array)($snapshot['exports'] ?? [])),
            'block_count' => $blockCount,
            'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
            'sql_batches' => $sqlBatches,
            'container_rewritten' => true,
            'dependency_count' => count((array)($snapshot['dependencies'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function assertCurrentFormatReferences(array $snapshot): void
    {
        foreach ((array)($snapshot['names'] ?? []) as $row) {
            if (!is_array($row)
                || !array_key_exists('imports_count', $row)
                || !array_key_exists('exports_count', $row)
                || !array_key_exists('first_import_index', $row)
                || !array_key_exists('first_export_index', $row)) {
                throw new RuntimeException('Current-format publication requires persisted Name usage metadata; reparse the source package first.');
            }
        }
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (!is_array($row)
                || !array_key_exists('class_package_name_index', $row)
                || !array_key_exists('class_name_index', $row)
                || !array_key_exists('object_name_index', $row)
                || !array_key_exists('verify_identity_hash', $row)
                || !array_key_exists('path_hash_ci', $row)) {
                throw new RuntimeException(
                    'Current-format publication requires serialized Import FName indexes '
                    . 'plus v4 identity/path hash fields; rebuild through the current parser or v3->v4 migrator.'
                );
            }
            $identityHash = (string)($row['verify_identity_hash'] ?? '');
            $pathHash = (string)($row['path_hash_ci'] ?? '');
            if ($identityHash !== '' && preg_match('/^[0-9a-f]{32}$/i', $identityHash) !== 1) {
                throw new RuntimeException('Current-format Import VerifyImport identity hash is invalid.');
            }
            if ($pathHash !== '' && preg_match('/^[0-9a-f]{32}$/i', $pathHash) !== 1) {
                throw new RuntimeException('Current-format Import path hash is invalid.');
            }
        }
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            if (!is_array($row)
                || !array_key_exists('class_index', $row)
                || !array_key_exists('super_index', $row)
                || !array_key_exists('template_index', $row)
                || !array_key_exists('object_name_index', $row)
                || !array_key_exists('verify_class_package', $row)
                || !array_key_exists('verify_class_name', $row)
                || !array_key_exists('verify_identity_hash', $row)
                || !array_key_exists('path_hash_ci', $row)) {
                throw new RuntimeException(
                    'Current-format publication requires serialized Export reference indexes '
                    . 'plus v4 class identity/path hash fields; rebuild through the current parser or v3->v4 migrator.'
                );
            }
            $identityHash = (string)($row['verify_identity_hash'] ?? '');
            $pathHash = (string)($row['path_hash_ci'] ?? '');
            if ($identityHash !== '' && preg_match('/^[0-9a-f]{32}$/i', $identityHash) !== 1) {
                throw new RuntimeException('Current-format Export VerifyImport identity hash is invalid.');
            }
            if ($pathHash !== '' && preg_match('/^[0-9a-f]{32}$/i', $pathHash) !== 1) {
                throw new RuntimeException('Current-format Export path hash is invalid.');
            }
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshotCounts(array $snapshot): void
    {
        $file = (array)($snapshot['file'] ?? []);
        $expected = [
            'names' => (int)($file['name_count'] ?? -1),
            'imports' => (int)($file['import_count'] ?? -1),
            'exports' => (int)($file['export_count'] ?? -1),
            'dependencies' => (int)($file['import_count'] ?? -1),
        ];
        foreach ($expected as $section => $count) {
            $actual = count((array)($snapshot[$section] ?? []));
            if ($count < 0 || $actual !== $count) {
                throw new RuntimeException(
                    'Compact snapshot ' . $section . ' count mismatch: expected '
                    . $count . ', found ' . $actual . '.'
                );
            }
        }

        $paths = (array)($snapshot['paths'] ?? []);
        if (count((array)($paths['imports'] ?? [])) !== $expected['imports']) {
            throw new RuntimeException('Compact snapshot Import path count mismatch.');
        }
        if (count((array)($paths['exports'] ?? [])) !== $expected['exports']) {
            throw new RuntimeException('Compact snapshot Export path count mismatch.');
        }
    }
}
