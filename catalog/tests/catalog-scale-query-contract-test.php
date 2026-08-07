<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies catalog scale query behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function catalog_scale_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$index = file_get_contents(__DIR__ . '/../index.php');
catalog_scale_expect(is_string($index), 'Catalog search page could not be read.');
catalog_scale_expect(
    str_contains($index, '$adminSearch = catalog_support_is_admin();')
        && str_contains($index, 'Choose a game before searching.')
        && str_contains($index, "(!$adminSearch ? ' required' : '')"),
    'Public search is not locked to a selected game.'
);
catalog_scale_expect(
    str_contains($index, "? '<option value=\"\">All games</option>'")
        && str_contains($index, ": '<option value=\"\">Choose game</option>'"),
    'Administrator all-game search or public game selection is missing.'
);

$search = file_get_contents(__DIR__ . '/../src/Application/Search/CatalogSearchService.php');
catalog_scale_expect(is_string($search), 'Catalog search service could not be read.');
catalog_scale_expect(
    str_contains($search, 'private static function collectStage(')
        && str_contains($search, '$remaining = $limit - count($candidateMatches);'),
    'Search stages do not stop after the result limit is satisfied.'
);
catalog_scale_expect(
    str_contains($search, 'SELECT STRAIGHT_JOIN i.file_id id,i.object_name match_value FROM ue_files f')
        && str_contains($search, 'SELECT STRAIGHT_JOIN e.file_id id,e.object_name match_value FROM ue_files f'),
    'Game-scoped import/export searches are not driven from the selected game files.'
);
catalog_scale_expect(
    !str_contains($search, 'SELECT f.*,g.name game_name')
        && str_contains($search, 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count'),
    'Search result hydration still loads the full wide file row.'
);
catalog_scale_expect(
    !str_contains($search, 'catalog_package_aliases_ensure($db);'),
    'Read-only search still performs package-alias schema checks.'
);

$aliasRepository = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoPackageAliasRepository.php');
catalog_scale_expect(is_string($aliasRepository), 'Package alias repository could not be read.');
catalog_scale_expect(
    !str_contains($aliasRepository, 'CREATE TABLE IF NOT EXISTS ue_file_package_aliases')
        && str_contains($aliasRepository, 'Migration 202607180002 creates and verifies this table.'),
    'Normal package-alias access still executes runtime schema DDL.'
);

$affected = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
catalog_scale_expect(is_string($affected), 'Affected dependency service could not be read.');
catalog_scale_expect(
    str_contains($affected, 'WHERE d.required_package=? AND d.file_id<>?')
        && str_contains($affected, 'f.game_id=? AND f.scan_status="verified"'),
    'Affected dependency refresh is not restricted to verified files in the selected game.'
);

$migration = file_get_contents(__DIR__ . '/../migrations/202607270001_catalog_scale_indexes.php');
catalog_scale_expect(is_string($migration), 'Catalog scale migration could not be read.');
foreach ([
    'idx_ue_files_game_status_package',
    'idx_ue_files_game_status_original',
    'idx_ue_file_alias_game_original',
    'idx_ue_imports_root_file',
    'idx_ue_exports_file_local',
    'idx_ue_deps_required_file',
] as $indexName) {
    catalog_scale_expect(str_contains($migration, $indexName), 'Missing catalog scale migration index: ' . $indexName);
}

fwrite(STDOUT, "Catalog scale query contract tests passed.\n");
