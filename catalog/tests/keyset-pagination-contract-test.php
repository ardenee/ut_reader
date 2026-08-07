<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies keyset pagination behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

require_once __DIR__ . '/../bootstrap/autoload.php';

function keyset_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'db' => ['database' => 'catalog_test', 'username' => 'tester', 'password' => 'secret'],
    'storage_path' => '/tmp/catalog-test',
];
$context = json_encode(['page' => 'game-files', 'game_id' => 7, 'sort' => 'size'], JSON_THROW_ON_ERROR);
$values = [4096, 'Engine', 'Engine.u', 42];
$token = CatalogKeysetPaginator::encode($config, $context, $values);

keyset_expect(CatalogKeysetPaginator::decode($config, $context, $token) === $values, 'Cursor did not round-trip its sort tuple.');
keyset_expect(CatalogKeysetPaginator::decode($config, $context . '-changed', $token) === null, 'Cursor was not bound to its filter/sort context.');
$tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');
keyset_expect(CatalogKeysetPaginator::decode($config, $context, $tampered) === null, 'Tampered cursor signature was accepted.');

$comparison = CatalogKeysetPaginator::comparison(
    ['a', 'b', 'c'],
    ['ASC', 'DESC', 'ASC'],
    [10, 'z', 5],
    true
);
keyset_expect(
    $comparison['sql'] === '((a>?) OR (a=? AND b<?) OR (a=? AND b=? AND c>?))',
    'Forward lexicographic comparison SQL changed unexpectedly.'
);
keyset_expect($comparison['args'] === [10, 10, 'z', 10, 'z', 5], 'Forward cursor arguments are not aligned with the comparison branches.');
keyset_expect(
    CatalogKeysetPaginator::order(['a', 'b', 'c'], ['ASC', 'DESC', 'ASC'], true) === 'a DESC, b ASC, c DESC',
    'Reverse-page ordering does not invert every tuple direction.'
);

$root = dirname(__DIR__);
$gameService = file_get_contents($root . '/src/Application/Catalog/CatalogGameFileListService.php');
$gamePage = file_get_contents($root . '/game-files.php');
$missingService = file_get_contents($root . '/src/Application/Dependency/CatalogMissingFileListService.php');
$missingPage = file_get_contents($root . '/missing.php');
$migration = file_get_contents($root . '/migrations/202607270006_keyset_pagination_indexes.php');

foreach ([$gameService, $gamePage, $missingService, $missingPage, $migration] as $source) {
    keyset_expect(is_string($source) && $source !== '', 'A keyset pagination source file could not be read.');
}
keyset_expect(!str_contains((string)$gameService, ' OFFSET '), 'Game file service still contains OFFSET pagination.');
keyset_expect(!str_contains((string)$missingService, ' OFFSET '), 'Missing file service still contains OFFSET pagination.');
keyset_expect(str_contains((string)$gameService, "'f.id'"), 'Game file sort tuples do not include the stable file ID tie-breaker.');
keyset_expect(str_contains((string)$gameService, 'ue_dependency_package_summaries'), 'Dependency sorting does not use the compact summary table.');
keyset_expect(str_contains((string)$gamePage, 'CatalogKeysetPaginator::decode'), 'Game file page does not validate cursor tokens.');
keyset_expect(str_contains((string)$missingPage, 'CatalogMissingFileListService::fetchCursorPage'), 'Missing Files page does not use cursor pagination.');
foreach ([
    'idx_ue_files_game_package_cursor',
    'idx_ue_files_game_original_cursor',
    'idx_ue_files_game_version_cursor',
    'idx_ue_files_game_size_cursor',
    'idx_ue_files_game_compression_cursor',
    'idx_ue_files_game_uploaded_cursor',
] as $index) {
    keyset_expect(str_contains((string)$migration, $index), 'Keyset migration is missing index: ' . $index);
}

fwrite(STDOUT, "Keyset pagination contract tests passed.\n");
