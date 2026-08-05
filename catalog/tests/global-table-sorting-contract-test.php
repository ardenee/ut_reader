<?php
declare(strict_types=1);

function global_table_sort_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$support = file_get_contents(__DIR__ . '/../lib/CatalogSupport.php');
global_table_sort_expect(is_string($support), 'Could not read CatalogSupport.php.');
global_table_sort_expect(
    str_contains($support, 'CatalogTableSortAssets::register();'),
    'CatalogSupport does not register the global table sorting asset.'
);

$hook = file_get_contents(__DIR__ . '/../src/Presentation/Http/CatalogTableSortAssets.php');
global_table_sort_expect(is_string($hook), 'Could not read CatalogTableSortAssets.php.');
foreach ([
    'catalog-table-sort.js',
    "../assets/catalog-table-sort.js",
    "assets/catalog-table-sort.js",
    'ob_start(static function',
    "str_contains(\$output, '</head>')",
] as $fragment) {
    global_table_sort_expect(
        str_contains($hook, $fragment),
        'Global table sorting asset hook is missing: ' . $fragment
    );
}

$script = file_get_contents(__DIR__ . '/../assets/catalog-table-sort.js');
global_table_sort_expect(is_string($script), 'Could not read catalog-table-sort.js.');
foreach ([
    "root.querySelectorAll('table').forEach(bind)",
    'function headerRowFor(table)',
    "cell.tagName === 'TH'",
    "cell.tagName === 'TD'",
    "table.dataset.tableSort === 'false'",
    "table.dataset.catalogSortBound === '1'",
    "table.dataset.packageRefMoved !== '1'",
    "cell.getAttribute('data-sort-value')",
    'function byteValue(value)',
    'function numericValue(value)',
    'function durationValue(value)',
    'MutationObserver',
    "header.setAttribute('aria-sort'",
] as $fragment) {
    global_table_sort_expect(
        str_contains($script, $fragment),
        'Global table sorter is missing: ' . $fragment
    );
}

$gameManager = file_get_contents(__DIR__ . '/../game-manager.php');
global_table_sort_expect(is_string($gameManager), 'Could not read game-manager.php.');
global_table_sort_expect(
    str_contains($gameManager, '<table><tr><th>Game</th>'),
    'Game Manager games table no longer has a recognizable legacy header row.'
);
global_table_sort_expect(
    str_contains($gameManager, '<table><tr><th>Name</th><th>Type</th>'),
    'Game Manager sources table no longer has a recognizable legacy header row.'
);

echo "Global table sorting contract tests passed.\n";
