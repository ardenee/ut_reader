#!/usr/bin/env php
<?php
/**
 * Static gate proving normal runtime contains no v2 compact-metadata paths.
 * Offline v3 migration tooling is excluded until cutover cleanup.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$patterns = [
    '/\\.uedb2/i' => '.uedb2',
    '/UEDBM2/' => 'UEDBM2',
    '/format[_ -]?version\\s*=\\s*2/i' => 'format_version=2',
    '/format-2/i' => 'format-2',
    '/version-2/i' => 'version-2',
    '/\\[\\s*2\\s*,\\s*BlockedCompressedMetadataContainer::FORMAT_VERSION/' => '[2, current-format]',
];
$excludedPrefixes = [
    'bin/v3-migration/',
    'migrations/',
];
$excludedFiles = [
    'bin/upgrade-blocked-metadata-v3.php',
    'bin/verify-v3-only-runtime.php',
];
$hits = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'sql'], true)) {
        continue;
    }
    $real = realpath($file->getPathname()) ?: $file->getPathname();
    $relative = str_replace('\\', '/', substr($real, strlen($root) + 1));
    if (in_array($relative, $excludedFiles, true)) {
        continue;
    }
    $excluded = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            $excluded = true;
            break;
        }
    }
    if ($excluded) {
        continue;
    }
    $source = @file_get_contents($real);
    if (!is_string($source)) {
        $hits[] = ['file' => $relative, 'line' => 0, 'kind' => 'unreadable'];
        continue;
    }
    foreach (preg_split('/\\R/', $source) ?: [] as $lineNo => $line) {
        foreach ($patterns as $pattern => $kind) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = [
                    'file' => $relative,
                    'line' => $lineNo + 1,
                    'kind' => $kind,
                    'text' => trim($line),
                ];
            }
        }
    }
}
$result = [
    'ok' => $hits === [],
    'scope' => 'runtime',
    'migration_tooling_excluded' => true,
    'hits' => $hits,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($hits === [] ? 0 : 2);
