<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/** Orchestrates validated, batched, non-destructive metadata conversion. */
final class BatchedCompressedFileMetadataConverter
{
    public const FORMAT_VERSION = 1;
    public const CODEC_GZIP = 1;

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
        if ($this->hasMetadata($fileId)) {
            return array_merge($this->verify($fileId), [
                'already_converted' => true,
                'legacy_rows_deleted' => false,
            ]);
        }

        $snapshot = (new CompressedMetadataLegacySnapshot($this->db))->capture($fileId);
        $payload = (array)$snapshot['payload'];
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

        $file = (array)$snapshot['file'];
        $finalPath = self::metadataPath($this->storageRoot, (int)$file['game_id'], $fileId);
        $directory = dirname($finalPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create metadata directory: ' . $directory);
        }
        if (is_file($finalPath) && !unlink($finalPath)) {
            throw new RuntimeException('Could not replace orphan metadata file: ' . $finalPath);
        }

        $temporaryPath = $finalPath . '.tmp.' . bin2hex(random_bytes(6));
        $written = file_put_contents($temporaryPath, $compressed, LOCK_EX);
        if ($written !== strlen($compressed)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not completely write compact metadata: ' . $temporaryPath);
        }
        try {
            $this->verifyTemporary(
                $temporaryPath,
                $json,
                $fileId,
                count((array)$snapshot['names']),
                count((array)$snapshot['imports']),
                count((array)$snapshot['exports'])
            );
            if (!rename($temporaryPath, $finalPath)) {
                throw new RuntimeException('Could not publish compact metadata file: ' . $finalPath);
            }
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }

        $sqlBatches = 0;
        $this->db->beginTransaction();
        try {
            (new CompressedMetadataLookupWriter($this->db))->write(
                $snapshot,
                $compressed,
                $json,
                $sqlBatches
            );
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
            'name_count' => count((array)$snapshot['names']),
            'import_count' => count((array)$snapshot['imports']),
            'export_count' => count((array)$snapshot['exports']),
            'dependency_count' => count((array)$snapshot['dependencies']),
            'string_count' => count((array)$payload['strings']),
            'uncompressed_size' => strlen($json),
            'compressed_size' => strlen($compressed),
            'compression_ratio' => strlen($json) > 0 ? round(strlen($compressed) / strlen($json), 4) : 0,
            'sql_batches' => $sqlBatches,
            'verified' => (bool)$verification['verified'],
            'already_converted' => false,
            'legacy_rows_deleted' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $reader = new CompressedFileMetadataReader($this->db, $this->storageRoot);
        $payload = $reader->read($fileId);
        $statement = $this->db->prepare('SELECT compressed_size,uncompressed_size FROM ue_file_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' has no compressed metadata row.');
        }
        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => self::metadataPath(
                $this->storageRoot,
                (int)($payload['file']['game_id'] ?? 0),
                $fileId
            ),
            'compressed_size' => (int)$row['compressed_size'],
            'uncompressed_size' => (int)$row['uncompressed_size'],
            'name_count' => count((array)($payload['names'] ?? [])),
            'import_count' => count((array)($payload['imports'] ?? [])),
            'export_count' => count((array)($payload['exports'] ?? [])),
        ];
    }

    public static function metadataPath(string $storageRoot, int $gameId, int $fileId): string
    {
        $root = rtrim($storageRoot, "\\/");
        $shard = str_pad((string)intdiv($fileId, 1000), 6, '0', STR_PAD_LEFT);
        return $root . DIRECTORY_SEPARATOR . 'metadata'
            . DIRECTORY_SEPARATOR . $gameId
            . DIRECTORY_SEPARATOR . $shard
            . DIRECTORY_SEPARATOR . $fileId . '.uedb.json.gz';
    }

    private function hasMetadata(int $fileId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ue_file_metadata WHERE file_id=? LIMIT 1');
        $statement->execute([$fileId]);
        return (bool)$statement->fetchColumn();
    }

    private function assertSchema(): void
    {
        $tables = ['ue_file_metadata', 'ue_terms', 'ue_export_lookup', 'ue_dependency_links'];
        $statement = $this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $statement->execute($tables);
        $actual = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        $missing = array_values(array_filter($tables, static fn(string $table): bool => empty($actual[$table])));
        if ($missing !== []) {
            throw new RuntimeException('Compact metadata schema is incomplete: ' . implode(', ', $missing) . '.');
        }
    }

    private function verifyTemporary(
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
}
