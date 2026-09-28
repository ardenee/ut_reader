<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads staged UEDB5 files directly from disk without consulting production metadata registration.
 * Why: Format 5 must be verifiable during migration while the live catalog remains format 4 only.
 * Role: Offline/staging reader; it is intentionally not wired into production page or dependency runtime services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use RuntimeException;

final class Uedb5MetadataStagingReader
{
    /** @var array<string,mixed> */
    private array $manifest;
    private int $payloadStart;

    public function __construct(
        private readonly string $path,
        private readonly int $fileId
    ) {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required for staged UEDB5 metadata.');
        }
        $verified = Uedb5MetadataContainer::verifyFile($path, $fileId);
        $this->manifest = (array)$verified['manifest'];
        $this->payloadStart = (int)$verified['payload_start'];
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function sectionNames(): array
    {
        return array_values(array_map('strval', array_keys((array)$this->manifest['sections'])));
    }

    public function count(string $section): int
    {
        $section = $this->section($section);
        return (int)($this->manifest['counts'][$section] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function page(string $section, int $start, int $limit): array
    {
        $section = $this->section($section);
        $start = max(0, $start);
        $limit = max(1, min(5000, $limit));
        $end = $start + $limit;
        $rows = [];

        $handle = @fopen($this->path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open staged UEDB5 container.');
        }
        try {
            foreach ((array)$this->manifest['sections'][$section] as $block) {
                $blockStart = (int)$block['row_start'];
                $blockEnd = $blockStart + (int)$block['row_count'];
                if ($blockEnd <= $start || $blockStart >= $end) {
                    continue;
                }
                $decoded = $this->readBlock($handle, $section, (array)$block);
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

    /** @return \Generator<int,array<string,mixed>> */
    public function scan(string $section): \Generator
    {
        $section = $this->section($section);
        $handle = @fopen($this->path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open staged UEDB5 container.');
        }
        try {
            foreach ((array)$this->manifest['sections'][$section] as $block) {
                foreach ($this->readBlock($handle, $section, (array)$block) as $row) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<string,mixed> $block
     * @return list<array<string,mixed>>
     */
    private function readBlock($handle, string $section, array $block): array
    {
        $offset = $this->payloadStart + (int)$block['offset'];
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek to staged UEDB5 section ' . $section . '.');
        }
        $compressed = $this->readExactly($handle, (int)$block['compressed_length']);
        if (!hash_equals((string)$block['sha256'], hash('sha256', $compressed))) {
            throw new RuntimeException('Staged UEDB5 section checksum mismatch for ' . $section . '.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || strlen($json) !== (int)$block['uncompressed_length']) {
            throw new RuntimeException('Could not decompress staged UEDB5 section ' . $section . '.');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Staged UEDB5 section is invalid JSON: ' . $section . '.', 0, $error);
        }
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== (int)$block['row_count']) {
            throw new RuntimeException('Staged UEDB5 section row count mismatch for ' . $section . '.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Staged UEDB5 section contains a non-array row: ' . $section . '.');
            }
        }
        return $rows;
    }

    private function section(string $section): string
    {
        $section = trim($section);
        if ($section === '' || !array_key_exists($section, (array)$this->manifest['sections'])) {
            throw new RuntimeException('Unknown staged UEDB5 section: ' . $section);
        }
        return $section;
    }

    /** @param resource $handle */
    private function readExactly($handle, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Invalid staged UEDB5 read length.');
        }
        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read staged UEDB5 container.');
            }
            $buffer .= $chunk;
        }
        if (strlen($buffer) !== $length) {
            throw new RuntimeException('Staged UEDB5 container ended unexpectedly.');
        }
        return $buffer;
    }
}
