#!/usr/bin/env php
<?php
/**
 * Contract verifier for catalog-wide v3 package superset analysis.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$source = file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoPackageSupersetAnalyzer.php'
);
$coverage = file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php'
);

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$check(
    'consumer_discovery_uses_compact_projection',
    is_string($source)
        && str_contains($source, 'ue_dependency_links')
        && str_contains($source, 'required_package_term_id'),
    'Catalog-wide analysis should use the compact dependency projection to locate relevant consumer Imports.'
);
$check(
    'consumer_set_is_v3_only',
    is_string($source)
        && str_contains($source, 'm.format_version=3')
        && !str_contains($source, 'format_version=2'),
    'Only verified v3 consumer metadata participates in superset analysis.'
);
$check(
    'requirements_come_from_v3_imports',
    is_string($source)
        && str_contains($source, "'imports'")
        && str_contains($source, "'relative_object_path'"),
    'The exact required object hierarchy must come from each consumer Import in .uedb3.'
);
$check(
    'import_reads_are_bounded',
    is_string($source)
        && str_contains($source, 'contiguousRanges')
        && str_contains($source, "->page("),
    'Relevant Import indexes are grouped into bounded contiguous v3 block reads instead of whole-file scans.'
);
$check(
    'union_deduplicates_full_relative_paths',
    is_string($source)
        && str_contains($source, '$requirements[$key] ??= $relativePath')
        && !str_contains($source, 'basename('),
    'The catalog union deduplicates complete package-relative paths without collapsing Outer/group hierarchy.'
);
$check(
    'union_reuses_provider_coverage',
    is_string($source)
        && str_contains($source, 'PdoPackageObjectCoverageResolver::evaluate'),
    'Every provider is evaluated against the same catalog-wide requirement union.'
);
$check(
    'analysis_is_not_persisted_to_metadata',
    is_string($source)
        && !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $source),
    'Superset analysis remains derived state and must not duplicate mutable catalog-global data into .uedb3.'
);
$check(
    'coverage_reports_exact_missing_objects',
    is_string($coverage)
        && str_contains($coverage, "'missing_paths'")
        && str_contains($coverage, "'matched_exports'"),
    'Provider results retain exact missing paths and verified Export indexes.'
);
$check(
    'no_largest_package_heuristic',
    is_string($source)
        && !preg_match('/serial_size|file_size|size_bytes/i', $source),
    'Catalog-wide superset status is object coverage, not a largest-file heuristic.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(
    ['ok' => $ok, 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
exit($ok ? 0 : 3);
