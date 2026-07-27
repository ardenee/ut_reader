<?php
declare(strict_types=1);

function search_document_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607270003_search_documents.php');
search_document_expect(is_string($migration), 'Search-document migration could not be read.');
foreach ([
    'CREATE TABLE ue_search_documents',
    'uq_ue_search_document_source',
    'idx_ue_search_game_primary',
    'idx_ue_search_game_secondary',
    'FULLTEXT KEY ft_ue_search_values',
    'SELECT f.game_id,i.file_id,"import"',
    'SELECT f.game_id,e.file_id,"export"',
] as $fragment) {
    search_document_expect(str_contains($migration, $fragment), 'Search-document migration is missing ' . $fragment);
}
search_document_expect(
    strpos($migration, 'INSERT INTO ue_search_documents') < strpos($migration, 'ADD FULLTEXT KEY'),
    'The large search-document backfill should complete before the FULLTEXT index is built.'
);
search_document_expect(
    !str_contains($migration, 'CONCAT(a.package_name,".",e.local_path)'),
    'The materialized table must not multiply aliases by every export row.'
);

$service = file_get_contents(__DIR__ . '/../src/Application/Search/CatalogSearchService.php');
search_document_expect(is_string($service), 'CatalogSearchService could not be read.');
foreach ([
    'FROM ue_search_documents d',
    'MATCH(d.primary_value,d.secondary_value) AGAINST',
    'search_documents_contains',
    'alias_export_path_targeted',
    'searchDocumentsAvailable',
] as $fragment) {
    search_document_expect(str_contains($service, $fragment), 'Search service is missing ' . $fragment);
}
search_document_expect(
    !str_contains($service, 'STRAIGHT_JOIN i.file_id')
        && !str_contains($service, 'STRAIGHT_JOIN e.file_id'),
    'Normal indexed search returned to raw parser-table scans.'
);

$indexer = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoSearchDocumentIndexer.php');
search_document_expect(is_string($indexer), 'PdoSearchDocumentIndexer could not be read.');
foreach (['DELETE FROM ue_search_documents WHERE file_id=?', '"alias"', '"import"', '"export"'] as $fragment) {
    search_document_expect(str_contains($indexer, $fragment), 'Per-file search indexer is missing ' . $fragment);
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$affected = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
$aliases = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoPackageAliasRepository.php');
search_document_expect(is_string($types) && str_contains($types, 'REBUILD_FILE_SEARCH_INDEX'), 'Search-index job type is missing.');
search_document_expect(is_string($factory) && str_contains($factory, 'CatalogSearchIndexJobHandler'), 'Search-index job handler is not registered.');
search_document_expect(is_string($affected) && str_contains($affected, 'CatalogSearchIndexQueue::enqueueFile'), 'Imports do not enqueue search indexing.');
search_document_expect(is_string($aliases) && str_contains($aliases, 'CatalogSearchIndexQueue::enqueueFile'), 'Alias inserts do not enqueue search indexing.');

fwrite(STDOUT, "Search document index contract tests passed.\n");
