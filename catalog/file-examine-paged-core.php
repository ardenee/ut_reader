<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the current paginated names/imports/exports examination view used by `file-examine.php`.
 * Why: It avoids loading very large package tables into one request and supersedes the older examination core.
 * Role: Active package-inspection UI core backed by `PdoPackageTablePageQuery`.
 * Audit: Current implementation; keep shared pagination logic here rather than restoring the older core.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/file-examine-zen-tables.php';
require_once __DIR__ . '/file-examine-zen-exports.php';
require_once __DIR__ . '/file-examine-source-rows.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameIndexedPackageCoverageQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameFileListQuery;

catalog_start_session();

/**
 * Compact local Zen reference renderer matching the legacy examiner cells.
 * File-identity enrichment must never operate on these package references.
 *
 * @param array<string,mixed> $ref
 * @param array<string,string> $cookedLabels
 * @param array<string,int> $importIndices
 */
function examine_zen_table_reference(
    array $ref,
    array $cookedLabels,
    array $importIndices,
    int $fileId,
    int $pageSize
): string {
    $kind = (string)($ref['type'] ?? '');
    $raw = strtoupper((string)($ref['raw_type_and_id'] ?? ''));
    if ($kind === 'Null' || $kind === '') return '<span class="muted">—</span>';
    if ($kind === 'Export' && isset($ref['local_export_index'])) {
        $index = (int)$ref['local_export_index'];
        return '<a class="xref mono" href="' . catalog_h(examine_href($fileId, 'export-'.$index, $pageSize))
            . '">Export #' . $index . '</a>';
    }
    if ($kind === 'ScriptImport') {
        $label = $cookedLabels[$raw] ?? ('0x' . $raw);
        $html = '<span class="mono path">' . catalog_h($label) . '</span>';
        if (isset($importIndices[$raw])) {
            $html = '<a class="xref mono path" href="'
                . catalog_h(examine_href($fileId, 'import-'.$importIndices[$raw], $pageSize))
                . '">' . catalog_h($label) . '</a>';
        }
        return $html;
    }
    if ($kind === 'PackageImport') {
        $packageId = (string)($ref['provider_package_id'] ?? '');
        $hash = (string)($ref['provider_public_export_hash'] ?? '');
        return '<span class="mono">FPackageId ' . catalog_h($packageId) . '</span>'
            . '<span class="examine-zen-secondary">Export hash ' . catalog_h($hash) . '</span>';
    }
    return '<span class="mono">0x' . catalog_h($raw) . '</span>';
}

function examine_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max(0, (int)$value);
}

function examine_target(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?? '';
}

function examine_target_table(string $target): string
{
    if (str_starts_with($target, 'import-')) {
        return 'imports';
    }
    if (str_starts_with($target, 'export-')) {
        return 'exports';
    }
    return 'names';
}

function examine_href(int $fileId, string $target, int $pageSize): string
{
    $target = examine_target($target);
    $table = examine_target_table($target);
    $index = PdoPackageTablePageQuery::targetIndex($target, $table) ?? 0;
    $page = PdoPackageTablePageQuery::pageForIndex($index, $pageSize);
    return 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'tab' => $table,
        'page' => $page,
        'page_size' => $pageSize,
        'target' => $target,
    ]) . '#' . rawurlencode($target);
}

function examine_tab_href(int $fileId, string $table, int $pageSize): string
{
    $table = strtolower(trim($table));
    if (!in_array($table, ['names','imports','exports','uses','used-by'], true)) {
        $table = 'names';
    }
    return 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'tab' => $table,
        'page_size' => $pageSize,
    ]) . '#package-tables';
}

function examine_page_href(int $fileId, string $table, int $page, int $pageSize): string
{
    return 'file-examine.php?' . http_build_query(array_filter([
        'id' => $fileId,
        'tab' => $table,
        'page' => max(1, $page),
        'page_size' => $pageSize,
        'filter' => trim((string)($_GET['filter'] ?? '')),
        'name_ref' => trim((string)($_GET['name_ref'] ?? '')),
    ],static fn($value):bool=>$value !== '')) . '#package-tables';
}

function examine_back(int $gameId): string
{
    $candidate = trim((string)($_GET['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    $parts = $candidate !== '' ? parse_url($candidate) : false;
    if (is_array($parts) && basename((string)($parts['path'] ?? '')) === 'game-files.php') {
        parse_str((string)($parts['query'] ?? ''), $parameters);
        if ((int)($parameters['id'] ?? 0) === $gameId) {
            return 'game-files.php?' . http_build_query(array_intersect_key(
                $parameters,
                array_flip(['id','file_filter','dep_filter','type_filter','compression_filter','sort','dir','cursor','cursor_move','cursor_page'])
            ));
        }
    }
    return 'game-files.php?id=' . $gameId;
}

function examine_reference(int $reference, int $fileId, int $pageSize): string
{
    if ($reference === 0) {
        return '<span class="muted">none</span>';
    }
    $target = $reference < 0 ? 'import-' . ((-$reference) - 1) : 'export-' . ($reference - 1);
    return '<a class="xref mono" href="' . catalog_h(examine_href($fileId, $target, $pageSize)) . '">' . $reference . '</a>';
}

function examine_link_name(string $value, array $nameLookup, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return '<span class="muted">none</span>';
    }
    $key = mb_strtolower($value, 'UTF-8');
    if (isset($nameLookup[$key])) {
        $target = 'name-' . (int)$nameLookup[$key];
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, $target, $pageSize)) . '" title="Open name table entry">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_name_index_link(string $value, ?int $nameIndex, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return '<span class="muted">none</span>';
    }
    if ($nameIndex !== null && $nameIndex >= 0) {
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, 'name-' . $nameIndex, $pageSize))
            . '" title="Open exact Name table entry">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_object_label(string $value, int $reference, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return examine_reference($reference, $fileId, $pageSize);
    }
    if ($reference !== 0) {
        $target = $reference < 0 ? 'import-' . ((-$reference) - 1) : 'export-' . ($reference - 1);
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, $target, $pageSize))
            . '" title="Open referenced Import/Export row">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_stored_usage(array $row, int $fileId, int $pageSize): string
{
    return examine_usage([
        'imports_count' => (int)($row['imports_count'] ?? 0),
        'imports_target' => isset($row['first_import_index']) ? 'import-' . (int)$row['first_import_index'] : '',
        'exports_count' => (int)($row['exports_count'] ?? 0),
        'exports_target' => isset($row['first_export_index']) ? 'export-' . (int)$row['first_export_index'] : '',
    ], $fileId, $pageSize);
}

