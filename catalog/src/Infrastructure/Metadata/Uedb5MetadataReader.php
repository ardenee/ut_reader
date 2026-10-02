<?php
/**
 * Production-capable format-5 metadata reader kept isolated from the live UEDB4 runtime.
 * It resolves only the canonical .uedb5 path supplied by game/file identity and never consults
 * ue_file_metadata or falls back to a prior container format.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use RuntimeException;

final class Uedb5MetadataReader
{
    /** @var array<string,array<string,mixed>> */
    private array $contextCache = [];

    public function __construct(private readonly string $storageRoot)
    {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for UEDB5 reading.');
        }
    }

    public function path(int $gameId, int $fileId): string
    {
        return Uedb5MetadataContainer::path($this->storageRoot, $gameId, $fileId);
    }

    /** @return array<string,mixed> */
    public function manifest(int $gameId, int $fileId): array
    {
        return (array)$this->context($gameId, $fileId)['manifest'];
    }

    /** @return list<string> */
    public function sectionNames(int $gameId, int $fileId): array
    {
        return array_values(array_map(
            'strval',
            array_keys((array)$this->manifest($gameId, $fileId)['sections'])
        ));
    }

    public function count(int $gameId, int $fileId, string $section): int
    {
        $context = $this->context($gameId, $fileId);
        $section = $this->section($context, $section);
        return (int)($context['manifest']['counts'][$section] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function page(int $gameId, int $fileId, string $section, int $start, int $limit): array
    {
        $context = $this->context($gameId, $fileId);
        $section = $this->section($context, $section);
        $start = max(0, $start);
        $limit = max(1, min(5000, $limit));
        $end = $start + $limit;
        $rows = [];
        [$context, $handle] = $this->openContext($gameId, $fileId, $context);
        $section = $this->section($context, $section);
        try {
            foreach ((array)$context['manifest']['sections'][$section] as $block) {
                $block = (array)$block;
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

    /** @return array<int,array<string,mixed>> keyed by serialized row position */
    public function rowsByPositions(int $gameId, int $fileId, string $section, array $positions): array
    {
        $context = $this->context($gameId, $fileId);
        $section = $this->section($context, $section);
        $wanted = [];
        foreach ($positions as $position) {
            $position = (int)$position;
            if ($position >= 0) {
                $wanted[$position] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $rows = [];
        [$context, $handle] = $this->openContext($gameId, $fileId, $context);
        $section = $this->section($context, $section);
        try {
            foreach ((array)$context['manifest']['sections'][$section] as $block) {
                $block = (array)$block;
                $blockStart = (int)$block['row_start'];
                $blockEnd = $blockStart + (int)$block['row_count'];
                $touches = false;
                foreach (array_keys($wanted) as $position) {
                    if ($position >= $blockStart && $position < $blockEnd) {
                        $touches = true;
                        break;
                    }
                }
                if (!$touches) {
                    continue;
                }
                foreach ($this->readBlock($handle, $context, $section, $block) as $offset => $row) {
                    $position = $blockStart + $offset;
                    if (isset($wanted[$position])) {
                        $rows[$position] = $row;
                        unset($wanted[$position]);
                    }
                }
                if ($wanted === []) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        ksort($rows, SORT_NUMERIC);
        return $rows;
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function scan(int $gameId, int $fileId, string $section): \Generator
    {
        $context = $this->context($gameId, $fileId);
        $section = $this->section($context, $section);
        [$context, $handle] = $this->openContext($gameId, $fileId, $context);
        $section = $this->section($context, $section);
        try {
            foreach ((array)$context['manifest']['sections'][$section] as $block) {
                foreach ($this->readBlock($handle, $context, $section, (array)$block) as $row) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    public function snapshot(int $gameId, int $fileId): array
    {
        $manifest = $this->manifest($gameId, $fileId);
        $sections = [];
        foreach ($this->sectionNames($gameId, $fileId) as $section) {
            $sections[$section] = iterator_to_array($this->scan($gameId, $fileId, $section), false);
        }
        return [
            'file' => (array)$manifest['file'],
            'package_family' => (string)$manifest['package_family'],
            'source_policy' => (string)$manifest['source_policy'],
            'section_schemas' => (array)($manifest['section_schemas'] ?? []),
            'sections' => $sections,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(int $gameId, int $fileId, ?string $expectedPayloadSha256 = null): array
    {
        $path = $this->path($gameId, $fileId);
        $verified = Uedb5MetadataContainer::verifyFile($path, $fileId, $expectedPayloadSha256);
        $manifest = (array)$verified['manifest'];
        if ((int)($manifest['file']['game_id'] ?? 0) !== $gameId) {
            throw new RuntimeException('UEDB5 manifest game identity mismatch.');
        }
        return $verified + ['path' => $path];
    }

    public function clearCache(?int $gameId = null, ?int $fileId = null): void
    {
        if ($gameId === null || $fileId === null) {
            $this->contextCache = [];
            return;
        }
        unset($this->contextCache[$this->cacheKey($gameId, $fileId)]);
    }

    /** @return array<string,mixed> */
    private function context(int $gameId, int $fileId): array
    {
        if ($gameId < 1 || $fileId < 1) {
            throw new RuntimeException('Positive game and file IDs are required for UEDB5 reading.');
        }
        $key = $this->cacheKey($gameId, $fileId);
        $path = $this->path($gameId, $fileId);
        $signature = $this->fileSignature($path);
        if (isset($this->contextCache[$key])
            && hash_equals((string)($this->contextCache[$key]['file_signature'] ?? ''), $signature)) {
            return $this->contextCache[$key];
        }
        unset($this->contextCache[$key]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->fileSignature($path);
            $verified = $this->verify($gameId, $fileId);
            $after = $this->fileSignature($path);
            if (!hash_equals($before, $after)) {
                continue;
            }
            $context = [
                'path' => (string)$verified['path'],
                'manifest' => (array)$verified['manifest'],
                'payload_start' => (int)$verified['payload_start'],
                'file_signature' => $after,
            ];
            return $this->contextCache[$key] = $context;
        }
        throw new RuntimeException('UEDB5 container changed repeatedly while being opened: ' . $path);
    }

    /** @param array<string,mixed> $context */
    private function section(array $context, string $section): string
    {
        $section = trim($section);
        if ($section === '' || !array_key_exists($section, (array)$context['manifest']['sections'])) {
            throw new RuntimeException('Unknown UEDB5 section: ' . $section);
        }
        return $section;
    }

    /** @param resource $handle @param array<string,mixed> $context @param array<string,mixed> $block */
    private function readBlock($handle, array $context, string $section, array $block): array
    {
        $offset = (int)$context['payload_start'] + (int)$block['offset'];
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek to UEDB5 section ' . $section . '.');
        }
        $compressed = $this->readExactly($handle, (int)$block['compressed_length']);
        if (!hash_equals((string)$block['sha256'], hash('sha256', $compressed))) {
            throw new RuntimeException('UEDB5 section checksum mismatch for ' . $section . '.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || strlen($json) !== (int)$block['uncompressed_length']) {
            throw new RuntimeException('Could not decompress UEDB5 section ' . $section . '.');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('UEDB5 section is invalid JSON: ' . $section . '.', 0, $error);
        }
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== (int)$block['row_count']) {
            throw new RuntimeException('UEDB5 section row count mismatch for ' . $section . '.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('UEDB5 section contains a non-array row: ' . $section . '.');
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $context @return array{0:array<string,mixed>,1:resource} */
    private function openContext(int $gameId, int $fileId, array $context): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = $this->open((string)$context['path']);
            $stat = fstat($handle);
            $signature = is_array($stat) ? $this->statSignature($stat) : '';
            if ($signature !== ''
                && hash_equals((string)($context['file_signature'] ?? ''), $signature)) {
                return [$context, $handle];
            }
            fclose($handle);
            $this->clearCache($gameId, $fileId);
            $context = $this->context($gameId, $fileId);
        }
        throw new RuntimeException('UEDB5 container changed while opening cached section data.');
    }

    private function fileSignature(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            throw new RuntimeException('UEDB5 container is missing: ' . $path);
        }
        return $this->statSignature($stat);
    }

    /** @param array<string,mixed> $stat */
    private function statSignature(array $stat): string
    {
        return implode(':', [
            (string)($stat['dev'] ?? ''),
            (string)($stat['ino'] ?? ''),
            (string)($stat['size'] ?? ''),
            (string)($stat['mtime'] ?? ''),
            (string)($stat['ctime'] ?? ''),
        ]);
    }

    /** @return resource */
    private function open(string $path)
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open UEDB5 container: ' . $path);
        }
        return $handle;
    }

    /** @param resource $handle */
    private function readExactly($handle, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Invalid UEDB5 read length.');
        }
        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read UEDB5 container.');
            }
            $buffer .= $chunk;
        }
        if (strlen($buffer) !== $length) {
            throw new RuntimeException('UEDB5 container ended unexpectedly.');
        }
        return $buffer;
    }

    private function cacheKey(int $gameId, int $fileId): string
    {
        return $gameId . ':' . $fileId;
    }
}
