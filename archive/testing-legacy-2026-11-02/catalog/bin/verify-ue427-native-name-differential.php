<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';
$log = $argv[1] ?? 'C:/Temp/ue427-native-names-stdout.log';
$list = $argv[2] ?? 'C:/Temp/ue427-p01-real-assets.txt';
$raw = @file_get_contents($log);
$paths = @file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_string($raw) || !is_array($paths)) throw new RuntimeException('Native name oracle log or asset manifest unavailable.');
if (str_starts_with($raw, "\xFF\xFE")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
if (isset($paths[0])) $paths[0] = preg_replace('/^\xEF\xBB\xBF/', '', $paths[0]);
$counts = []; $names = []; $nativeErrors = [];
foreach (explode("\n", $raw) as $line) {
    if (($p = strpos($line, 'UE427_NATIVE_NAME_COUNT|')) !== false) {
        $parts = explode('|', trim(substr($line, $p + strlen('UE427_NATIVE_NAME_COUNT|'))));
        if (count($parts) !== 2) throw new RuntimeException('Malformed native name-count log row');
        $index = (int)$parts[0];
        if (array_key_exists($index, $counts)) throw new RuntimeException('Duplicate native name-count row');
        $counts[$index] = (int)$parts[1];
    } elseif (($p = strpos($line, 'UE427_NATIVE_NAME|')) !== false) {
        $parts = explode('|', trim(substr($line, $p + strlen('UE427_NATIVE_NAME|'))));
        if (count($parts) !== 4) throw new RuntimeException('Malformed native name row');
        [$fileIndex, $nameIndex, $offset, $hex] = $parts;
        if (isset($names[(int)$fileIndex][(int)$nameIndex])) throw new RuntimeException('Duplicate native name entry');
        $names[(int)$fileIndex][(int)$nameIndex] = [(int)$offset, strtoupper($hex)];
    } elseif (($p = strpos($line, 'UE427_NATIVE_NAME_ERROR|')) !== false) {
        $nativeErrors[] = trim(substr($line, $p));
    }
}
$matches = 0; $mismatches = []; $wideNames = 0; $nameCount = 0;
foreach ($paths as $fileIndex => $path) {
    $expectedCount = $counts[$fileIndex] ?? null;
    $reader = new UnrealPackageReader4($path);
    $rows = $reader->getNames();
    $header = $reader->getHeader();
    if ($reader->validatePackage() !== []) {
        $mismatches[] = ['file' => $fileIndex, 'error' => 'UnrealDB parser issues', 'details' => $reader->validatePackage()];
    }
    if ($expectedCount === null || $expectedCount !== count($rows) || (int)($header['nameCount'] ?? -1) !== $expectedCount) {
        $mismatches[] = ['file' => $fileIndex, 'error' => 'name count mismatch', 'native' => $expectedCount, 'unrealdb' => count($rows)];
        continue;
    }
    $nativeRows = $names[$fileIndex] ?? [];
    if (count($nativeRows) !== $expectedCount) {
        $mismatches[] = ['file' => $fileIndex, 'error' => 'native rows missing', 'native_rows' => count($nativeRows), 'expected' => $expectedCount];
        continue;
    }
    foreach ($rows as $index => $row) {
        $entry = $nativeRows[$index] ?? null;
        $plain = (string)($row['name'] ?? '');
        $actual = strtoupper(bin2hex($plain));
        if (!is_array($entry) || $entry[0] !== (int)($row['offset'] ?? -1) || $entry[1] !== $actual) {
            if (count($mismatches) < 30) {
                $mismatches[] = ['file' => $fileIndex, 'entry' => $index, 'error' => 'native name bytes or entry offset mismatch',
                    'epic' => $entry, 'unrealdb' => [(int)($row['offset'] ?? -1), $actual]];
            }
        } else {
            $matches++;
        }
    }
    $nameCount += $expectedCount;
}
$ok = count($counts) === count($paths) && count($nativeErrors) === 0 && $mismatches === [] && $matches === $nameCount;
echo json_encode(['ok' => $ok, 'files' => count($paths), 'native_name_counts' => count($counts),
    'name_entries_compared' => $nameCount, 'matching_entries' => $matches,
    'native_errors' => $nativeErrors, 'mismatch_count' => count($mismatches), 'mismatches' => $mismatches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($ok ? 0 : 2);
