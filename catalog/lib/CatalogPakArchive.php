<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function catalog_pak_archive_extension(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return $ext === 'pak' ? 'pak' : '';
}

function catalog_pak_archive_is_supported_filename(string $filename): bool
{
    return catalog_pak_archive_extension($filename) !== '';
}

function catalog_pak_archive_config(array $config): array
{
    $pak = $config['pak'] ?? $config['pak_extract'] ?? [];
    return is_array($pak) ? $pak : [];
}

function catalog_pak_archive_tool_path(array $config): string
{
    $pak = catalog_pak_archive_config($config);
    $path = trim((string)($pak['unrealpak_path'] ?? $pak['tool_path'] ?? getenv('UNREALDB_UNREALPAK') ?: ''));
    if ($path === '') {
        throw new RuntimeException('PAK extraction is not configured. Set config.php pak.unrealpak_path to UnrealPak.exe.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Configured UnrealPak tool was not found: ' . $path);
    }
    return $path;
}

function catalog_pak_archive_crypto_keys_path(array $config): string
{
    $pak = catalog_pak_archive_config($config);
    $path = trim((string)($pak['crypto_keys_path'] ?? $pak['cryptokeys_path'] ?? getenv('UNREALDB_UNREALPAK_CRYPTOKEYS') ?: ''));
    if ($path !== '' && !is_file($path)) {
        throw new RuntimeException('Configured UnrealPak Crypto.json was not found: ' . $path);
    }
    return $path;
}

function catalog_pak_archive_max_files(array $config): int
{
    $pak = catalog_pak_archive_config($config);
    return max(1, (int)($pak['max_extracted_files'] ?? 20000));
}

function catalog_pak_archive_max_bytes(array $config): int
{
    $pak = catalog_pak_archive_config($config);
    return max(1, (int)($pak['max_extracted_bytes'] ?? (8 * 1024 * 1024 * 1024)));
}

function catalog_pak_archive_timeout(array $config): int
{
    $pak = catalog_pak_archive_config($config);
    return max(30, (int)($pak['timeout_seconds'] ?? 1800));
}

function catalog_pak_archive_temp_dir(string $prefix = 'ue_pak_'): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($base, 0775, true) && !is_dir($base)) {
        throw new RuntimeException('Could not create temporary PAK extraction folder.');
    }
    return $base;
}

function catalog_pak_archive_delete_tree(string $path): void
{
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        return;
    }

    $tmpRoot = realpath(sys_get_temp_dir());
    if ($tmpRoot === false || !str_starts_with($real, rtrim($tmpRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item instanceof SplFileInfo) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
    @rmdir($real);
}

function catalog_pak_archive_relative_path(string $base, string $path): string
{
    $baseReal = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $pathReal = str_replace('\\', '/', realpath($path) ?: $path);
    if (str_starts_with($pathReal, $baseReal)) {
        return ltrim(substr($pathReal, strlen($baseReal)), '/');
    }
    return basename($path);
}

function catalog_pak_archive_command(array $config, string $pakPath, string $extractDir, string $logPath): string
{
    $parts = [
        catalog_pak_archive_tool_path($config),
        $pakPath,
        '-Extract',
        $extractDir,
    ];

    $cryptoKeys = catalog_pak_archive_crypto_keys_path($config);
    if ($cryptoKeys !== '') {
        $parts[] = '-cryptokeys=' . $cryptoKeys;
    }

    return implode(' ', array_map('escapeshellarg', $parts)) . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
}

function catalog_pak_archive_run(string $command, int $timeoutSeconds, string $workingDir): int
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PAK extraction requires PHP proc_open to be enabled.');
    }

    $process = proc_open($command, [], $pipes, $workingDir);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start UnrealPak extraction process.');
    }

    $started = time();
    do {
        $status = proc_get_status($process);
        if (empty($status['running'])) {
            $exitCode = (int)($status['exitcode'] ?? 0);
            proc_close($process);
            return $exitCode;
        }
        if (time() - $started > $timeoutSeconds) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('UnrealPak extraction timed out after ' . $timeoutSeconds . ' seconds.');
        }
        usleep(200000);
    } while (true);
}

/** @return list<array{path:string,relative:string,bytes:int}> */
function catalog_pak_archive_collect_files(array $config, string $extractDir): array
{
    $extractReal = realpath($extractDir);
    if ($extractReal === false) {
        throw new RuntimeException('PAK extraction folder disappeared.');
    }

    $maxFiles = catalog_pak_archive_max_files($config);
    $maxBytes = catalog_pak_archive_max_bytes($config);
    $files = [];
    $bytes = 0;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractReal, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, rtrim($extractReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $size = (int)$item->getSize();
        $bytes += $size;
        if (count($files) + 1 > $maxFiles) {
            throw new RuntimeException('PAK extraction produced too many files; limit is ' . $maxFiles . '.');
        }
        if ($bytes > $maxBytes) {
            throw new RuntimeException('PAK extraction exceeded byte limit: ' . catalog_bytes($maxBytes) . '.');
        }

        $files[] = [
            'path' => $real,
            'relative' => catalog_pak_archive_relative_path($extractReal, $real),
            'bytes' => $size,
        ];
    }

    return $files;
}

/** @return array{dir:string,files:list<array{path:string,relative:string,bytes:int}>,log:string,source_name:string} */
function catalog_pak_archive_extract_to_temp(array $config, string $pakPath, string $sourceName = ''): array
{
    if (!catalog_pak_archive_is_supported_filename($sourceName !== '' ? $sourceName : $pakPath)) {
        throw new RuntimeException('Not an Unreal PAK file: ' . basename($sourceName !== '' ? $sourceName : $pakPath));
    }
    if (!is_file($pakPath)) {
        throw new RuntimeException('PAK file is missing.');
    }

    $workDir = catalog_pak_archive_temp_dir('ue_pak_work_');
    $extractDir = $workDir . DIRECTORY_SEPARATOR . 'extract';
    if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
        catalog_pak_archive_delete_tree($workDir);
        throw new RuntimeException('Could not create PAK extraction output folder.');
    }

    $logPath = $workDir . DIRECTORY_SEPARATOR . 'unrealpak.log';
    $command = catalog_pak_archive_command($config, $pakPath, $extractDir, $logPath);

    try {
        $exitCode = catalog_pak_archive_run($command, catalog_pak_archive_timeout($config), $workDir);
        $log = is_file($logPath) ? trim((string)file_get_contents($logPath)) : '';
        if ($exitCode !== 0) {
            throw new RuntimeException('UnrealPak extraction failed with exit code ' . $exitCode . ($log !== '' ? ': ' . substr($log, -1200) : '.'));
        }

        $files = catalog_pak_archive_collect_files($config, $extractDir);
        if ($files === []) {
            throw new RuntimeException('UnrealPak extraction completed but produced no files.' . ($log !== '' ? ' Log: ' . substr($log, -800) : ''));
        }

        return [
            'dir' => $workDir,
            'files' => $files,
            'log' => $log,
            'source_name' => basename($sourceName !== '' ? $sourceName : $pakPath),
        ];
    } catch (Throwable $error) {
        catalog_pak_archive_delete_tree($workDir);
        throw $error;
    }
}
