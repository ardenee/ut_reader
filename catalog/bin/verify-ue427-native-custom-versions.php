<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';

// Compare custom-version GUID/version multisets from the Epic C++ summary
// against UnrealDB for EVERY corpus input, keyed by input index (not basename).
$log = $argv[1] ?? 'C:/Temp/ue427-all-native-custom-stdout.log';
$list = $argv[2] ?? 'C:/Temp/ue427-all-assets.txt';
$raw = @file_get_contents($log);
$files = @file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_string($raw) || !is_array($files)) throw new RuntimeException('Missing native log or corpus manifest.');
if (str_starts_with($raw, "\xFF\xFE")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
if (isset($files[0])) $files[0] = preg_replace('/^\xEF\xBB\xBF/', '', $files[0]);
$counts = [];
$versions = [];
foreach (explode("\n", $raw) as $line) {
    if (($pos = strpos($line, 'UE427_NATIVE_CUSTOM_COUNT|')) !== false) {
        $parts = explode('|', trim(substr($line, $pos + strlen('UE427_NATIVE_CUSTOM_COUNT|'))));
        if (count($parts) !== 2) throw new RuntimeException('Malformed native custom-count row.');
        $counts[(int)$parts[0]] = (int)$parts[1];
    } elseif (($pos = strpos($line, 'UE427_NATIVE_CUSTOM|')) !== false) {
        $parts = explode('|', trim(substr($line, $pos + strlen('UE427_NATIVE_CUSTOM|'))));
        if (count($parts) !== 3) throw new RuntimeException('Malformed native custom-version row.');
        $versions[(int)$parts[0]][] = strtoupper($parts[1]) . ':' . (int)$parts[2];
    }
}
$failures = [];
$versionEntriesCompared = 0;
$markers = [];
$formats = [];
foreach ($files as $index => $path) {
    if (!isset($counts[$index])) {
        $failures[] = ['index' => $index, 'path' => $path, 'error' => 'missing native custom count'];
        continue;
    }
    $reader = new UnrealPackageReader4($path);
    $h = $reader->getHeader();
    if ($reader->validatePackage() !== []) {
        $failures[] = ['index' => $index, 'path' => $path, 'error' => $reader->validatePackage()];
        continue;
    }
    $marker = (string)($h['legacyFileVersion'] ?? '');
    $markers[$marker] = ($markers[$marker] ?? 0) + 1;
    $phpVersions = [];
    foreach (($h['customVersions'] ?? []) as $v) {
        $format = (string)($v['format'] ?? '');
        $formats[$format] = ($formats[$format] ?? 0) + 1;
        if (!isset($v['guid'])) {
            $failures[] = ['index' => $index, 'path' => $path, 'error' => 'legacy enum custom version needs separate mapping'];
            continue;
        }
        $phpVersions[] = strtoupper((string)$v['guid']) . ':' . (int)$v['version'];
    }
    $native = $versions[$index] ?? [];
    if ($counts[$index] !== count($native) || count($phpVersions) !== $counts[$index]) {
        $failures[] = ['index' => $index, 'path' => $path,
            'error' => 'entry count differs', 'native_declared' => $counts[$index],
            'native_seen' => count($native), 'unrealdb' => count($phpVersions)];
        continue;
    }
    sort($native, SORT_STRING);
    sort($phpVersions, SORT_STRING);
    $versionEntriesCompared += count($native);
    if ($native !== $phpVersions) {
        $failures[] = ['index' => $index, 'path' => $path,
            'error' => 'GUID/version identity mismatch', 'native' => $native, 'unrealdb' => $phpVersions];
    }
}
$ok = count($counts) === count($files) && $failures === [];
ksort($markers); ksort($formats);
echo json_encode(['ok' => $ok, 'corpus_packages' => count($files),
    'native_custom_count_records' => count($counts),
    'custom_version_entries_compared' => $versionEntriesCompared,
    'legacy_markers' => $markers, 'custom_formats' => $formats,
    'mismatch_count' => count($failures),
    'mismatches' => array_slice($failures, 0, 20)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($ok ? 0 : 2);
