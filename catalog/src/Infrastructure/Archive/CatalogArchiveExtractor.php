<?php
/**
 * Safe unpack-only ZIP/7z/RAR reader used by catalog ingestion jobs.
 *
 * Archives are never extracted wholesale into a filesystem tree. Entries are
 * listed first, validated, then one requested regular file is streamed to a
 * temporary file. This avoids path traversal and lets callers impose their own
 * Unreal-file policy before any member is unpacked.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogArchiveExtractor
{
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar'];
    private const LIST_OUTPUT_LIMIT = 16 * 1024 * 1024;

    private ?string $sevenZipBinary = null;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function isArchiveName(string $name): bool
    {
        return in_array(strtolower((string)pathinfo($name, PATHINFO_EXTENSION)), self::ARCHIVE_EXTENSIONS, true);
    }

    /**
     * @return list<array{
     *   index:int,
     *   path:string,
     *   size:int,
     *   encrypted:bool,
     *   safe:bool,
     *   reason:string,
     *   backend:string
     * }>
     */
    public function entries(string $archivePath, string $archiveName): array
    {
        $this->requireArchive($archivePath, $archiveName);
        $extension = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));

        if ($extension === 'zip' && class_exists(\ZipArchive::class)) {
            return $this->zipEntries($archivePath);
        }
        return $this->sevenZipEntries($archivePath);
    }

    /**
     * Extract one previously listed entry to a temporary regular file.
     * Caller owns the returned file and must unlink it.
     *
     * @param array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string} $entry
     */
    public function extractToTemp(string $archivePath, string $archiveName, array $entry, int $maxBytes): string
    {
        $this->requireArchive($archivePath, $archiveName);
        if (empty($entry['safe'])) {
            throw new \RuntimeException('Archive member is unsafe: ' . (string)($entry['reason'] ?? 'invalid path'));
        }
        if (!empty($entry['encrypted'])) {
            throw new \RuntimeException('Encrypted/password-protected archive members are not supported.');
        }

        $expected = (int)($entry['size'] ?? -1);
        $maxBytes = max(1, $maxBytes);
        if ($expected < 1 || $expected > $maxBytes) {
            throw new \RuntimeException('Archive member exceeds the configured extraction limit.');
        }

        $backend = (string)($entry['backend'] ?? '');
        return $backend === 'zip'
            ? $this->extractZipEntry($archivePath, $entry, $maxBytes)
            : $this->extractSevenZipEntry($archivePath, $entry, $maxBytes);
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> */
    private function zipEntries(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open ZIP archive (ZipArchive code ' . (string)$opened . ').');
        }

        $entries = [];
        $maxEntries = $this->maxEntries();
        try {
            if ($zip->numFiles > $maxEntries) {
                throw new \RuntimeException('Archive contains too many entries; limit is ' . number_format($maxEntries) . '.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    continue;
                }
                $rawPath = (string)($stat['name'] ?? '');
                if ($rawPath === '' || str_ends_with(str_replace('\\', '/', $rawPath), '/')) {
                    continue;
                }

                $symlink = false;
                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $opsys = 0;
                    $attributes = 0;
                    if ($zip->getExternalAttributesIndex($index, $opsys, $attributes, \ZipArchive::FL_UNCHANGED)) {
                        $mode = ($attributes >> 16) & 0170000;
                        $symlink = $mode === 0120000;
                    }
                }

                $encrypted = false;
                if (method_exists($zip, 'getEncryptionName')) {
                    $encryption = $zip->getEncryptionName($index);
                    $encrypted = is_string($encryption) && $encryption !== '' && strtolower($encryption) !== 'none';
                }

                [$safePath, $reason] = $this->safeMemberPath($rawPath);
                if ($symlink) {
                    $safePath = '';
                    $reason = 'symbolic-link entries are not accepted';
                }
                $entries[] = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                    'size' => max(0, (int)($stat['size'] ?? 0)),
                    'encrypted' => $encrypted,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'zip',
                ];
            }
        } finally {
            $zip->close();
        }

        return $this->stableOrder($entries);
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> */
    private function sevenZipEntries(string $archivePath): array
    {
        $binary = $this->sevenZipBinary();
        $result = $this->capture([
            $binary,
            'l',
            '-slt',
            '-sccUTF-8',
            '-p__UNREALDB_REJECT_ENCRYPTED__',
            '--',
            $archivePath,
        ]);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException(
                '7-Zip could not list the archive: ' . $this->shortError($result['stderr'], $result['stdout'])
            );
        }

        $blocks = preg_split('/\R\s*\R/u', str_replace("\r\n", "\n", $result['stdout'])) ?: [];
        $entries = [];
        $index = 0;
        foreach ($blocks as $block) {
            $properties = [];
            foreach (preg_split('/\R/u', trim($block)) ?: [] as $line) {
                $separator = strpos($line, ' = ');
                if ($separator === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $separator));
                $value = substr($line, $separator + 3);
                if ($key !== '') {
                    $properties[$key] = $value;
                }
            }
            if (!isset($properties['Path'], $properties['Size'])) {
                continue;
            }
            if (($properties['Folder'] ?? '-') === '+' || str_contains((string)($properties['Attributes'] ?? ''), 'D')) {
                continue;
            }

            $rawPath = (string)$properties['Path'];
            [$safePath, $reason] = $this->safeMemberPath($rawPath);
            if (isset($properties['Symbolic Link']) || isset($properties['Hard Link'])) {
                $safePath = '';
                $reason = 'link entries are not accepted';
            }
            $entries[] = [
                'index' => $index++,
                'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                'size' => max(0, (int)$properties['Size']),
                'encrypted' => ($properties['Encrypted'] ?? '-') === '+',
                'safe' => $safePath !== '',
                'reason' => $reason,
                'backend' => '7zip',
            ];
            if (count($entries) > $this->maxEntries()) {
                throw new \RuntimeException(
                    'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                );
            }
        }

        return $this->stableOrder($entries);
    }

    /** @param array{index:int,path:string,size:int} $entry */
    private function extractZipEntry(string $archivePath, array $entry, int $maxBytes): string
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('Could not reopen ZIP archive for extraction.');
        }

        $temporary = $this->temporaryPath();
        $input = null;
        $output = null;
        try {
            if (method_exists($zip, 'getStreamIndex')) {
                $input = $zip->getStreamIndex((int)$entry['index'], \ZipArchive::FL_UNCHANGED);
            } else {
                $input = $zip->getStream((string)$entry['path']);
            }
            if (!is_resource($input)) {
                throw new \RuntimeException('Could not open ZIP member stream.');
            }
            $output = fopen($temporary, 'wb');
            if (!is_resource($output)) {
                throw new \RuntimeException('Could not create temporary archive member.');
            }

            $written = 0;
            while (!feof($input)) {
                $buffer = fread($input, 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read ZIP member stream.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new \RuntimeException('ZIP member stream stopped unexpectedly.');
                }
                $written += strlen($buffer);
                if ($written > $maxBytes) {
                    throw new \RuntimeException('Archive member exceeded the configured extraction limit while unpacking.');
                }
                if (fwrite($output, $buffer) !== strlen($buffer)) {
                    throw new \RuntimeException('Could not write temporary archive member.');
                }
            }
            fflush($output);
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $zip->close();
        }

        $this->verifyExtractedFile($temporary, (int)$entry['size'], $maxBytes);
        return $temporary;
    }

    /** @param array{path:string,size:int} $entry */
    private function extractSevenZipEntry(string $archivePath, array $entry, int $maxBytes): string
    {
        $temporary = $this->temporaryPath();
        $stderr = null;
        $process = @proc_open(
            [
                $this->sevenZipBinary(),
                'x',
                '-so',
                '-y',
                '-spd',
                '-p__UNREALDB_REJECT_ENCRYPTED__',
                '--',
                $archivePath,
                (string)$entry['path'],
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $temporary, 'wb'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not start 7-Zip extraction process.');
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            @unlink($temporary);
            throw new \RuntimeException('7-Zip could not unpack archive member: ' . $this->shortError((string)$stderr, ''));
        }

        $this->verifyExtractedFile($temporary, (int)$entry['size'], $maxBytes);
        return $temporary;
    }

    private function verifyExtractedFile(string $path, int $expectedBytes, int $maxBytes): void
    {
        if (!is_file($path) || is_link($path)) {
            @unlink($path);
            throw new \RuntimeException('Archive member did not produce a regular file.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || (int)$size > $maxBytes || (int)$size !== $expectedBytes) {
            @unlink($path);
            throw new \RuntimeException('Archive member output size does not match its declared size.');
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function capture(array $command): array
    {
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start 7-Zip. Set UNREALDB_7ZIP_BINARY if it is not on PATH.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], self::LIST_OUTPUT_LIMIT + 1);
        $stderr = stream_get_contents($pipes[2], self::LIST_OUTPUT_LIMIT + 1);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        if (strlen($stdout) > self::LIST_OUTPUT_LIMIT || strlen($stderr) > self::LIST_OUTPUT_LIMIT) {
            throw new \RuntimeException('Archive listing output exceeded the safety limit.');
        }
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function sevenZipBinary(): string
    {
        if ($this->sevenZipBinary !== null) {
            return $this->sevenZipBinary;
        }
        $configured = trim((string)($this->config['archive']['seven_zip_binary'] ?? getenv('UNREALDB_7ZIP_BINARY') ?: ''));
        if ($configured !== '') {
            return $this->sevenZipBinary = $configured;
        }

        foreach (['7zz', '7z', '7za'] as $candidate) {
            $probe = @proc_open(
                [$candidate, 'i'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($probe)) {
                continue;
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($probe);
            if ($exit === 0) {
                return $this->sevenZipBinary = $candidate;
            }
        }
        throw new \RuntimeException(
            '7z/RAR extraction requires a 7-Zip command-line binary (7zz, 7z or 7za). '
            . 'Install it on the server or set UNREALDB_7ZIP_BINARY.'
        );
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
        if (strlen($safe) > 2048) {
            return ['', 'path is too long'];
        }
        return [$safe, ''];
    }

    /** @param list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> $entries */
    private function stableOrder(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $path = strnatcasecmp((string)$left['path'], (string)$right['path']);
            return $path !== 0 ? $path : ((int)$left['index'] <=> (int)$right['index']);
        });
        return array_values($entries);
    }

    private function maxEntries(): int
    {
        return max(1, min(100000, (int)($this->config['archive']['max_entries'] ?? 10000)));
    }

    private function requireArchive(string $archivePath, string $archiveName): void
    {
        if (!self::isArchiveName($archiveName)) {
            throw new \InvalidArgumentException('Unsupported archive extension: ' . (string)pathinfo($archiveName, PATHINFO_EXTENSION));
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Archive source is unavailable.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-archive-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate temporary archive-member storage.');
        }
        return $path;
    }

    private function shortError(string $primary, string $fallback): string
    {
        $message = trim($primary) !== '' ? trim($primary) : trim($fallback);
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        if ($message === '') {
            return 'unknown archive error';
        }
        return function_exists('mb_substr') ? mb_substr($message, 0, 700, 'UTF-8') : substr($message, 0, 700);
    }
}
