<?php
/**
 * Optional external archive fallback for RAR members that libarchive cannot decode.
 *
 * UnrealDB remains extension-first. This reader is entered only after the
 * libarchive RAR path reports a deterministic decoder/capability failure. On
 * Windows it auto-detects the standard 7-Zip installation; other deployments can
 * configure archive.external_7zip_binary or UNREALDB_7ZIP_BINARY.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogExternalArchiveReader
{
    private const LIST_OUTPUT_LIMIT = 16 * 1024 * 1024;
    private const STDERR_LIMIT = 128 * 1024;
    private const IDLE_TIMEOUT_SECONDS = 120;

    private bool $binaryResolved = false;
    private ?string $resolvedBinary = null;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null && function_exists('proc_open');
    }

    /**
     * @param callable(array<string,mixed>):array{extract:bool,max_bytes?:int,state?:mixed} $plan
     * @param callable(array<string,mixed>,?string,mixed):void $complete
     * @param null|callable():void $heartbeat
     * @return array{entries:int,decoded_bytes:int,format:string}
     */
    public function walk(
        string $archivePath,
        string $archiveName,
        int $maxDecodedBytes,
        callable $plan,
        callable $complete,
        ?callable $heartbeat = null
    ): array {
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Archive source is unavailable for external decoding.');
        }
        if (strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'rar') {
            throw new \InvalidArgumentException('External archive fallback currently supports RAR only.');
        }
        $binary = $this->binary();
        if ($binary === null || !function_exists('proc_open')) {
            throw new \RuntimeException('External 7-Zip RAR fallback is unavailable.');
        }

        $entries = $this->listEntries($binary, $archivePath, $heartbeat);
        $maxDecodedBytes = max(1, $maxDecodedBytes);
        $decodedBytes = 0;
        $processed = 0;

        foreach ($entries as $sourceEntry) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $rawPath = (string)$sourceEntry['path'];
            [$safePath, $reason] = $this->safeMemberPath($rawPath);
            $entry = [
                'index' => $processed,
                'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                'size' => max(0, (int)$sourceEntry['size']),
                'encrypted' => (bool)$sourceEntry['encrypted'],
                'safe' => $safePath !== '',
                'reason' => $reason,
                'backend' => '7zip-cli',
                'format' => 'rar',
            ];
            $processed++;

            $decision = $plan($entry);
            if (!is_array($decision) || !array_key_exists('extract', $decision)) {
                throw new \LogicException('External archive plan must return an extract decision.');
            }
            $extract = (bool)$decision['extract'];
            $state = $decision['state'] ?? null;
            $entryLimit = max(1, (int)($decision['max_bytes'] ?? $maxDecodedBytes));
            $entryBytes = max(0, (int)$entry['size']);
            $remainingTotal = $maxDecodedBytes - $decodedBytes;
            if ($remainingTotal < 1) {
                throw new \RuntimeException(
                    'Archive expansion exceeds the configured total unpacked-data limit of '
                    . number_format($maxDecodedBytes) . ' bytes.'
                );
            }
            if ($entryBytes > 0 && $entryBytes > $remainingTotal) {
                throw new \RuntimeException(
                    'Archive expansion exceeds the configured total unpacked-data limit of '
                    . number_format($maxDecodedBytes) . ' bytes.'
                );
            }

            if (!$extract) {
                $complete($entry, null, $state);
                continue;
            }

            $streamLimit = min($entryLimit, $remainingTotal);
            if ($entryBytes > 0 && $entryBytes > $streamLimit) {
                throw new \RuntimeException(
                    'Archive member exceeded its configured external decode limit while reading RAR member '
                    . (string)$entry['path'] . '.'
                );
            }

            $temporary = $this->temporaryPath();
            try {
                $actualBytes = $this->extractMember(
                    $binary,
                    $archivePath,
                    $rawPath,
                    $temporary,
                    $streamLimit,
                    $heartbeat
                );
                if ($entryBytes > 0 && $actualBytes !== $entryBytes) {
                    throw new \RuntimeException(
                        'External RAR member output size does not match its declared size; expected '
                        . number_format($entryBytes) . ' bytes, got ' . number_format($actualBytes) . ' bytes.'
                    );
                }
                if ($actualBytes < 1) {
                    throw new \RuntimeException('External RAR member produced no data.');
                }
                if ($entryBytes < 1) {
                    $entry['size'] = $actualBytes;
                }
                $decodedBytes += $actualBytes;
                $complete($entry, $temporary, $state);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        return [
            'entries' => $processed,
            'decoded_bytes' => $decodedBytes,
            'format' => 'rar-7zip-cli',
        ];
    }

    /** @return list<array{path:string,size:int,encrypted:bool}> */
    private function listEntries(string $binary, string $archivePath, ?callable $heartbeat): array
    {
        [$stdout, $stderr, $exit] = $this->runCapture(
            [$binary, 'l', '-slt', '-ba', '-bd', '-sccUTF-8', '--', $archivePath],
            self::LIST_OUTPUT_LIMIT,
            $heartbeat
        );
        if ($exit !== 0) {
            throw new \RuntimeException('7-Zip could not list the RAR archive: ' . $this->diagnostic($stderr, $exit));
        }

        $entries = [];
        $blocks = preg_split('/(?:\r?\n){2,}/', trim($stdout)) ?: [];
        foreach ($blocks as $block) {
            $fields = [];
            foreach (preg_split('/\r?\n/', trim($block)) ?: [] as $line) {
                $separator = strpos($line, ' = ');
                if ($separator === false) {
                    continue;
                }
                $fields[substr($line, 0, $separator)] = substr($line, $separator + 3);
            }
            if (!isset($fields['Path'], $fields['Size']) || !preg_match('/^\d+$/', trim((string)$fields['Size']))) {
                continue;
            }
            $attributes = strtoupper(trim((string)($fields['Attributes'] ?? '')));
            $folder = trim((string)($fields['Folder'] ?? ''));
            if ($folder === '+' || str_starts_with($attributes, 'D')) {
                continue;
            }
            $entries[] = [
                'path' => (string)$fields['Path'],
                'size' => max(0, (int)$fields['Size']),
                'encrypted' => trim((string)($fields['Encrypted'] ?? '-')) === '+',
            ];
            if (count($entries) > $this->maxEntries()) {
                throw new \RuntimeException(
                    'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                );
            }
        }
        if ($entries === []) {
            throw new \RuntimeException('7-Zip did not report any regular RAR members.');
        }
        return $entries;
    }

    private function extractMember(
        string $binary,
        string $archivePath,
        string $memberPath,
        string $temporary,
        int $maxBytes,
        ?callable $heartbeat
    ): int {
        $output = @fopen($temporary, 'wb');
        if (!is_resource($output)) {
            throw new \RuntimeException('Could not create external archive-member temporary file.');
        }

        $pipes = [];
        $process = @proc_open(
            [$binary, 'x', '-so', '-y', '-bd', '-sccUTF-8', '--', $archivePath, $memberPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Could not start the external 7-Zip decoder.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $written = 0;
        $stderr = '';
        $lastActivity = microtime(true);
        $exitCode = -1;
        try {
            while (true) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && $chunk !== '') {
                    $lastActivity = microtime(true);
                    $length = strlen($chunk);
                    $written += $length;
                    if ($written > $maxBytes) {
                        @proc_terminate($process);
                        throw new \RuntimeException('External RAR member exceeded its configured import limit.');
                    }
                    if (fwrite($output, $chunk) !== $length) {
                        @proc_terminate($process);
                        throw new \RuntimeException('Could not write external RAR member temporary data.');
                    }
                }
                $errorChunk = stream_get_contents($pipes[2]);
                if (is_string($errorChunk) && $errorChunk !== '') {
                    $lastActivity = microtime(true);
                    $stderr = substr($stderr . $errorChunk, -self::STDERR_LIMIT);
                }
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int)$status['exitcode'];
                    break;
                }
                if (microtime(true) - $lastActivity > self::IDLE_TIMEOUT_SECONDS) {
                    @proc_terminate($process);
                    throw new \RuntimeException('External 7-Zip decoder made no progress for 120 seconds.');
                }
                usleep(20000);
            }

            $tail = stream_get_contents($pipes[1]);
            if (is_string($tail) && $tail !== '') {
                $length = strlen($tail);
                $written += $length;
                if ($written > $maxBytes || fwrite($output, $tail) !== $length) {
                    throw new \RuntimeException('External RAR member exceeded its configured import/write limit.');
                }
            }
            $errorTail = stream_get_contents($pipes[2]);
            if (is_string($errorTail) && $errorTail !== '') {
                $stderr = substr($stderr . $errorTail, -self::STDERR_LIMIT);
            }
        } finally {
            fflush($output);
            fclose($output);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExit = proc_close($process);
            if ($exitCode < 0 && is_int($closedExit)) {
                $exitCode = $closedExit;
            }
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                '7-Zip could not extract RAR member "' . $memberPath . '": ' . $this->diagnostic($stderr, $exitCode)
            );
        }
        return $written;
    }

    /** @param list<string> $command @return array{0:string,1:string,2:int} */
    private function runCapture(array $command, int $stdoutLimit, ?callable $heartbeat): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP proc_open() is unavailable for external archive decoding.');
        }
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the external 7-Zip decoder.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $lastActivity = microtime(true);
        $exitCode = -1;
        try {
            while (true) {
                $out = stream_get_contents($pipes[1]);
                if (is_string($out) && $out !== '') {
                    $lastActivity = microtime(true);
                    $stdout .= $out;
                    if (strlen($stdout) > $stdoutLimit) {
                        @proc_terminate($process);
                        throw new \RuntimeException('External 7-Zip listing exceeded its bounded output limit.');
                    }
                }
                $err = stream_get_contents($pipes[2]);
                if (is_string($err) && $err !== '') {
                    $lastActivity = microtime(true);
                    $stderr = substr($stderr . $err, -self::STDERR_LIMIT);
                }
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int)$status['exitcode'];
                    break;
                }
                if (microtime(true) - $lastActivity > self::IDLE_TIMEOUT_SECONDS) {
                    @proc_terminate($process);
                    throw new \RuntimeException('External 7-Zip decoder made no progress for 120 seconds.');
                }
                usleep(20000);
            }
            $tail = stream_get_contents($pipes[1]);
            if (is_string($tail) && $tail !== '') {
                $stdout .= $tail;
            }
            $errorTail = stream_get_contents($pipes[2]);
            if (is_string($errorTail) && $errorTail !== '') {
                $stderr = substr($stderr . $errorTail, -self::STDERR_LIMIT);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExit = proc_close($process);
            if ($exitCode < 0 && is_int($closedExit)) {
                $exitCode = $closedExit;
            }
        }
        return [$stdout, $stderr, $exitCode];
    }

    private function binary(): ?string
    {
        if ($this->binaryResolved) {
            return $this->resolvedBinary;
        }
        $this->binaryResolved = true;

        $configured = trim((string)($this->config['archive']['external_7zip_binary'] ?? ''));
        $environment = trim((string)(getenv('UNREALDB_7ZIP_BINARY') ?: ''));
        foreach ([$configured, $environment] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (!str_contains($candidate, '/') && !str_contains($candidate, '\\')) {
                return $this->resolvedBinary = $candidate;
            }
            if (is_file($candidate)) {
                return $this->resolvedBinary = $candidate;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $roots = array_filter([
                getenv('ProgramFiles') ?: null,
                getenv('ProgramW6432') ?: null,
                getenv('ProgramFiles(x86)') ?: null,
                'C:\\Program Files',
                'C:\\Program Files (x86)',
            ], static fn(mixed $value): bool => is_string($value) && trim($value) !== '');
            foreach (array_unique($roots) as $root) {
                foreach (['7z.exe', '7zz.exe'] as $name) {
                    $candidate = rtrim((string)$root, "\\/") . '\\7-Zip\\' . $name;
                    if (is_file($candidate)) {
                        return $this->resolvedBinary = $candidate;
                    }
                }
            }
        }

        return $this->resolvedBinary = null;
    }

    /** @return array{0:string,1:string} */
    private function safeMemberPath(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            return ['', 'empty/control-character path'];
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return ['', 'absolute path'];
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return ['', 'parent-directory traversal'];
            }
            $part = rtrim($part, " .\t\r\n");
            if ($part === '') {
                return ['', 'empty path component'];
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return ['', 'empty normalized path'];
        }
        $safe = implode('/', $parts);
        return strlen($safe) <= 2048 ? [$safe, ''] : ['', 'path is too long'];
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-archive-7z-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate external archive-member temporary storage.');
        }
        return $path;
    }

    private function maxEntries(): int
    {
        return max(1, min(100000, (int)($this->config['archive']['max_entries'] ?? 10000)));
    }

    private function diagnostic(string $stderr, int $exitCode): string
    {
        $stderr = trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr);
        if ($stderr === '') {
            return '7-Zip exit code ' . $exitCode . '.';
        }
        return (function_exists('mb_substr') ? mb_substr($stderr, 0, 1000, 'UTF-8') : substr($stderr, 0, 1000))
            . ' (exit ' . $exitCode . ').';
    }
}
