<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/catalog/compliance/UE583-SOURCE-DENOMINATOR.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Invalid UE583 source denominator JSON.\n");
    exit(2);
}

$sourceRoot = getenv((string) ($manifest['source_root_env'] ?? 'UNREALDB_EPIC_SOURCE_ROOT'));
$sourceRoot = is_string($sourceRoot) ? rtrim(str_replace('\\', '/', $sourceRoot), '/') : '';
$mode = in_array('--certification', $argv, true)
    ? 'certification'
    : (in_array('--inventory', $argv, true) ? 'inventory' : 'integrity');

$issues = [];
$sourceChecks = 0;
$symbolChecks = 0;
$implementationChecks = 0;
$evidenceChecks = 0;
$ids = [];
$inventoryCounts = [];
$verificationCounts = [];
$units = is_array($manifest['units'] ?? null) ? $manifest['units'] : [];

$check = static function (bool $ok, string $message) use (&$issues): void {
    if (!$ok) {
        $issues[] = $message;
    }
};

$check(($manifest['schema'] ?? null) === 1, 'schema must be 1');
$check(($manifest['engine'] ?? null) === 'UE5.8.3', 'engine must be UE5.8.3');
$check(($manifest['denominator_locked'] ?? null) === true, 'denominator_locked must be true');
$check(($manifest['denominator_units'] ?? null) === count($units), 'denominator_units does not match units[] count');
$check(count($units) > 0, 'denominator has no units');
$check($sourceRoot !== '' && is_dir($sourceRoot), 'pinned Epic source root is unavailable');

foreach ((array) ($manifest['production_entrypoints'] ?? []) as $path) {
    $implementationChecks++;
    $check(is_string($path) && is_file($root . '/' . $path), 'missing production entrypoint: ' . (string) $path);
}

foreach ($units as $index => $unit) {
    if (!is_array($unit)) {
        $issues[] = "unit[$index] is not an object";
        continue;
    }

    $id = (string) ($unit['id'] ?? '');
    $check($id !== '', "unit[$index] missing id");
    $check(!isset($ids[$id]), "duplicate unit id: $id");
    $ids[$id] = true;

    $inventoryStatus = (string) ($unit['inventory_status'] ?? '');
    $verificationStatus = (string) ($unit['verification_status'] ?? '');
    $check(in_array($inventoryStatus, ['accounted', 'partial', 'open', 'excluded'], true), "$id invalid inventory_status: $inventoryStatus");
    $check(in_array($verificationStatus, ['verified', 'partial', 'open', 'excluded'], true), "$id invalid verification_status: $verificationStatus");
    $inventoryCounts[$inventoryStatus] = ($inventoryCounts[$inventoryStatus] ?? 0) + 1;
    $verificationCounts[$verificationStatus] = ($verificationCounts[$verificationStatus] ?? 0) + 1;

    $sources = is_array($unit['epic_sources'] ?? null) ? $unit['epic_sources'] : [];
    $check($sources !== [], "$id has no Epic sources");
    $combinedSource = '';
    foreach ($sources as $source) {
        $path = (string) ($source['path'] ?? '');
        $expected = strtolower((string) ($source['sha256'] ?? ''));
        $relative = preg_replace('#^Engine/Source/#', '', str_replace('\\', '/', $path));
        $full = $sourceRoot . '/Engine/Source/' . $relative;
        $sourceChecks++;
        $check($path !== '' && is_file($full), "$id missing Epic source: $path");
        if (is_file($full)) {
            $actual = strtolower((string) hash_file('sha256', $full));
            $check($expected !== '' && hash_equals($expected, $actual), "$id Epic source hash drift: $path");
            $combinedSource .= "\n" . (string) file_get_contents($full);
        }
    }

    foreach ((array) ($unit['symbols'] ?? []) as $symbol) {
        $symbolChecks++;
        $symbol = (string) $symbol;
        $found = $symbol !== '' && str_contains($combinedSource, $symbol);
        if (!$found && preg_match('/::([A-Za-z_][A-Za-z0-9_]*)/', $symbol, $m) === 1) {
            $found = str_contains($combinedSource, $m[1] . '(');
        }
        if (!$found && str_starts_with($symbol, 'operator<<')) {
            $found = str_contains($combinedSource, 'operator<<');
        }
        $check($found, "$id source symbol not found: $symbol");
    }

    foreach ((array) ($unit['unrealdb_files'] ?? []) as $path) {
        $implementationChecks++;
        $check(is_string($path) && is_file($root . '/' . $path), "$id missing UnrealDB file: " . (string) $path);
    }

    foreach ((array) ($unit['evidence'] ?? []) as $path) {
        $evidenceChecks++;
        $check(is_string($path) && is_file($root . '/' . $path), "$id missing evidence file: " . (string) $path);
    }

    if ($inventoryStatus === 'excluded' || $verificationStatus === 'excluded') {
        $check(!empty($unit['exclusion_reason']), "$id exclusion requires exclusion_reason");
    }

    if ($mode === 'inventory') {
        $check(in_array($inventoryStatus, ['accounted', 'excluded'], true), "$id inventory remains $inventoryStatus");
    } elseif ($mode === 'certification') {
        $check(in_array($inventoryStatus, ['accounted', 'excluded'], true), "$id inventory remains $inventoryStatus");
        $check(in_array($verificationStatus, ['verified', 'excluded'], true), "$id verification remains $verificationStatus");
    }
}

ksort($inventoryCounts);
ksort($verificationCounts);
$report = [
    'ok' => $issues === [],
    'mode' => $mode,
    'engine' => $manifest['engine'] ?? null,
    'denominator_locked' => $manifest['denominator_locked'] ?? false,
    'units' => count($units),
    'inventory_status' => $inventoryCounts,
    'verification_status' => $verificationCounts,
    'source_hash_checks' => $sourceChecks,
    'source_symbol_checks' => $symbolChecks,
    'implementation_file_checks' => $implementationChecks,
    'evidence_file_checks' => $evidenceChecks,
    'issues' => $issues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($issues === [] ? 0 : 1);
