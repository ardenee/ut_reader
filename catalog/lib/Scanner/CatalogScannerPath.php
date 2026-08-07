<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Package/file naming and source-path policy used by catalog scanning.
 * Role: Compatibility functions retained for existing scanner callers while the scanner monolith is decomposed.
 */
declare(strict_types=1);

function scanner_clean_name(string $s): string
{
    $s = str_replace(["\0", "\\"], ['', '/'], $s);
    $s = preg_replace('#/+#', '/', $s) ?? $s;
    return trim($s);
}

function scanner_clean_original_filename(string $originalName): string
{
    /*
     * catalog_clean_unreal_filename() intentionally normalizes unusual legacy
     * filenames, but '+' is a valid serialized UE4 package-name character.
     * Protect it while retaining the existing filename cleanup rules.
     */
    $placeholder = '__UE_PACKAGE_PLUS__';
    while (str_contains($originalName, $placeholder)) {
        $placeholder .= '_';
    }
    $clean = catalog_clean_unreal_filename(str_replace('+', $placeholder, $originalName));
    return str_replace($placeholder, '+', $clean);
}

function scanner_slug_text(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

function scanner_join_path_parts(array $parts): string
{
    return implode('.', array_values(array_filter(array_map('scanner_clean_name', $parts), static fn($v) => $v !== '')));
}

function scanner_logical_package_name(string $originalName): string
{
    $cleanName = scanner_clean_original_filename($originalName);
    return scanner_clean_name((string)pathinfo($cleanName, PATHINFO_FILENAME));
}

function scanner_package_leaf(string $packageName): string
{
    $packageName = rtrim(trim(str_replace('\\', '/', $packageName)), '/');
    if ($packageName === '') {
        return '';
    }
    $slash = strrpos($packageName, '/');
    return $slash === false ? $packageName : substr($packageName, $slash + 1);
}

function scanner_normalize_source_relative_path(string $path): string
{
    $path = str_replace(["\0", "\\"], ['', '/'], trim($path));
    $path = preg_replace('#^[A-Za-z]:/#', '', $path) ?? $path;
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = trim($path, "/ \t\n\r\0\x0B");
    if ($path === '') {
        return '';
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts !== []) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function scanner_original_name_from_source_relative(string $sourceRelativePath): string
{
    $sourceRelativePath = scanner_normalize_source_relative_path($sourceRelativePath);
    if ($sourceRelativePath === '') {
        return '';
    }
    $parts = explode('/', $sourceRelativePath);
    return scanner_clean_original_filename((string)end($parts));
}

function scanner_clean_package_path_segment(string $segment): string
{
    $segment = trim(str_replace(["\0", '/', '\\'], ['', '', ''], $segment));
    $segment = preg_replace('/\s+/', ' ', $segment) ?? $segment;
    $segment = preg_replace('/[^A-Za-z0-9._ +\-]+/', '_', $segment) ?? $segment;
    return trim($segment, " \t\n\r\0\x0B.");
}

function scanner_ue_package_name_from_source_relative(string $sourceRelativePath): string
{
    $relative = scanner_normalize_source_relative_path($sourceRelativePath);
    if ($relative === '') {
        return '';
    }

    $parts = explode('/', $relative);
    $root = '/Game';
    $contentIndex = -1;
    foreach ($parts as $index => $part) {
        if (strtolower((string)$part) === 'content') {
            $contentIndex = $index;
        }
    }

    if ($contentIndex >= 0) {
        $pluginIndex = -1;
        for ($index = 0; $index < $contentIndex; $index++) {
            if (strtolower((string)$parts[$index]) === 'plugins') {
                $pluginIndex = $index;
            }
        }

        if ($pluginIndex >= 0 && $contentIndex > $pluginIndex + 1) {
            $pluginRoot = scanner_clean_package_path_segment((string)$parts[$contentIndex - 1]);
            if ($pluginRoot !== '') {
                $root = '/' . $pluginRoot;
            }
        } elseif ($contentIndex > 0 && strtolower((string)$parts[$contentIndex - 1]) === 'engine') {
            $root = '/Engine';
        }
        $parts = array_slice($parts, $contentIndex + 1);
    } elseif (isset($parts[0]) && strtolower((string)$parts[0]) === 'game') {
        $parts = array_slice($parts, 1);
    } elseif (isset($parts[0]) && strtolower((string)$parts[0]) === 'engine') {
        $root = '/Engine';
        $parts = array_slice($parts, 1);
    }

    if ($parts === []) {
        return '';
    }

    $last = array_pop($parts);
    $lastClean = scanner_clean_original_filename((string)$last);
    $leaf = scanner_clean_package_path_segment((string)pathinfo($lastClean, PATHINFO_FILENAME));
    if ($leaf === '') {
        return '';
    }

    $cleanParts = [];
    foreach ($parts as $part) {
        $clean = scanner_clean_package_path_segment((string)$part);
        if ($clean !== '') {
            $cleanParts[] = $clean;
        }
    }
    $cleanParts[] = $leaf;

    return $root . '/' . implode('/', $cleanParts);
}

function scanner_package_name_from_reader(string $fallbackPackageName, string $readerEngine, array $names, array $header): string
{
    /*
     * UT4's FPackageReader derives PackageName from PackageFilename using
     * FPackageName::FilenameToLongPackageName(). AssetRegistryData stores
     * object/class/tag rows inside that package; it does not replace the
     * package identity. Keep UE4/UE5 package identity on the mounted source
     * path calculated before the reader opened the file.
     */
    return $fallbackPackageName;
}
