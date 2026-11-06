<?php
/**
 * Read-only UI acceptance: real package version/licensee sort, original
 * unmutated Exports columns, and metadata metrics embedded in two tables.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameFileListQuery;
function ensure(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException($label);
}
$root=dirname(__DIR__, 2);
$gameUi=(string)file_get_contents($root.'/catalog/game-files.php');
$examUi=(string)file_get_contents($root.'/catalog/file-examine-paged-core.php');
$script=(string)file_get_contents($root.'/catalog/assets/catalog-ui.js');
$zenExports=(string)file_get_contents($root.'/catalog/file-examine-zen-exports.php');
ensure(str_contains($gameUi,"game_files_sort_link('Version', 'version'")
 && str_contains($gameUi,"game_files_sort_link('Lic', 'licensee'")
 && str_contains($gameUi,'game-files-version-type { display: flex; justify-content: center')
 && !str_contains($gameUi,"game_files_sort_link('unresolved', 'deps'"),
 'Game files version sorts, type centring or dependency header not corrected.');
ensure(str_contains($examUi,'data-catalog-identity-skip="1"')
 && str_contains($examUi,'<th>Package ref</th><th>Class</th><th>Object</th>')
 && str_contains($zenExports,'data-catalog-identity-skip="1"')
 && str_contains($script,"querySelectorAll('.examine-imports-table')")
 && !str_contains($script,"querySelectorAll('.examine-imports-table, .examine-exports-table')"),
 'Browser code still alters Exports columns from original HTML.');
$start=strpos($examUi,"'<div class=\"two-col\"><table>'");
$met=strpos($examUi,'<th>Dependencies met</th>');
$firstTableEnd=strpos($examUi,"echo '</table><table>'",$start);
$missing=strpos($examUi,'<th>Dependencies missing</th>');
$allEnd=strpos($examUi,"echo '</table></div><p class=\"muted small\">",$missing);
ensure($start!==false && $met!==false && $firstTableEnd!==false
 && $missing!==false && $allEnd!==false
 && $start<$met && $met<$firstTableEnd
 && $firstTableEnd<$missing && $missing<$allEnd
 && !str_contains($examUi,'$coverageColumns'),
 'Dependency figures are not rendered within the two original metadata tables.');
$config=catalog_config();
$config['db']['host']=(string)(getenv('UE_GAME_LIST_TEST_DB_HOST')?:'192.168.0.55');
$db=catalog_db($config);
$db->exec('SET SESSION MAX_EXECUTION_TIME=45000');
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try{
    $service=new PdoGameFileListQuery($db);
    $results=[];
    foreach([
        ['version','asc','package_version'],
        ['licensee','asc','licensee_version'],
        ['licensee','desc','licensee_version'],
    ] as [$sort,$direction,$column]){
        $page=$service->fetchNumberedPage('WHERE f.game_id=?',[2],$sort,$direction,15,1);
        $returned=array_map(static fn(array $row):int=>(int)$row['id'],$page['rows']);
        $sql='SELECT f.id FROM ue_files f WHERE f.game_id=? '
            .'ORDER BY f.'.$column.' '.strtoupper($direction)
            .',f.package_name ASC,f.original_name ASC,f.id ASC LIMIT 15';
        $q=$db->prepare($sql);$q->execute([2]);
        $expected=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        ensure($returned===$expected,
            'Original Unreal II '.$sort.' '.$direction.' SQL ordering mismatch');
        ensure(count($returned)===15,'Unexpected original Unreal II row count');
        $first=$service->fetchCursorPage('WHERE f.game_id=?',[2],$sort,$direction,10,null,'first');
        $next=$service->fetchCursorPage('WHERE f.game_id=?',[2],$sort,$direction,
            10,$first['last_cursor'],'next');
        $cursorIds=array_map(static fn(array $row):int=>(int)$row['id'],$next['rows']);
        $otherPage=$service->fetchNumberedPage('WHERE f.game_id=?',[2],$sort,$direction,10,2);
        $numberedIds=array_map(static fn(array $row):int=>(int)$row['id'],$otherPage['rows']);
        ensure($cursorIds===$numberedIds,
            'Keyset cursor disagrees with direct numbered page for '.$sort.' '.$direction);
        $values=array_map(static fn(array $row):int=>(int)$row[$column],$page['rows']);
        $results[]=['sort'=>$sort,'direction'=>$direction,
            'first_value'=>$values[0],'last_value'=>end($values),
            'first_page_count'=>count($returned),'cursor_matches_second_page'=>true,
            'example_file_id'=>$returned[0]];
    }
    $db->rollBack();
    echo json_encode([
        'pass'=>true,'read_only'=>true,'game_id'=>2,
        'version_licensee_order_and_cursor_cases'=>$results,
        'export_table_original_column_structure_preserved'=>true,
        'browser_no_longer_removes_export_columns'=>true,
        'metadata_counts_use_existing_two_tables'=>true,
        'new_database_records'=>0,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
