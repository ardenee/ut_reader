<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `BlockedCompressedMetadataReader` for blocked compressed metadata reader.
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

/** Reads only the required blocks from a version-2 metadata container. */
final class BlockedCompressedMetadataReader
{
    /** @var array<int,array<string,mixed>> */
    private array $manifestCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for blocked metadata.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function page(int $fileId, string $section, int $start, int $limit): array
    {
        $section = $this->section($section);
        $start = max(0, $start);
        $limit = max(1, min(5000, $limit));
        $end = $start + $limit;
        $context = $this->manifest($fileId);
        $rows = [];

        $handle = fopen((string)$context['path'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open blocked metadata container.');
        }
        try {
            foreach ((array)($context['manifest']['sections'][$section] ?? []) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $blockStart = (int)$block['row_start'];
                $blockEnd = $blockStart + (int)$block['row_count'];
                if ($blockEnd <= $start || $blockStart >= $end) {
                    continue;
                }
                $decoded = $this->readBlock($handle, $context, $section, $block);
                $sliceStart = max(0, $start - $blockStart);
                $sliceLength = min(count($decoded) - $sliceStart, $end - max($start, $blockStart));
                if ($sliceLength > 0) {
                    array_push($rows, ...array_slice($decoded, $sliceStart, $sliceLength));
                }
            }
        } finally {
            fclose($handle);
        }
        return $rows;
    }

    /** @param list<string> $values @return array<string,int> */
    public function findNameIndexes(int $fileId, array $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $wanted[$this->key($value)] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $found = [];
        foreach ($this->scanSection($fileId, 'names') as $row) {
            $key = $this->key((string)$row['name_text']);
            if (isset($wanted[$key]) && !isset($found[$key])) {
                $found[$key] = (int)$row['name_index'];
                if (count($found) === count($wanted)) {
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public function nameUsage(int $fileId, array $names): array
    {
        $usage = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $usage[$this->key($name)] = [
                'imports_count' => 0,
                'imports_target' => '',
                'exports_count' => 0,
                'exports_target' => '',
            ];
        }
        if ($usage === []) {
            return [];
        }

        foreach ($this->scanSection($fileId, 'imports') as $row) {
            $matched = [];
            foreach (['class_package', 'class_name', 'object_name'] as $column) {
                $key = $this->key((string)($row[$column] ?? ''));
                if (isset($usage[$key])) {
                    $matched[$key] = true;
                }
            }
            foreach (array_keys($matched) as $key) {
                $usage[$key]['imports_count']++;
                if ($usage[$key]['imports_target'] === '') {
                    $usage[$key]['imports_target'] = 'import-' . (int)$row['import_index'];
                }
            }
        }

        foreach ($this->scanSection($fileId, 'exports') as $row) {
            $matched = [];
            foreach (['class_name', 'object_name'] as $column) {
                $key = $this->key((string)($row[$column] ?? ''));
                if (isset($usage[$key])) {
                    $matched[$key] = true;
                }
            }
            foreach (array_keys($matched) as $key) {
                $usage[$key]['exports_count']++;
                if ($usage[$key]['exports_target'] === '') {
                    $usage[$key]['exports_target'] = 'export-' . (int)$row['export_index'];
                }
            }
        }
        return $usage;
    }

    /** @param list<int> $importIndexes @return array<int,array<string,mixed>> */
    public function dependenciesForImportIndexes(int $fileId, array $importIndexes): array
    {
        $wanted = array_fill_keys(array_map('intval', $importIndexes), true);
        if ($wanted === []) {
            return [];
        }
        $found = [];
        foreach ($this->scanSection($fileId, 'dependencies') as $row) {
            $index = (int)$row['import_index'];
            if (isset($wanted[$index])) {
                $found[$index] = $row;
                if (count($found) === count($wanted)) {
                    break;
                }
            }
        }
        return $found;
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $context = $this->manifest($fileId, false);
        $verified = BlockedCompressedMetadataContainer::verifyFile(
            (string)$context['path'],
            $fileId,
            (string)$context['row']['payload_sha256']
        );
        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => (string)$context['path'],
            'compressed_size' => (int)$verified['compressed_size'],
            'uncompressed_size' => (int)$context['row']['uncompressed_size'],
            'name_count' => (int)$context['row']['name_count'],
            'import_count' => (int)$context['row']['import_count'],
            'export_count' => (int)$context['row']['export_count'],
            'block_count' => (int)$verified['block_count'],
            'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
        ];
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function scanSection(int $fileId, string $section): \Generator
    {
        $section = $this->section($section);
        $context = $this->manifest($fileId);
        $handle = fopen((string)$context['path'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open blocked metadata container.');
        }
        try {
            foreach ((array)($context['manifest']['sections'][$section] ?? []) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                foreach ($this->readBlock($handle, $context, $section, $block) as $row) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function manifest(int $fileId, bool $cache = true): array
    {
        if ($cache && isset($this->manifestCache[$fileId])) {
            return $this->manifestCache[$fileId];
        }
        $statement = $this->db->prepare(
            'SELECT m.*,f.game_id FROM ue_file_metadata m JOIN ue_files f ON f.id=m.file_id WHERE m.file_id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' has no compressed metadata row.');
        }
        if ((int)$row['format_version'] !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('File #' . $fileId . ' is not using blocked metadata format version 2.');
        }
        if ((int)$row['codec'] !== BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP) {
            throw new RuntimeException('File #' . $fileId . ' uses an unsupported blocked metadata codec.');
        }

        $path = BlockedCompressedMetadataContainer::path(
            $this->storageRoot,
            (int)$row['game_id'],
            $fileId
        );
        clearstatcache(true, $path);
        if (!is_file($path)) {
            throw new RuntimeException('Blocked metadata file is missing: ' . $path);
        }
        $size = @filesize($path);
        if ($size === false) {
            throw new RuntimeException('Could not read blocked metadata file size: ' . $path);
        }
        $expectedSize = (int)$row['compressed_size'];
        if ((int)$size !== $expectedSize) {
            throw new RuntimeException(
                'Blocked metadata file size mismatch: ' . $path
                . ' (expected=' . $expectedSize . ', actual=' . (int)$size . ')'
            );
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open blocked metadata container: ' . $path);
        }
        try {
            $headerBytes = $this->readExactly($handle, 20);
            $header = unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', $headerBytes);
            if (!is_array($header) || (string)$header['magic'] !== "UEDBM2\0\0") {
                throw new RuntimeException('Blocked metadata container magic is invalid.');
            }
            $manifestLength = (int)$header['manifest_length'];
            if ($manifestLength < 2 || $manifestLength > 16 * 1024 * 1024) {
                throw new RuntimeException('Blocked metadata manifest length is invalid.');
            }
            try {
                $manifest = json_decode(
                    $this->readExactly($handle, $manifestLength),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $error) {
                throw new RuntimeException('Blocked metadata manifest is invalid JSON.', 0, $error);
            }
        } finally {
            fclose($handle);
        }
        if (!is_array($manifest) || (int)($manifest['file']['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('Blocked metadata manifest identity mismatch.');
        }

        $context = [
            'row' => $row,
            'path' => $path,
            'manifest' => $manifest,
            'payload_start' => 20 + $manifestLength,
        ];
        if ($cache) {
            $this->manifestCache[$fileId] = $context;
        }
        return $context;
    }

    /**
     * @param resource $handle
     * @param array<string,mixed> $context
     * @param array<string,mixed> $block
     * @return list<array<string,mixed>> */
    private function readBlock($handle, array $context, string $section, array $block): array
    {
        $offset = (int)$context['payload_start'] + (int)$block['offset'];
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek to blocked metadata section.');
        }
        $compressed = $this->readExactly($handle, (int)$block['compressed_length']);
        if (!hash_equals((string)$block['sha256'], hash('sha256', $compressed))) {
            throw new RuntimeException('Blocked metadata section checksum mismatch.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || strlen($json) !== (int)$block['uncompressed_length']) {
            throw new RuntimeException('Could not decompress blocked metadata section.');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Blocked metadata section is invalid JSON.', 0, $error);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Blocked metadata section payload is invalid.');
        }
        $strings = (array)($payload['strings'] ?? []);
        $rows = [];
        foreach ((array)($payload['rows'] ?? []) as $row) {
            if (is_array($row)) {
                $rows[] = $this->decodeRow($section, $row, $strings);
            }
        }
        if (count($rows) !== (int)$block['row_count']) {
            throw new RuntimeException('Blocked metadata section row count mismatch.');
        }
        return $rows;
    }

    /** @param list<mixed> $row @param array<int,mixed> $strings @return array<string,mixed> */
    private function decodeRow(string $section, array $row, array $strings): array
    {
        return match ($section) {
            'names' => [
                'id' => (int)($row[0] ?? 0) + 1,
                'name_index' => (int)($row[0] ?? 0),
                'name_text' => $this->stringAt($strings, $row[1] ?? null),
                'flags' => $row[2] ?? null,
            ],
            'imports' => [
                'id' => (int)($row[0] ?? 0) + 1,
                'import_index' => (int)($row[0] ?? 0),
                'class_package' => $this->stringAt($strings, $row[1] ?? null),
                'class_name' => $this->stringAt($strings, $row[2] ?? null),
                'object_name' => $this->stringAt($strings, $row[3] ?? null),
                'outer_index' => (int)($row[4] ?? 0),
                'full_path' => $this->stringAt($strings, $row[5] ?? null),
                'root_package' => $this->stringAt($strings, $row[6] ?? null),
                'relative_object_path' => $this->stringAt($strings, $row[7] ?? null),
                'is_common' => (int)($row[8] ?? 0),
            ],
            'exports' => [
                'id' => (int)($row[0] ?? 0) + 1,
                'export_index' => (int)($row[0] ?? 0),
                'class_name' => $this->stringAt($strings, $row[1] ?? null),
                'object_name' => $this->stringAt($strings, $row[2] ?? null),
                'outer_index' => (int)($row[3] ?? 0),
                'local_path' => $this->stringAt($strings, $row[4] ?? null),
                'full_path' => $this->stringAt($strings, $row[5] ?? ''),
                'object_flags' => $row[6] ?? null,
                'serial_size' => $row[7] ?? null,
                'serial_offset' => $row[8] ?? null,
            ],
            'dependencies' => [
                'file_id' => 0,
                'import_index' => (int)($row[0] ?? 0),
                'required_package' => $this->stringAt($strings, $row[1] ?? null),
                'required_object_path' => $this->stringAt($strings, $row[2] ?? null),
                'resolved_file_id' => $row[3] !== null ? (int)$row[3] : null,
                'resolved_export_index' => $row[4] !== null ? (int)$row[4] : null,
                'status' => $this->statusLabel((int)($row[5] ?? 0)),
                'resolution_source' => $this->sourceLabel((int)($row[6] ?? 0)),
                'resolution_confidence' => (int)($row[7] ?? 0),
            ],
            default => throw new RuntimeException('Unsupported blocked metadata section: ' . $section),
        };
    }

    /** @param resource $handle */
    private function readExactly($handle, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Invalid blocked metadata read length.');
        }
        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read blocked metadata container.');
            }
            $buffer .= $chunk;
        }
        if (strlen($buffer) !== $length) {
            throw new RuntimeException('Blocked metadata container ended unexpectedly.');
        }
        return $buffer;
    }

    private function section(string $section): string
    {
        $section = strtolower(trim($section));
        if (!in_array($section, ['names', 'imports', 'exports', 'dependencies'], true)) {
            throw new RuntimeException('Unsupported blocked metadata section: ' . $section);
        }
        return $section;
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

    private function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
