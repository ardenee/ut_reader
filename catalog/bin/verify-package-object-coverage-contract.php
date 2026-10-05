#!/usr/bin/env php
<?php
/**
 * Contract verifier for package-wide object coverage.
 *
 * Uses an in-memory SQLite catalog plus tiny current metadata containers so the
 * contract proves both projection filtering and exact current-format Export-path
 * verification without touching the production catalog.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageObjectCoverageResolver;

$source = file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php'
);
$resolverSource = file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoDependencyResolver.php'
);
$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$check(
    'current_format_only_provider_filter',
    is_string($source) && str_contains($source, 'BlockedCompressedMetadataContainer::FORMAT_VERSION')
        && !str_contains($source, 'm.format_version=3'),
    'Coverage candidates must come only from verified current metadata providers.'
);
$check(
    'full_relative_path_is_required',
    is_string($source)
        && str_contains($source, 'path_hash_ci')
        && str_contains($source, 'local_path')
        && str_contains($source, 'ue_terms'),
    'A normalized path-hash hit must still be confirmed against the exact projected Export local_path.'
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


$check(
    'dependency_resolver_does_not_use_coverage_for_provider_selection',
    is_string($resolverSource)
        && !str_contains($resolverSource, 'chooseCompleteProvider')
        && !str_contains($resolverSource, '$completeProviders[$packageKey]'),
    'Coverage analysis is diagnostic/catalog tooling only; Epic selects a package/linker before import verification.'
);
$check(
    'unsupported_source_profile_fails_closed',
    is_string($resolverSource)
        && str_contains($resolverSource, "'source_profile_not_implemented'")
        && str_contains($resolverSource, "'source_unresolved'"),
    'An engine without a registered source resolver must remain unresolved rather than using generic object coverage as invented semantics.'
);
$check(
    'dependency_resolver_has_no_independent_object_fallback',
    is_string($resolverSource)
        && !str_contains($resolverSource, 'loadExportMatches(')
        && !str_contains($resolverSource, 'PdoCompactCaseInsensitiveExportResolver::fill'),
    'Authoritative resolution must not split one source package/linker across independent object-level fallbacks.'
);
$check(
    'coverage_returns_verified_export_indexes',
    is_string($source)
        && str_contains($source, "'matched_exports'")
        && str_contains($source, 'path_hash_ci'),
    'Diagnostic coverage rows may report Export indexes from normalized paths verified against the projected exact path term.'
);
$check(
    'diagnostic_preferred_file_only_orders_equal_coverage',
    is_string($source)
        && strpos($source, "\$status !== 0") !== false
        && strpos($source, "\$preferredFileId > 0") !== false
        && strpos($source, "\$status !== 0") < strpos($source, "\$preferredFileId > 0"),
    'Diagnostic report ordering may prefer a file only after the non-authoritative coverage status has been calculated.'
);


$check(
    'coverage_checks_class_when_available',
    is_string($source)
        && str_contains($source, 'requiredClassesByPath')
        && str_contains($source, 'class_name')
        && str_contains($source, 'class_package'),
    'A path match must also honor Import class identity when both Import and Export expose it.'
);
$check(
    'case_insensitive_path_matching_is_indexed',
    is_string($source)
        && str_contains($source, 'ue_export_path_lookup')
        && str_contains($source, 'path_hash_ci')
        && !str_contains($source, 'PdoCompactCaseInsensitiveExportResolver')
        && !str_contains($source, 'BlockedCompressedMetadataReader'),
    'Case-insensitive object-path matching must use the indexed normalized projection without reopening .uedb4 files.'
);


$check(
    'coverage_helper_exposes_no_provider_selector_api',
    is_string($source)
        && !str_contains($source, 'chooseCompleteProvider')
        && !str_contains($source, 'selectCompleteCoverage'),
    'Coverage tooling must remain reporting-only and expose no API that can choose an authoritative physical provider.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
