<?php
declare(strict_types=1);

function missing_detail_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogMissingDetailListService.php');
$page = file_get_contents(__DIR__ . '/../missing.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202607270010_missing_object_pagination.php');
foreach ([$service, $page, $migration] as $source) {
    missing_detail_expect(is_string($source), 'Missing-detail pagination source could not be read.');
}

foreach (['fetchPackageObjects', 'fetchFileObjects', 'fetchPackageFiles'] as $method) {
    missing_detail_expect(str_contains($service, 'function ' . $method . '('), 'Missing-detail service is missing ' . $method . '.');
    missing_detail_expect(str_contains($page, 'CatalogMissingDetailListService::' . $method . '('), 'Missing Files does not use ' . $method . '.');
}
missing_detail_expect(!str_contains(strtoupper($service), ' OFFSET '), 'Missing-detail service returned to OFFSET pagination.');
missing_detail_expect(str_contains($service, 'CatalogKeysetPaginator::comparison('), 'Missing-detail service does not use cursor comparisons.');
missing_detail_expect(str_contains($service, 'CatalogKeysetPaginator::order('), 'Missing-detail service does not reverse keyset ordering.');
missing_detail_expect(str_contains($service, "'d.id'"), 'Object drill-down tuples do not end in dependency ID.');
missing_detail_expect(str_contains($service, "'f.id'"), 'Requiring-file drill-down tuple does not end in file ID.');
missing_detail_expect(str_contains($service, "LIMIT ' . (\$limit + 1)"), 'Missing-detail service does not read one bounded look-ahead row.');
missing_detail_expect(str_contains($service, "'prev' => \$rows !== []"), 'Previous navigation does not preserve a return path to the later page.');

foreach ([
    "'page' => 'missing-detail'",
    "'mode' => \$detailMode",
    "'package' => \$selectedPackage",
    "'file_id' => \$selectedFileId",
    "'detail_cursor'",
    "'detail_move'",
    "'detail_page'",
    'missing_detail_pagination(',
] as $fragment) {
    missing_detail_expect(str_contains($page, $fragment), 'Missing Files detail cursor is missing: ' . $fragment);
}
missing_detail_expect(str_contains($page, '$detailPerPage = 200;'), 'Missing-object pages do not use the bounded 200-row size.');
missing_detail_expect(str_contains($page, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND file_id=?'), 'File-object exact total is missing.');
missing_detail_expect(str_contains($page, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND required_package=?'), 'Package-object exact total is missing.');
missing_detail_expect(!str_contains($page, "\$fileDetailRows = catalog_all("), 'File object drill-down still loads every row directly.');
missing_detail_expect(!str_contains($page, "\$detailRows = catalog_all("), 'Package drill-down still loads every row directly.');

foreach (['idx_ue_deps_missing_package_cursor', 'idx_ue_deps_missing_file_cursor'] as $index) {
    missing_detail_expect(str_contains($migration, $index), 'Missing-object cursor migration lacks ' . $index . '.');
}
missing_detail_expect(str_contains($migration, '(required_package,status,file_id,id)'), 'Package-object cursor index has the wrong shape.');
missing_detail_expect(str_contains($migration, '(file_id,status,required_package,id)'), 'File-object cursor index has the wrong shape.');

fwrite(STDOUT, "Missing detail pagination contract tests passed.\n");
