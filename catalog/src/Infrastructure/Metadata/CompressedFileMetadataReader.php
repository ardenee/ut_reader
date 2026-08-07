<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompressedFileMetadataReader` for compressed file metadata reader.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;

/** Reads and reconstructs versioned compressed per-file metadata. */
final class CompressedFileMetadataReader
{
    /** @var array<int,array<string,mixed>> */
    private array $payloadCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compressed metadata.');
        }
    }

    public function has(int $fileId): bool
    {
        if ($fileId < 1) {
            return false;
        }
        $statement = $this->db->prepare('SELECT 1 FROM ue_file_metadata WHERE file_id=? LIMIT 1');
        $statement->execute([$fileId]);
        return (bool)$statement->fetchColumn();
    }

    /** @return array<string,mixed> */
    public function read(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        if (isset($this->payloadCache[$fileId])) {
            return $this->payloadCache[$fileId];
        }
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('The PHP zlib extension is required for compressed metadata.');
        }

        $statement = $this->db->prepare(
            'SELECT m.*,f.game_id FROM ue_file_metadata m '
            . 'JOIN ue_files f ON f.id=m.file_id WHERE m.file_id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' has no compressed metadata row.');
        }
        if ((int)$row['format_version'] !== BatchedCompressedFileMetadataConverter::FORMAT_VERSION) {
            throw new RuntimeException(
                'Unsupported compressed metadata format version ' . (int)$row['format_version']
                . ' for file #' . $fileId . '.'
            );
        }
        if ((int)$row['codec'] !== BatchedCompressedFileMetadataConverter::CODEC_GZIP) {
            throw new RuntimeException('Unsupported compressed metadata codec for file #' . $fileId . '.');
        }

        $path = BatchedCompressedFileMetadataConverter::metadataPath(
            $this->storageRoot,
            (int)$row['game_id'],
            $fileId
        );
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
        if (
            count((array)($payload['names'] ?? [])) !== (int)$row['name_count']
            || count((array)($payload['imports'] ?? [])) !== (int)$row['import_count']
            || count((array)($payload['exports'] ?? [])) !== (int)$row['export_count']
        ) {
            throw new RuntimeException('Compressed metadata row-count mismatch for file #' . $fileId . '.');
        }

        return $this->payloadCache[$fileId] = $payload;
    }

    /** @return list<array<string,mixed>> */
    public function names(int $fileId): array
    {
        $payload = $this->read($fileId);
        $strings = (array)($payload['strings'] ?? []);
        $result = [];
        foreach ((array)($payload['names'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'name_index' => (int)($row[0] ?? 0),
                'name_text' => $this->stringAt($strings, $row[1] ?? null),
                'flags' => $row[2] ?? null,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function imports(int $fileId): array
    {
        return $this->legacyView($fileId)['imports'];
    }

    /** @return list<array<string,mixed>> */
    public function exports(int $fileId): array
    {
        return $this->legacyView($fileId)['exports'];
    }

    /** @return list<array<string,mixed>> */
    public function dependencies(int $fileId): array
    {
        return $this->legacyView($fileId)['dependencies'];
    }

    /**
     * @return array{
     *   names:list<array<string,mixed>>,
     *   imports:list<array<string,mixed>>,
     *   exports:list<array<string,mixed>>,
     *   dependencies:list<array<string,mixed>>
     * }
     */
    public function legacyView(int $fileId): array
    {
        $payload = $this->read($fileId);
        $strings = (array)($payload['strings'] ?? []);
        $packageName = (string)($payload['file']['package_name'] ?? '');

        $names = $this->names($fileId);
        $importMap = [];
        foreach ((array)($payload['imports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row[0] ?? 0);
            $importMap[$index] = [
                'import_index' => $index,
                'class_package' => $this->stringAt($strings, $row[1] ?? null),
                'class_name' => $this->stringAt($strings, $row[2] ?? null),
                'object_name' => $this->stringAt($strings, $row[3] ?? null),
                'outer_index' => (int)($row[4] ?? 0),
                'is_common' => (int)($row[5] ?? 0),
            ];
        }

        $exportMap = [];
        foreach ((array)($payload['exports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row[0] ?? 0);
            $exportMap[$index] = [
                'export_index' => $index,
                'class_name' => $this->stringAt($strings, $row[1] ?? null),
                'object_name' => $this->stringAt($strings, $row[2] ?? null),
                'outer_index' => (int)($row[3] ?? 0),
                'object_flags' => $row[4] ?? null,
                'serial_size' => $row[5] ?? null,
                'serial_offset' => $row[6] ?? null,
            ];
        }

        $pathCache = [];
        $resolve = function (int $reference, array $seen = []) use (&$resolve, &$pathCache, $importMap, $exportMap): string {
            if ($reference === 0) {
                return '';
            }
            if (isset($pathCache[$reference])) {
                return $pathCache[$reference];
            }
            if (isset($seen[$reference])) {
                throw new RuntimeException('Cycle detected while reading compressed metadata reference ' . $reference . '.');
            }
            $seen[$reference] = true;
            if ($reference < 0) {
                $index = -$reference - 1;
                $row = $importMap[$index] ?? null;
            } else {
                $index = $reference - 1;
                $row = $exportMap[$index] ?? null;
            }
            if (!is_array($row)) {
                throw new RuntimeException('Compressed metadata reference ' . $reference . ' points to a missing row.');
            }
            $parent = $resolve((int)$row['outer_index'], $seen);
            return $pathCache[$reference] = $this->joinPath([$parent, (string)$row['object_name']]);
        };

        $imports = [];
        foreach ($importMap as $index => $row) {
            $full = $resolve(-($index + 1));
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = (string)($parts[0] ?? '');
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $imports[] = array_merge($row, [
                'full_path' => $full,
                'root_package' => $root,
                'relative_object_path' => $relative,
            ]);
        }

        $exports = [];
        foreach ($exportMap as $index => $row) {
            $local = $resolve($index + 1);
            $exports[] = array_merge($row, [
                'local_path' => $local,
                'full_path' => $this->joinPath([$packageName, $local]),
            ]);
        }

        $importsByIndex = [];
        foreach ($imports as $row) {
            $importsByIndex[(int)$row['import_index']] = $row;
        }
        $dependencies = [];
        foreach ((array)($payload['dependencies'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $importIndex = (int)($row[0] ?? 0);
            $import = $importsByIndex[$importIndex] ?? null;
            if (!is_array($import)) {
                throw new RuntimeException('Compressed dependency references missing import index ' . $importIndex . '.');
            }
            $statusCode = (int)($row[3] ?? 0);
            $sourceCode = (int)($row[4] ?? 0);
            $dependencies[] = [
                'file_id' => $fileId,
                'import_index' => $importIndex,
                'required_package' => (string)$import['root_package'],
                'required_object_path' => (string)$import['full_path'],
                'resolved_file_id' => $row[1] !== null ? (int)$row[1] : null,
                'resolved_export_index' => $row[2] !== null ? (int)$row[2] : null,
                'status' => $this->statusLabel($statusCode),
                'resolution_source' => $this->sourceLabel($sourceCode),
                'resolution_confidence' => (int)($row[5] ?? 0),
            ];
        }

        return [
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
        ];
    }

    /** @param array<int,mixed> $strings */
    private function stringAt(array $strings, mixed $index): string
    {
        if ($index === null) {
            return '';
        }
        $value = $strings[(int)$index] ?? '';
        return is_string($value) ? $value : (string)$value;
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'resolved',
            2 => 'package_only',
            3 => 'common',
            default => 'missing',
        };
    }

    private function sourceLabel(int $source): string
    {
        return match ($source) {
            1 => 'exact_object',
            2 => 'exact_package',
            3 => 'common_script',
            default => 'none',
        };
    }

    /** @param list<string> $parts */
    private function joinPath(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim(str_replace(["\0", '/', '\\'], ['', '.', '.'], $part));
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode('.', $clean);
    }
}
