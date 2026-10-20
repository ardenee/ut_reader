<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';
$log = $argv[1] ?? 'C:/Temp/ue427-legacy-guid-native-friendly.log';
$list = $argv[2] ?? 'C:/Temp/ue427-legacy-guid-fixtures/list.txt';
$raw = @file_get_contents($log);
$paths = @file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_string($raw) || !is_array($paths)) throw new RuntimeException('Native evidence not available');
if (str_starts_with($raw, "\xFF\xFE")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
if (isset($paths[0])) $paths[0] = preg_replace('/^\xEF\xBB\xBF/', '', $paths[0]);
$native = [];
foreach (explode("\n", $raw) as $line) {
    $marker = 'UE427_NATIVE_CUSTOM_FRIENDLY|';
    $offset = strpos($line, $marker);
    if ($offset === false) continue;
    $parts = explode('|', trim(substr($line, $offset + strlen($marker))));
    if (count($parts) !== 3) throw new RuntimeException('Malformed native friendly-name row');
    $native[(int)$parts[0]][strtoupper($parts[1])] = $parts[2];
}
$expectedCounts = [-3 => 2, -4 => 1, -5 => 1];
$results = [];
foreach ($paths as $index => $path) {
    $reader = new UnrealPackageReader4($path);
    $header = $reader->getHeader();
    $marker = (int)($header['legacyFileVersion'] ?? 0);
    $versions = $header['customVersions'] ?? [];
    $ok = $reader->validatePackage() === [] && count($versions) === ($expectedCounts[$marker] ?? -1);
    $cases = [];
    foreach ($versions as $v) {
        $actual = (string)($v['friendlyName'] ?? '');
        $fromEpic = $native[$index][strtoupper((string)($v['guid'] ?? ''))] ?? null;
        $pass = $fromEpic !== null && $fromEpic === $actual && $v['format'] === 'guid-deprecated';
        $cases[] = ['guid' => $v['guid'] ?? null, 'native' => $fromEpic, 'unrealdb' => $actual, 'match' => $pass];
        $ok = $pass && $ok;
    }
    $results[] = ['file' => basename($path), 'legacy_marker' => $marker, 'ok' => $ok, 'entries' => $cases];
}
$ok = count($results) === 3 && count($native) === 3 && !in_array(false, array_column($results, 'ok'), true);
echo json_encode(['ok' => $ok, 'fixtures' => count($results), 'friendly_names_compared' => array_sum(array_map(static fn(array $row): int => count($row['entries']), $results)), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($ok ? 0 : 2);