function examine_name_flags(mixed $value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted">n/a</span>';
    }
    $integer = (int)$value;
    if ($integer > 65535) {
        return sprintf('0x%04X / 0x%04X', ($integer >> 16) & 0xFFFF, $integer & 0xFFFF);
    }
    return (string)$integer;
}

function examine_usage(array $usage, int $fileId, int $pageSize): string
{
    $parts = [];
    if (($usage['imports_count'] ?? 0) > 0 && ($usage['imports_target'] ?? '') !== '') {
        $parts[] = '<a class="xref" href="' . catalog_h(examine_href($fileId, (string)$usage['imports_target'], $pageSize)) . '">Imports: ' . (int)$usage['imports_count'] . '</a>';
    }
    if (($usage['exports_count'] ?? 0) > 0 && ($usage['exports_target'] ?? '') !== '') {
        $parts[] = '<a class="xref" href="' . catalog_h(examine_href($fileId, (string)$usage['exports_target'], $pageSize)) . '">Exports: ' . (int)$usage['exports_count'] . '</a>';
    }
    return $parts !== [] ? implode(' <span class="muted">·</span> ', $parts) : '<span class="muted">none</span>';
}

function examine_pagination(int $fileId, string $table, array $page): string
{
    $pages = (int)$page['pages'];
    if ($pages <= 1) {
        return '';
    }
    $current = (int)$page['page'];
    $size = (int)$page['page_size'];
    return CatalogUi::pagination($current, $pages, [
        'label' => 'Package table pagination',
        'first' => $current > 1 ? examine_page_href($fileId, $table, 1, $size) : '',
        'previous' => $current > 1
            ? examine_page_href($fileId, $table, $current - 1, $size) : '',
        'next' => $current < $pages
            ? examine_page_href($fileId, $table, $current + 1, $size) : '',
        'last' => $current < $pages
            ? examine_page_href($fileId, $table, $pages, $size) : '',
        'page_url' => static fn(int $n): string => examine_page_href($fileId, $table, $n, $size),
        'jump' => [
            'action' => 'file-examine.php',
            'name' => 'page',
            'fields' => ['id' => $fileId, 'tab' => $table,
                'page_size' => $size, 'filter' => (string)($_GET['filter'] ?? '')],
        ],
    ]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = examine_int('id');
    $target = examine_target((string)($_GET['target'] ?? ''));
    $requestedTab = strtolower(trim((string)($_GET['tab'] ?? ($target !== '' ? examine_target_table($target) : 'names'))));
    $table = in_array($requestedTab, ['uses','used-by'], true)
        ? $requestedTab
        : PdoPackageTablePageQuery::normalizeTable($requestedTab);
    $pageSize = PdoPackageTablePageQuery::normalizePageSize(examine_int('page_size', PdoPackageTablePageQuery::DEFAULT_PAGE_SIZE));
    $requestedPage = max(1, examine_int('page', 1));
    $filter = substr(trim((string)($_GET['filter'] ?? '')),0,128);
    // An explicit cross-reference target must be opened by original index,
    // even when the user arrived from an existing filtered result set.
    if ($target !== '') $filter = '';

    $file = catalog_one(
        $db,
        'SELECT f.*,g.name game_name,g.id game_id,p.engine_key profile_engine '. 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '. 'JOIN ue_game_profiles p ON p.id=g.profile_id WHERE f.id=?',
        [$fileId]
    );
    if (!$file) {
        throw new RuntimeException('File not found.');
    }
    if ((string)$file['scan_status'] === 'unverified') {
        header('Location: unverified-file-details.php?id=' . $fileId, true, 302);
        exit;
    }

    if ($target !== '') {
        $targetTable = examine_target_table($target);
        $targetIndex = PdoPackageTablePageQuery::targetIndex($target, $targetTable);
        if ($targetIndex !== null) {
            $table = $targetTable;
            $requestedPage = PdoPackageTablePageQuery::pageForIndex($targetIndex, $pageSize);
        }
    }

    $zenNameRef=null;
    $rawNameRef=(string)($_GET['name_ref']??'');
    if($target==='' && $filter==='' && $table==='exports'
        && strtolower((string)($file['extension']??''))==='zenpkg'
        && preg_match('/^(0|[1-9][0-9]{0,8})$/D',$rawNameRef)===1){
        $zenNameRef=(int)$rawNameRef;
    }
    $fetchStarted = microtime(true);
    if ($table === 'uses' || $table === 'used-by') {
        $page = ['rows'=>[],'page'=>1,'pages'=>1,'total'=>0,'page_size'=>$pageSize,'start'=>0,'end'=>0];
    } else {
        $page = $zenNameRef !== null
            ? PdoPackageTablePageQuery::fetchZenExportsByNameIndex(
                $db,$file,$zenNameRef,$requestedPage,$pageSize
            )
            : PdoPackageTablePageQuery::fetchFilteredPage(
                $db,$file,$table,$filter,$requestedPage,$pageSize
            );
    }
    $fetchElapsedMs = (int)round((microtime(true) - $fetchStarted) * 1000);
    error_log(
        '[UnrealDB file examiner page] file_id=' . $fileId
        . ' table=' . $table
        . ' page=' . $requestedPage
        . ' page_size=' . $pageSize
        . ' rows=' . count($page['rows'])
        . ' elapsed_ms=' . $fetchElapsedMs
    );
    $rows = $page['rows'];
    $isZen = strtolower((string)($file['extension'] ?? '')) === 'zenpkg';
    $zenScriptLabels = [];
    $zenScriptDetails = [];
    if ($isZen && in_array($table, ['imports','exports'], true)) {
        $scriptIndexes = [];
        foreach ($rows as $item) {
            $references = $table === 'imports'
                ? [$item] : array_filter([
                    $item['outer_index'] ?? null,
                    $item['class_index'] ?? null,
                    $item['super_index'] ?? null,
                    $item['template_index'] ?? null,
                ], 'is_array');
            foreach ($references as $reference) {
                if (is_array($reference) && ($reference['type'] ?? '') === 'ScriptImport') {
                    $scriptIndexes[] = (string)($reference['raw_type_and_id'] ?? '');
                }
            }
        }
        if ($scriptIndexes !== []) {
            $zenScriptDetails = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenCookedScriptLabelLookup::describe(
                $db,(string)$config['storage_path'],(int)$file['game_id'],$scriptIndexes
            );
            foreach($zenScriptDetails as $raw=>$detail){
                $zenScriptLabels[$raw]=(string)$detail['path'];
            }
        }
    }
    $zenHeaderNameIndex = null;
    if ($isZen && $table === 'names') {
        $header = (new \UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection($db))
            ->header($fileId);
        if (is_array($header) && isset($header['summary']['name']['name_index'])) {
            $zenHeaderNameIndex = (int)$header['summary']['name']['name_index'];
        }
    }
    $zenNameReverse = [];
    if ($isZen && $table === 'names' && $rows !== []) {
        $zenNameReverse = (new \UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection($db))
            ->exportReferencesByName(
                $fileId,array_map(static fn(array $r):int=>(int)$r['name_index'],$rows)
            );
    }
    $zenExportPaths = [];
    $zenImportIndices = [];
    if ($isZen && $table === 'exports' && $rows !== []) {
        // Paths were materialized from the validated snapshot during import.
        // Never load the entire .uedb5 snapshot again for a browser page.
        $rawIndices = [];
        foreach ($rows as $record) {
            $idx = (int)($record['export_index'] ?? -1);
            if ($idx >= 0 && (string)($record['local_path'] ?? '') !== '') {
                $zenExportPaths[$idx] = (string)$record['local_path'];
            }
            foreach (['class_index','outer_index','super_index','template_index'] as $field) {
                $ref = (array)($record[$field] ?? []);
                $raw = strtoupper((string)($ref['raw_type_and_id'] ?? ''));
                if ((string)($ref['type'] ?? '') !== 'Null'
                    && preg_match('/^[A-F0-9]{16}$/D',$raw) === 1) {
                    $rawIndices[] = $raw;
                }
            }
        }
        if ($rawIndices !== []) {
            $zenImportIndices = (new \UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection($db))
                ->importIndexLookup($fileId, $rawIndices);
        }
    }

    $zenExternalRefMatches = [];
    if ($isZen && $table === 'exports' && $rows !== []) {
        $rawRefs=[];
        foreach($rows as $row){
            foreach(['outer_index','class_index','super_index','template_index'] as $field){
                $ref=(array)($row[$field]??[]);
                if((string)($ref['type']??'')==='PackageImport'){
                    $raw=strtoupper((string)($ref['raw_type_and_id']??''));
                    if(preg_match('/^[0-9A-F]{16}$/D',$raw))$rawRefs[$raw]=$ref;
                }
            }
        }
        if($rawRefs!==[]){
            $candidates=[];$byIdx=[];
            foreach($rawRefs as $raw=>$ref){
                $index=count($candidates);
                $candidates[]=$ref+['import_index'=>$index];
                $byIdx[$index]=$raw;
            }
            $results=(new \UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenStaticDependencyLookup($db))
                ->forImports((int)$file['game_id'],$candidates);
            foreach($byIdx as $index=>$raw){
                if(isset($results[$index]))$zenExternalRefMatches[$raw]=$results[$index];
            }
        }
    }
    // Highlight only negative catalogue evidence. Never infer that an
    // identically named import/export is a successful native loader match.
    $packageFiles = [];
    if ($table === 'imports' && !$isZen) {
        $roots = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['root_package'] ?? '')),
            $rows
        ))));
        $packageFiles = PdoPackageTablePageQuery::packageFiles($db, (int)$file['game_id'], $roots);
    }
    $dependencyCurrentRow = catalog_one(
        $db,
        'SELECT ' . \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::currentFilePredicate('f')
            . ' dependency_current FROM ue_files f WHERE f.id=?',
        [$fileId]
    );
    $dependencyCurrent = (int)($dependencyCurrentRow['dependency_current'] ?? 0) === 1;
    // Catalogue availability is not a claim of source-selected native mounts.
    $examDependencyTotals = null;
    if ($dependencyCurrent && (string)($file['scan_status'] ?? '') === 'verified') {
        if ($isZen) {
            // Reuse the same exact Zen ScriptObjects/public-export matching
            // read model used by the game-file list; don't use source-only
            // unresolved codes as the user-visible catalogue counts.
            $coveragePage = (new PdoGameFileListQuery($db))->fetchNumberedPage(
                'WHERE f.id=? AND f.game_id=?', [$fileId,(int)$file['game_id']],
                'package', 'asc', 1, 1
            );
            $coverageRow = $coveragePage['rows'][0] ?? null;
            if (is_array($coverageRow)
                && (int)($coverageRow['dependency_projection_current'] ?? 0) === 1) {
                $examDependencyTotals = [
                    'met' => (int)$coverageRow['resolved_count']
                        + (int)$coverageRow['package_only_count'],
                    'missing' => (int)$coverageRow['unresolved_count']
                        + (int)$coverageRow['missing_count'],
                    'source_matched_script' => 0,
                    'source_unmatched_script' => 0,
                ];
            }
        } else {
            $availability = (new PdoGameIndexedPackageCoverageQuery($db))->forFiles(
                (int)$file['game_id'], [$fileId],
                strtoupper((string)($file['profile_engine'] ?? $file['engine_key'] ?? '')) === 'UE4'
                    || (int)$file['game_id'] === 7
            )[$fileId] ?? null;
            if (is_array($availability)) {
                $examDependencyTotals = [
                    'met' => (int)$availability['indexed'],
                    'missing' => (int)$availability['unindexed'],
                    'source_matched_script' => (int)$availability['source_matched_script'],
                    'source_unmatched_script' => (int)$availability['source_unmatched_script'],
                ];
            }
        }
    }

    // Catalogue resolution uses only persisted identities: cooked ScriptObjects
    // and exact FPackageId + PublicExportHash candidates. No live game state.
    $zenStaticImports = $isZen && $table === 'imports' && $rows !== []
        ? (new \UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenStaticDependencyLookup($db))
            ->forImports((int)$file['game_id'], $rows)
        : [];
    $zenImportOutcomes = [];
    if ($isZen && $table === 'imports' && $dependencyCurrent && $rows !== []) {
        $indices = array_values(array_unique(array_map(
            static fn(array $item): int => (int)$item['import_index'], $rows
        )));
        // Precisely source_kind=1 (serialized ordinary import map). The
        // two load-order outcomes (source_kind=4) are not import resolutions.
        $placeholders = implode(',', array_fill(0, count($indices), '?'));
        $edges = catalog_all($db,
            'SELECT source_index,classification,outcome,resolved_file_id '
            . 'FROM ue_uedb5_dependency_edges WHERE file_id=? AND source_kind=1 '
            . 'AND source_index IN (' . $placeholders . ')',
            array_merge([$fileId], $indices)
        );
        foreach ($edges as $edge) {
            $zenImportOutcomes[(int)$edge['source_index']] = $edge;
        }
    }
    // Relationship counts belong to the tab chrome, so compute the two
    // distinct-package counts on every examiner request rather than only after
    // the relationship tab has been selected.
    $freshDependencyJoins = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::joinCurrentEdges('l');
    $relationshipCounts = catalog_one(
        $db,
        'SELECT '
        . '(SELECT COUNT(DISTINCT l.resolved_file_id) FROM ue_uedb5_dependency_edges l '
        . $freshDependencyJoins . ' WHERE l.file_id=? AND l.resolved_file_id IS NOT NULL AND l.outcome IN (1,2)) uses_count,'
        . '(SELECT COUNT(DISTINCT l.file_id) FROM ue_uedb5_dependency_edges l '
        . $freshDependencyJoins . ' WHERE l.resolved_file_id=? AND l.outcome IN (1,2)) used_by_count',
        [$fileId, $fileId]
    ) ?: ['uses_count' => 0, 'used_by_count' => 0];

    $zenSourceTotal = 0;
    $zenSourceReferences = [];
    $zenReferencePage = max(1, examine_int('ref_page', 1));
    $zenReferencePageSize = min(250, $pageSize);
    if ($isZen) {
        $referenceCount = catalog_one($db,
            'SELECT COUNT(*) count FROM ue_uedb5_dependency_packages '
            . 'WHERE file_id=? AND package_key_kind=2', [$fileId]);
        $zenSourceTotal = (int)($referenceCount['count'] ?? 0);
        if ($table === 'uses' && $zenSourceTotal > 0) {
            $lastPage = max(1, (int)ceil($zenSourceTotal / $zenReferencePageSize));
            $zenReferencePage = min($zenReferencePage, $lastPage);
            $offset = ($zenReferencePage - 1) * $zenReferencePageSize;
            $zenSourceReferences = catalog_all($db,
                'SELECT HEX(package_key) package_id,required_package_name,dependency_count,'
                . 'unresolved_count,resolved_count,missing_count,summary_outcome '
                . 'FROM ue_uedb5_dependency_packages '
                . 'WHERE file_id=? AND package_key_kind=2 ORDER BY package_key '
                . 'LIMIT ' . $zenReferencePageSize . ' OFFSET ' . $offset, [$fileId]);
        }
    }
    $zenStaticPackages = $isZen && $table === 'uses' && $zenSourceReferences !== []
        ? (new \UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenStaticDependencyLookup($db))
            ->forImportedPackages((int)$file['game_id'], $zenSourceReferences)
        : [];
    $zenUsedBy = ['status'=>'not_indexed','total'=>0,
        'page'=>1,'page_size'=>$zenReferencePageSize,'rows'=>[]];
    if ($isZen) {
        // Query exactly serialized ImportedPackageId references in other
        // verified files; never substitute engine loader mount selection.
        $zenUsedBy = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoZenPackageUsedByQuery($db))
            ->forFile((int)$file['game_id'],$fileId,
                $zenReferencePage,$zenReferencePageSize,$table==='used-by');
        $relationshipCounts['used_by_count']=(int)$zenUsedBy['total'];
    }
    $relationshipRows = [];
    if ($table === 'uses') {
        $relationshipRows = catalog_all($db,
            'SELECT l.resolved_file_id file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1,COUNT(*) reference_count '
            . 'FROM ue_uedb5_dependency_edges l JOIN ue_files f ON f.id=l.resolved_file_id '
            . $freshDependencyJoins . 'WHERE l.file_id=? AND l.resolved_file_id IS NOT NULL AND l.outcome IN (1,2) AND f.scan_status="verified" '
            . 'GROUP BY l.resolved_file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1 ORDER BY f.package_name,f.original_name', [$fileId]);
    } elseif ($table === 'used-by' && $isZen) {
        $relationshipRows = $zenUsedBy['rows'];
    } elseif ($table === 'used-by') {
        $relationshipRows = catalog_all($db,
            'SELECT l.file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1,COUNT(*) reference_count '
            . 'FROM ue_uedb5_dependency_edges l JOIN ue_files f ON f.id=l.file_id '
            . $freshDependencyJoins . 'WHERE l.resolved_file_id=? AND l.outcome IN (1,2) AND f.scan_status="verified" '
            . 'GROUP BY l.file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1 ORDER BY f.package_name,f.original_name', [$fileId]);
    }
    $metadataVersion = (int)(catalog_one($db, 'SELECT format_version FROM ue_uedb5_files WHERE file_id=?', [$fileId])['format_version'] ?? 0);
    // Keep the initial examiner request bounded to the requested metadata page.
    // Cross-reference/usage/dependency enrichment is loaded after first paint by
    // file-examine-enrichment.php so a large package cannot block the page shell.
    $nameLookup = [];
    $usage = [];
    // The imports table shows serialized Unreal entries, not resolver-state labels.

    $back = examine_back((int)$file['game_id']);
    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<style>.examine-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}.examine-tab{display:inline-flex;gap:6px;min-height:34px;padding:6px 10px;border:1px solid var(--line2);border-radius:9px;color:var(--text);background:rgba(255,255,255,.035);font-weight:650;text-decoration:none}.examine-tab.is-active{color:#07111f;background:linear-gradient(180deg,#9dc2ff,#76a9ff);border-color:#a9c9ff}.examine-table-region{overflow-x:auto;border:1px solid var(--line);border-radius:12px}.used-by-identity span{display:block;overflow-wrap:anywhere}.examine-imports-table{min-width:1420px}.examine-exports-table{min-width:1320px}.examine-zen-ref-link{display:block;margin-top:5px}.examine-zen-secondary{display:block;color:var(--muted);font-size:.83em;margin-top:4px}.examine-zen-extras{margin-top:5px;font-size:.85em}.examine-zen-extras summary{color:var(--muted);cursor:pointer}.examine-import-missing td{background:rgba(241,164,84,.085);box-shadow:inset 3px 0 0 rgba(241,164,84,.42)}.is-reference-target td{background:rgba(246,196,83,.18)!important;box-shadow:inset 4px 0 0 #f6c453}.path{white-space:normal}.table-tools{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:10px 0}.to-top{position:fixed;right:20px;bottom:20px;width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:rgba(16,24,39,.94);border:1px solid var(--line2);font-size:22px}</style>';

    echo '<div class="card hero" id="top"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Bounded database-backed Names, Imports and Exports pages. Cross-references open the page containing the selected row.</p><p><a class="button" href="' . catalog_h($back) . '">Back to files</a> <a class="button" href="file-info.php?id=' . $fileId . '">Package details</a> <a class="button" href="download-info.php?id=' . $fileId . '">Download options</a> <a class="button" href="package-coverage.php?file_id=' . $fileId . '">Send to Package Coverage</a></p></div>';

    echo '<div class="card"><h2>Catalogue metadata &amp; package identity</h2>'
        .'<p class="muted">Game assignment, member filename, file size, '
        .'scan status and indexed counts are catalogue records. For Zen, '
        .'FPackageId is read from the IoStore chunk index, not FZenPackageSummary; '
        .'.zenpkg is an internally assigned member filename. The genuinely '
        .'serialized Zen header is shown separately below.</p>'
        .'<div class="two-col"><table>';
    $headerFields = [
        'Game (catalogue)' => catalog_h((string)$file['game_name']),
        'Package path (decoded)' => '<a href="file-info.php?id='.$fileId.'">'
            .catalog_h((string)$file['package_name']).'</a>',
    ];
    $zenSourceContainer = [];
    if ($isZen) {
        $zenSourceContainer = catalog_one($db,
            'SELECT HEX(p.package_id) package_id,p.container_id,c.name toc_name '
            . 'FROM ue_iostore_packages p JOIN ue_iostore_containers c ON c.id=p.container_id '
            . 'WHERE p.file_id=? AND p.chunk_index=0 LIMIT 1', [$fileId]) ?: [];
        $sourceId=(int)($zenSourceContainer['container_id']??0);
        $sourceName=(string)($zenSourceContainer['toc_name']??'');
        $headerFields += [
            'Format (reader classification)' => 'UE5 Zen / IoStore',
            'FPackageId (IoStore index)' => catalog_h((string)($zenSourceContainer['package_id']??'unavailable')),
            'Stored member filename (UnrealDB)' => catalog_h((string)$file['original_name']),
            'Source IoStore file' => $sourceId>0
                ? '<a href="iostore-info.php?id='.$sourceId.'">'
                    .catalog_h($sourceName).'</a>'
                : '<span class="muted">Not recorded</span>',
        ];
    } else {
        $headerFields += [
            'File (catalogue)' => catalog_h((string)$file['original_name']),
            'GUID (catalogue)' => catalog_h((string)($file['package_guid']?:'—')),
            'Version (catalogue)' => catalog_h((string)($file['package_version']??'—')),
            'Licensee Version (catalogue)' => catalog_h((string)($file['licensee_version']??'—')),
        ];
    }
    foreach ($headerFields as $label=>$htmlValue){
        echo '<tr><th>'.catalog_h($label).'</th><td class="mono path">'
            .$htmlValue.'</td></tr>';
    }
    if ($examDependencyTotals !== null) {
        echo '<tr><th>Dependencies met</th><td class="mono path">'
            . number_format((int)$examDependencyTotals['met']) . '</td></tr>';
        if ((int)$examDependencyTotals['source_matched_script'] > 0
            || (int)$examDependencyTotals['source_unmatched_script'] > 0) {
            echo '<tr><th>Native script in source</th><td class="mono path">'
                . number_format((int)$examDependencyTotals['source_matched_script'])
                . '</td></tr>';
        }
    }
    echo '</table><table>';
    foreach ([
        'Names' => (int)$file['name_count'],
        'Imports' => (int)$file['import_count'],
        'Exports' => (int)$file['export_count'],
        ($isZen ? 'Reconstructed member bytes' : 'File size') => catalog_bytes((int)$file['file_size']),
        'Scan status (catalogue)' => $file['scan_status'],
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$value) . '</td></tr>';
    }
    if ($examDependencyTotals !== null) {
        echo '<tr><th>Dependencies missing</th><td class="mono path">'
            . number_format((int)$examDependencyTotals['missing']) . '</td></tr>';
        if ((int)$examDependencyTotals['source_matched_script'] > 0
            || (int)$examDependencyTotals['source_unmatched_script'] > 0) {
            echo '<tr><th>Native script not in source</th><td class="mono path">'
                . number_format((int)$examDependencyTotals['source_unmatched_script'])
                . '</td></tr>';
        }
    } else {
        echo '<tr><th>Dependency coverage</th><td class="muted">Not evaluated</td></tr>';
    }
    echo '</table></div><p class="muted small">Met means an indexed catalogue candidate, '
        . 'not proven native runtime loading. Missing means the required identity '
        . 'has no suitable indexed catalogue match; UT4 source declarations '
        . 'are counted separately.</p></div>';
    echo '<div id="examine-header-slot"></div>';

    if (!empty($file['detection_notes']) || !empty($file['scan_notes'])) {
        echo '<div class="card"><details class="examine-scanner-notes">'
            . '<summary style="cursor:pointer"><strong>Scanner notes</strong></summary>'
            . '<div style="margin-top:12px">';
        $noteSourceId = $isZen ? (int)($zenSourceContainer['container_id']??0) : 0;
        $noteLink = $noteSourceId>0
            ? '<a href="iostore-info.php?id='.$noteSourceId.'">'
                .catalog_h((string)($zenSourceContainer['toc_name']??'')).'</a>'
            : '';
        foreach(['detection_notes'=>'Detection','scan_notes'=>'Scan'] as $field=>$heading){
            $note=(string)($file[$field]??'');
            if($note==='')continue;
            $safe=catalog_h($note);
            if($noteLink!==''){
                $safe=str_replace('IoStore container #'.$noteSourceId,$noteLink,$safe);
            }
            echo '<h3>'.$heading.'</h3><pre class="mono path">'.$safe.'</pre>';
        }
        echo '</div></details></div>';
    }

    $counts = [
        'names' => (int)$file['name_count'],
        'imports' => (int)$file['import_count'],
        'exports' => (int)$file['export_count'],
        'uses' => (int)($relationshipCounts['uses_count'] ?? 0),
        'used-by' => (int)($relationshipCounts['used_by_count'] ?? 0),
    ];
    echo '<div class="card" id="package-tables"><nav class="examine-tabs">';
    foreach (['names' => 'Names','imports' => 'Imports','exports' => 'Exports','uses' => 'Uses','used-by' => 'Used By'] as $key => $label) {
        // Zen counts describe serialized references; package existence is
        // independently matched against the current indexed catalogue.
        $tabCount = $isZen && $key === 'uses'
            ? $zenSourceTotal . ' packages / ' . (int)$file['import_count'] . ' object imports'
            : ($isZen && $key === 'used-by'
                ? (int)($counts[$key] ?? 0) . ' indexed source references'
                : ($key === 'uses' && !$dependencyCurrent ? 'unverified' : ($counts[$key] ?? '')));
        echo '<a class="examine-tab' . ($table === $key ? ' is-active' : '') . '" href="' . catalog_h(examine_tab_href($fileId, $key, $pageSize)) . '">' . $label . ($tabCount !== '' ? ' <span>' . $tabCount . '</span>' : '') . '</a>';
    }
    echo '</nav>';
    if (!$dependencyCurrent) {
        echo '<p class="muted">Dependency links for this file are not verified under the current engine policy. '
            . 'Only independently current reverse references are shown; missing or resolved outcomes remain unknown '
            . 'until source-correct regeneration.</p>';
    }
    echo '<style>
#package-tables .examine-source-scroll{display:block;width:100%;max-width:100%;overflow-x:auto;overscroll-behavior-x:contain}
#package-tables .examine-source-scroll > table.examine-source-table{width:max-content!important;min-width:100%!important;max-width:none!important;table-layout:auto!important}
#package-tables .examine-source-table th{font-size:12px;white-space:nowrap;line-height:1.3}
#package-tables .examine-source-table th,#package-tables .examine-source-table td{padding:10px 11px;vertical-align:middle}
#package-tables .examine-source-table .examine-source-cell{min-width:70px;max-width:340px;overflow-wrap:anywhere}
#package-tables .examine-source-table .examine-fname{white-space:nowrap;display:inline-block}
#package-tables .examine-source-table .examine-derived-col{background:rgba(130,165,220,.055)}
.examine-zen-exports-scroll{overflow-x:auto;max-width:100%}
.examine-zen-exports{width:max-content;min-width:100%;table-layout:auto}
.examine-zen-exports th{white-space:nowrap}
.examine-source-value{white-space:normal;overflow-wrap:anywhere}
.examine-source-parts{font-size:12px;line-height:1.5}
.examine-source-part{display:block;white-space:normal}
.examine-source-part-label{font-size:11px;opacity:.7}
.examine-source-composite summary{cursor:pointer;white-space:nowrap}
.examine-source-expanded{max-width:440px;max-height:380px;overflow:auto;white-space:pre;tab-size:2}
.examine-zen-raw-word{white-space:nowrap;margin:2px 0}
</style>';
    echo '<section data-file-examine-native-panel>';

    if ($table === 'uses' || $table === 'used-by') {
        $heading = $table === 'uses' ? 'Uses' : 'Used By';
        echo '<h2>' . $heading . '</h2>';
        if ($isZen) {
            echo '<p class="muted">References are matched against already indexed catalogue files. '
                . 'Open Imports for exact object and script identities.</p>';
        } else {
            echo '<p class="muted">Published provider links, where available.</p>';
        }
        if ($isZen && $table === 'uses') {
            echo '<p class="muted"><strong>' . $zenSourceTotal
                . ' source-backed external package identities</strong> are recorded in the Zen '
                . 'ImportedPackages map, alongside ' . (int)$file['import_count']
                . ' typed object imports. Each listed package identity is checked '
                . 'against verified, indexed files using its exact FPackageId. '
                . '<a href="' . catalog_h(examine_tab_href($fileId, 'imports', $pageSize))
                . '">Inspect object imports</a>.</p>';
            if ($zenSourceReferences !== []) {
                echo '<div class="examine-table-region"><table><thead><tr><th>Source package name</th>'
                    . '<th>FPackageId</th><th>Reference occurrences</th><th>Catalogue match</th>'
                    . '</tr></thead><tbody>';
                foreach ($zenSourceReferences as $refIndex => $reference) {
                    $match = $zenStaticPackages[$refIndex] ?? [];
                    $state = (string)($match['state'] ?? '');
                    if ($state === 'package_found') {
                        $status = '<a class="xref path" href="file-examine.php?id='
                            . (int)$match['file_id'] . '">'
                            . catalog_h((string)$match['package_name']) . '</a>';
                    } elseif ($state === 'multiple_matches') {
                        $status = '<span class="muted">Found in '
                            . (int)$match['match_count'] . ' files (ambiguous provider)</span>';
                    } else {
                        $status = '<span class="muted">No indexed package match</span>';
                    }
                    $requiredName = trim((string)($reference['required_package_name'] ?? ''));
                    echo '<tr id="package-' . catalog_h((string)$reference['package_id']) . '"><td class="mono path">'
                        . ($requiredName !== '' ? catalog_h($requiredName)
                            : '<span class="muted">Name not serialized</span>')
                        . '</td><td class="mono">' . catalog_h((string)$reference['package_id'])
                        . '</td><td>' . (int)$reference['dependency_count']
                        . '</td><td>' . $status . '</td></tr>';
                }
                echo '</tbody></table></div>';
                $lastPage = (int)ceil($zenSourceTotal / $zenReferencePageSize);
                if ($lastPage > 1) {
                    echo '<p class="page-links">Imported package references: page '
                        . $zenReferencePage . ' of ' . $lastPage;
                    foreach ([-1, 1] as $step) {
                        $nextPage = $zenReferencePage + $step;
                        if ($nextPage < 1 || $nextPage > $lastPage) continue;
                        $url = 'file-examine.php?' . http_build_query([
                            'id'=>$fileId,'tab'=>'uses','page_size'=>$pageSize,
                            'ref_page'=>$nextPage,
                        ]) . '#package-tables';
                        echo ' <a class="button" href="' . catalog_h($url) . '">'
                            . ($step < 0 ? 'Previous' : 'Next') . '</a>';
                    }
                    echo '</p>';
                }
            } elseif ($zenSourceTotal === 0) {
                echo '<p class="muted">This package has no external ImportedPackageIds. '
                    . 'Script imports may still be present in its Zen import map.</p>';
            }
        }
        if ($relationshipRows === []) {
            $emptyText = $isZen && $table === 'uses'
                ? 'External package and script-object catalogue matches are shown above and in the Imports tab.'
                : ($table === 'uses' && !$dependencyCurrent
                    ? 'Current Uses results are unavailable until source-correct dependency publication. The visible count is not proof that this package has no dependencies.'
                    : ($table === 'used-by'
                        ? ($isZen
                            ? (($zenUsedBy['status']??'')==='ambiguous'
                                ? 'Multiple indexed physical providers share this FPackageId; no single file is selected.'
                                : 'No other current indexed package imports this exact FPackageId.')
                            : 'No independently current reverse dependency references are published for this package.')
                        : 'No resolved package relationships found.'));
            echo '<p class="muted">' . catalog_h($emptyText) . '</p></section></div><a class="to-top" href="#top">↑</a>';
            catalog_foot();
            return;
        }
        echo '<div class="examine-table-region"><table><thead><tr><th>File</th><th>Package</th><th>Size</th><th>Identity</th><th>References</th></tr></thead><tbody>';
        foreach ($relationshipRows as $relation) {
            $relatedId=(int)$relation['file_id'];
            echo '<tr><td class="mono path"><a href="file-examine.php?id=' . $relatedId . '">' . catalog_h((string)$relation['original_name']) . '</a></td>'
                . '<td class="mono path"><a href="file-info.php?id=' . $relatedId . '">' . catalog_h((string)$relation['package_name']) . '</a></td>'
                . '<td class="mono">' . catalog_h(catalog_bytes((int)$relation['file_size'])) . '</td>'
                . '<td class="mono small used-by-identity">'
                . '<span>GUID: ' . catalog_h(trim((string)$relation['package_guid']) ?: '—') . '</span>'
                . '<span>MD5: ' . catalog_h(trim((string)$relation['md5']) ?: '—') . '</span>'
                . '<span>SHA1: ' . catalog_h(trim((string)$relation['sha1']) ?: '—') . '</span></td>'
                . '<td>' . (int)$relation['reference_count'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($isZen && $table==='used-by') {
            $lastPage=max(1,(int)ceil((int)$zenUsedBy['total']/$zenReferencePageSize));
            if ($lastPage>1) {
                echo '<p class="page-links">Indexed source references: page '
                    .(int)$zenUsedBy['page'].' of '.$lastPage;
                foreach ([-1,1] as $step) {
                    $next=(int)$zenUsedBy['page']+$step;
                    if($next<1||$next>$lastPage)continue;
                    $url='file-examine.php?'.http_build_query([
                        'id'=>$fileId,'tab'=>'used-by','page_size'=>$pageSize,
                        'ref_page'=>$next,
                    ]).'#package-tables';
                    echo ' <a class="button" href="'.catalog_h($url).'">'
                        .($step<0?'Previous':'Next').'</a>';
                }
                echo '</p>';
            }
        }
        echo '</section></div><a class="to-top" href="#top">↑</a>';
        catalog_foot();
        return;
    }

    echo '<div class="table-tools"><form method="get" action="file-examine.php">'
       . '<input type="hidden" name="id" value="'.$fileId.'">'
       . '<input type="hidden" name="tab" value="'.catalog_h($table).'">'
       . '<input type="hidden" name="page_size" value="'.$pageSize.'">'
       . '<label>Search all '.catalog_h(ucfirst($table)).' <input type="search" name="filter" maxlength="128" value="'.catalog_h($filter).'" placeholder="Any displayed field: name, path, root, outer, flags, size..."></label> '
       . '<button class="button" type="submit">Filter</button> '
       . '<a class="button secondary" href="'.catalog_h(examine_tab_href($fileId,$table,$pageSize)).'">Clear</a></form>'
       . '<form method="get"><input type="hidden" name="id" value="'.$fileId.'"><input type="hidden" name="tab" value="'.catalog_h($table).'">'
       . '<input type="hidden" name="filter" value="'.catalog_h($filter).'">'
       . '<label>Rows per page<br><select name="page_size" onchange="this.form.submit()">';
    foreach ([100,250,500,1000] as $option) {
        echo '<option value="' . $option . '"' . ($pageSize === $option ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label></form><span class="muted">Showing ' . (int)$page['start'] . '–' . (int)$page['end'] . ' of ' . (int)$page['total'] . ($filter !== '' ? ' matching rows (entire table searched).' : '.') . '</span>';
    echo '<a class="button" href="file-examine-export.php?' . catalog_h(http_build_query(['id' => $fileId,'table' => $table,'format' => 'csv'])) . '">CSV (all)</a>';
    echo '<a class="button" href="file-examine-export.php?' . catalog_h(http_build_query(['id' => $fileId,'table' => $table,'format' => 'json'])) . '">JSON (all)</a></div>';
    echo examine_pagination($fileId, $table, $page);

    if ($table === 'exports' && $isZen && $zenNameRef !== null) {
        echo '<p class="muted">ExportMap rows whose serialized '
            . 'ObjectName.NameIndex = <a class="xref mono" href="'
            . catalog_h(examine_href($fileId,'name-'.$zenNameRef,$pageSize))
            . '">'.$zenNameRef.'</a>. '
            . 'These results preserve their original export-map row positions.</p>';
    }
    if ($table === 'names') {
        if ($isZen) {
            zen_exam_names($rows,$zenNameReverse,$zenHeaderNameIndex,$fileId,$pageSize,$target);
        } else {
            examine_classic_full_source_table(
                'names',$rows,$file,$fileId,$pageSize,$target,
                $dependencyCurrent,$packageFiles
            );
        }
    } elseif ($table === 'imports') {
        if ($isZen) {
            zen_exam_imports($rows,$zenStaticImports,$zenScriptDetails,$fileId,$pageSize,$target);
        } else {
            examine_classic_full_source_table(
                'imports',$rows,$file,$fileId,$pageSize,$target,
                $dependencyCurrent,$packageFiles
            );
        }
    } else {
        if ($isZen) {
            zen_exam_exports(
                $rows,$zenScriptLabels,$zenScriptDetails,$zenImportIndices,
                $zenExternalRefMatches,$fileId,$pageSize,$target
            );
        } else {
            examine_classic_full_source_table(
                'exports',$rows,$file,$fileId,$pageSize,$target,
                $dependencyCurrent,$packageFiles
            );
        }
    }

    echo examine_pagination($fileId, $table, $page) . '</section></div><a class="to-top" href="#top">↑</a>';
    if ($target !== '') {
        echo '<script>window.addEventListener("DOMContentLoaded",function(){var row=document.getElementById(' . json_encode($target, JSON_THROW_ON_ERROR) . ');if(row){row.scrollIntoView({block:"center"});}});</script>';
    }
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Examine error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
