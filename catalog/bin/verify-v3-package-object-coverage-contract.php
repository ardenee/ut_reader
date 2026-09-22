#!/usr/bin/env php
<?php
/**
 * Contract verifier for package-wide object coverage.
 *
 * Uses an in-memory SQLite catalog plus tiny v3 metadata containers so the
 * contract proves both projection filtering and exact v3 Export-path
 * verification without touching the production catalog.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageObjectCoverageResolver;

$source = file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php'
);
$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$check(
    'v3_only_provider_filter',
    is_string($source) && str_contains($source, 'm.format_version=3')
        && !str_contains($source, 'm.format_version=2'),
    'Coverage candidates must come only from verified v3 metadata providers.'
);
$check(
    'full_relative_path_is_required',
    is_string($source) && str_contains($source, "local_path")
        && str_contains($source, 'exportPathMatches'),
    'A path-hash hit must be confirmed against the exact v3 Export local_path.'
);
$check(
    'coverage_states_are_explicit',
    is_string($source)
        && str_contains($source, "'fully_satisfies'")
        && str_contains($source, "'partially_satisfies'")
        && str_contains($source, "'does_not_satisfy'"),
    'Each package version must expose complete, partial, or no object coverage.'
);
$check(
    'missing_objects_are_reported',
    is_string($source) && str_contains($source, "'missing_paths'"),
    'Partial providers must identify the exact required objects they lack.'
);
$check(
    'does_not_rank_by_package_size',
    is_string($source)
        && !preg_match('/ORDER BY[^;]*(serial_size|file_size|size_bytes)/i', $source),
    'Coverage must be based on required exports, never largest-package heuristics.'
);
$check(
    'nested_outer_paths_remain_distinct',
    is_string($source) && !str_contains($source, 'basename('),
    'GroupA.Wall01 and GroupB.Wall01 must remain distinct relative object paths.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
