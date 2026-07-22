<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogJobEventLog
{
    private const MAX_EVENT_FILE_LENGTH = 2000;
    private const MAX_EVENT_MESSAGE_LENGTH = 8000;

    private string $directory;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for job events.');
        }
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'events';
    }

    public function reset(int $jobId): void
    {
        $this->ensureDirectory();
        $path = $this->path($jobId);
        if (file_put_contents($path, '', LOCK_EX) === false) {
            throw new \RuntimeException('Could not reset the background-job event stream.');
        }
        @chmod($path, 0640);
    }

    /** @param array<string,mixed> $event */
    public function append(int $jobId, array $event): void
    {
        $this->ensureDirectory();
        $payload = [
            'status' => $this->text((string)($event['status'] ?? 'info'), 32, 'info'),
            'file' => $this->text((string)($event['file'] ?? ''), self::MAX_EVENT_FILE_LENGTH),
            'message' => $this->text((string)($event['message'] ?? ''), self::MAX_EVENT_MESSAGE_LENGTH),
            'file_id' => max(0, (int)($event['file_id'] ?? 0)),
            'pak_entry_id' => max(0, (int)($event['pak_entry_id'] ?? 0)),
            'created_at' => gmdate(DATE_ATOM),
        ];
        if (is_array($event['meta'] ?? null) && $event['meta'] !== []) {
            $payload['meta'] = $event['meta'];
        }

        $line = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $handle = fopen($this->path($jobId), 'ab');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not open the background-job event stream.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the background-job event stream.');
            }
            $offset = 0;
            $length = strlen($line);
            while ($offset < $length) {
                $written = fwrite($handle, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Could not append to the background-job event stream.');
                }
                $offset += $written;
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array{events:list<array<string,mixed>>,offset:int,has_more:bool}
     */
    public function readFrom(int $jobId, int $offset = 0, int $limit = 250): array
    {
        $path = $this->path($jobId);
        if (!is_file($path)) {
            return ['events' => [], 'offset' => 0, 'has_more' => false];
        }
        $limit = max(1, min($limit, 1000));
        $size = filesize($path);
        $offset = max(0, $offset);
        if ($size === false || $offset > (int)$size) {
            $offset = 0;
        }

        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not read the background-job event stream.');
        }
        $events = [];
        $nextOffset = $offset;
        $hasMore = false;
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Could not lock the background-job event stream for reading.');
            }
            if (fseek($handle, $offset) !== 0) {
                throw new \RuntimeException('Could not seek the background-job event stream.');
            }
            while (count($events) < $limit && ($line = fgets($handle)) !== false) {
                $nextOffset = (int)ftell($handle);
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
            $stat = fstat($handle);
            $currentSize = is_array($stat) ? (int)($stat['size'] ?? $nextOffset) : $nextOffset;
            $hasMore = $nextOffset < $currentSize;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return ['events' => $events, 'offset' => $nextOffset, 'has_more' => $hasMore];
    }

    public function remove(int $jobId): bool
    {
        $path = $this->path($jobId);
        return !is_file($path) || @unlink($path);
    }

    private function path(int $jobId): string
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('A positive background-job id is required.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . 'job-' . $jobId . '.jsonl';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create background-job event storage.');
        }
    }

    private function text(string $value, int $maximum, string $fallback = ''): string
    {
        $value = trim(str_replace("\0", '', $value));
        if ($value === '') {
            return $fallback;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximum, 'UTF-8');
        }
        return substr($value, 0, $maximum);
    }
}
