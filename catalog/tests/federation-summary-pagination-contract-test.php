<?php
declare(strict_types=1);

function federation_summary_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../src/Application/Federation/CatalogFederationInventoryListService.php');
federation_summary_expect(is_string($service), 'Federation inventory service could not be read.');
federation_summary_expect(
    str_contains($service, 'FROM ue_dependency_package_summaries s')
        && str_contains($service, 'CatalogKeysetPaginator::comparison(')
        && str_contains($service, 'CatalogKeysetPaginator::order('),
    'Federation inventory lists are not summary-backed cursor queries.'
);
federation_summary_expect(
    !str_contains($service, ' OFFSET ')
        && str_contains($service, 'needed_by_parent_files')
        && str_contains($service, 'SUM(s.missing_count) object_count')
        && str_contains($service, 'COUNT(*) use_count'),
    'Federation inventory service still uses deep offsets or detailed dependency counts.'
);
federation_summary_expect(
    str_contains($service, 'example_required_object_path')
        && str_contains($service, 'hasExamplePathColumn('),
    'Federation request rows do not preserve an example dependency path or pre-migration compatibility.'
);

$page = file_get_contents(__DIR__ . '/../federation/inventories.php');
federation_summary_expect(is_string($page), 'Federation inventories page could not be read.');
federation_summary_expect(
    str_contains($page, 'CatalogFederationInventoryListService::parentCursorPage(')
        && str_contains($page, 'CatalogFederationInventoryListService::childCursorPage(')
        && str_contains($page, 'CatalogKeysetPaginator::decode(')
        && str_contains($page, 'CatalogKeysetPaginator::encode('),
    'Federation inventory controller is not using signed cursor pages.'
);
federation_summary_expect(
    !str_contains($page, 'fi_parent_need_count_sql(')
        && !str_contains($page, 'fi_child_missing_rows(')
        && !str_contains($page, ' OFFSET '),
    'Legacy detailed dependency or offset pagination remains in the federation inventory controller.'
);
federation_summary_expect(
    str_contains($page, 'The selected packages are no longer eligible.')
        && str_contains($page, '$byKey[fi_child_key($row)] = $row;'),
    'Child request submission is not revalidated against the signed current cursor page.'
);

$writer = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoDependencyPackageSummary.php');
federation_summary_expect(is_string($writer), 'Dependency summary writer could not be read.');
federation_summary_expect(
    str_contains($writer, 'MIN(NULLIF(d.required_object_path,"")) example_required_object_path')
        || str_contains($writer, 'MIN(NULLIF(d.required_object_path,"")) example_required_object_path,'),
    'Dependency summary writer does not maintain the representative object path.'
);
federation_summary_expect(
    str_contains($writer, 'hasExamplePathColumn()'),
    'Dependency summary writer does not preserve pre-migration compatibility.'
);

$migration = file_get_contents(__DIR__ . '/../migrations/202607270007_federation_summary_pagination.php');
federation_summary_expect(is_string($migration), 'Federation summary cursor migration could not be read.');
foreach ([
    'example_required_object_path',
    'idx_ue_dep_summary_game_package_missing',
    'idx_ue_peer_files_inventory_cursor',
] as $needle) {
    federation_summary_expect(str_contains($migration, $needle), 'Federation summary cursor migration is missing: ' . $needle);
}

fwrite(STDOUT, "Federation summary pagination contract tests passed.\n");
