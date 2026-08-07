<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies file examine pagination behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function examine_paging_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$wrapper = file_get_contents(__DIR__ . '/../file-examine.php');
examine_paging_expect(is_string($wrapper), 'File examiner wrapper could not be read.');
examine_paging_expect(
    str_contains($wrapper, "require __DIR__ . '/file-examine-paged-core.php';")
        && !str_contains($wrapper, "require __DIR__ . '/file-examine-core.php';"),
    'File examiner is not routed through the bounded page renderer.'
);
examine_paging_expect(
    str_contains($wrapper, 'CatalogPackageHeaderInspector::inspect(')
        && str_contains($wrapper, 'Raw package header'),
    'Paged examiner no longer preserves bounded raw package-header inspection.'
);

$core = file_get_contents(__DIR__ . '/../file-examine-paged-core.php');
examine_paging_expect(is_string($core), 'Paged examiner core could not be read.');
examine_paging_expect(
    str_contains($core, 'CatalogPackageTablePageService::fetchPage(')
        && str_contains($core, 'Rows per page')
        && str_contains($core, 'file-examine-export.php?'),
    'Paged examiner does not use bounded pages and streaming export links.'
);
foreach ([
    'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index',
    'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index',
    'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index',
] as $legacyQuery) {
    examine_paging_expect(!str_contains($core, $legacyQuery), 'Unbounded package table query remains: ' . $legacyQuery);
}

$service = file_get_contents(__DIR__ . '/../src/Application/Catalog/CatalogPackageTablePageService.php');
examine_paging_expect(is_string($service), 'Package table page service could not be read.');
examine_paging_expect(
    str_contains($service, " . ' WHERE file_id=? AND '")
        && str_contains($service, " . '>=? AND '")
        && str_contains($service, " . '<?'")
        && str_contains($service, 'normalizePageSize(')
        && str_contains($service, '[100, 250, 500, 1000]'),
    'Package table service does not use bounded index ranges and controlled page sizes.'
);
examine_paging_expect(
    str_contains($service, 'pageForIndex(')
        && str_contains($service, 'dependencyMap(')
        && str_contains($service, 'nameUsage('),
    'Target routing or page-local reference support is missing.'
);

$export = file_get_contents(__DIR__ . '/../file-examine-export.php');
examine_paging_expect(is_string($export), 'Package table export endpoint could not be read.');
examine_paging_expect(
    str_contains($export, '$batchSize = 1000;')
        && str_contains($export, " . ' WHERE file_id=? AND ' . \$indexColumn . '>?'")
        && str_contains($export, 'Content-Disposition: attachment;')
        && str_contains($export, "fputcsv(\$output, \$columns, ',', '\"', '');"),
    'Full table exports are not streamed in bounded index batches.'
);

fwrite(STDOUT, "File examine pagination contract tests passed.\n");
