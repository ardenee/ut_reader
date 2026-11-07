<?php
/**
 * Scoped, read-only acceptance for game-file and file-examine display changes.
 * No engine-specific import status is promoted to native VerifyImport success.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector as Inspector;
use UnrealDb\Catalog\Infrastructure\Persistence\CatalogStoredPackageFileResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeMetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection as Zen;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameIndexedPackageCoverageQuery as Coverage;

function ensure(bool $pass, string $message): void {
    if (!$pass) throw new RuntimeException($message);
}
$repository=dirname(__DIR__,2);
$fixtureRoot=(string)(getenv('EXAMINE_ORIGINAL_PACKAGE_FIXTURES')
    ?: 'D:/Temp/UnrealDB/file-examine-catalogue-fixtures/storage');
$metadataRoot=(string)(getenv('EXAMINE_ORIGINAL_UEDB5_FIXTURES')
    ?: 'D:/Temp/UnrealDB/all-engines-no-common-fixtures');
$originals=[
    ['relative_path'=>'storage/games/ut99/verified/2aa9a951613309c83ffbe0f90a08aea7.utx',
        'extension'=>'utx','sha256'=>'50edfe60fcf99c61f369cf8100079351bd75eb0a23febc77aa891142086dbbc0','bytes'=>7980],
    ['relative_path'=>'storage/games/unreal2/verified/05a4c4138b95620f110abb60d8e6e3fd.un2',
        'extension'=>'un2','sha256'=>'9e944f8c30da071b7d3b29e7154e4882036651d227d45a7c384c7160a7f8695f','bytes'=>1587941],
];
$inspections=[];
foreach($originals as $item) {
    $resolved=Resolver::resolve(['storage_path'=>$fixtureRoot],$item);
    ensure($resolved!==null && filesize($resolved)===$item['bytes'],
        'Original package file path not resolved');
    ensure(hash_file('sha256',$resolved)===$item['sha256'],
        'Original package source SHA mismatch');
    $header=Inspector::inspect($resolved,$item);
    ensure($header['ok'] && count($header['rows'])>=10,'Original header read rejected');
    $inspections[]=['source_file'=>$item['relative_path'],
        'sha256'=>$item['sha256'],'raw_fields'=>count($header['rows'])];
}
foreach(['../config.php','storage/../../etc/passwd','C:/Windows/win.ini','storage/games/missing.u'] as $unsafe) {
    ensure(Resolver::resolve(['storage_path'=>$fixtureRoot],
        ['relative_path'=>$unsafe])===null,'Unsafe storage path accepted');
}
$games=(string)file_get_contents($repository.'/catalog/games.php');
$listing=(string)file_get_contents($repository.'/catalog/game-files.php');
$ui=(string)file_get_contents($repository.'/catalog/file-examine-paged-core.php');
$outer=(string)file_get_contents($repository.'/catalog/file-examine.php');
$zenImports=(string)file_get_contents($repository.'/catalog/file-examine-zen-tables.php');
$zenExports=(string)file_get_contents($repository.'/catalog/file-examine-zen-exports.php');
ensure(str_contains($games,'>Files</th>')
    && str_contains($games,'>Native Script</th>')
    && str_contains($games,"'dep_filter' => 'missing'")
    && str_contains($games,'not in source') && str_contains($games,'in source'),
    'Games table labels or native script tooltip missing');
ensure(!str_contains($games,'>Files Missing Dependencies</th>')
    && !str_contains($games,"'dep_filter' => 'unindexed'")
    && !str_contains($listing,"'unindexed' => 'Missing dependencies'"),
    'Obsolete public missing-dependency filter or header');
$sourceTable=(string)file_get_contents($repository.'/catalog/file-examine-source-rows.php');
ensure(str_contains($ui,"examine_classic_full_source_table(")
    && str_contains($sourceTable,'examine_source_columns(')
    && str_contains($sourceTable,"'outer_index'")
    && str_contains($sourceTable,"'full_path'")
    && str_contains($sourceTable,"'root_package'")
    && str_contains($sourceTable,'examine-import-missing')
    && !str_contains($ui,'examine-export-unreferenced')
    && str_contains($zenImports,'examine-import-missing')
    && !str_contains($zenExports,'examine-export-unreferenced'),
    'Version-adaptive complete source fields, import highlighting or clean exports regressed');
ensure(str_contains($ui,'<div id="examine-header-slot"></div>')
    && str_contains($outer,"'<div id=\"examine-header-slot\"></div>'")
    && str_contains($ui,'<details class="examine-scanner-notes">')
    && !str_contains($ui,'<details class="examine-scanner-notes" open')
    && str_contains($ui,'Dependencies met')
    && str_contains($ui,'Dependencies missing'),
    'Metadata/header/scanner placement or dependency count missing');

$config=catalog_config();
$config['db']['host']=(string)(getenv('EXAMINE_DB_HOST')?:'192.168.0.55');
$db=catalog_db($config);
$db->exec('SET SESSION MAX_EXECUTION_TIME=25000');
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $reader=new Reader($db,$metadataRoot);
    $tests=[
      ['imports','-1',1],
      ['imports','Engine',2],
      ['exports','504',1],
      ['exports','00070000',3],
    ];
    $search=[];
    foreach($tests as [$section,$needle,$expected]) {
        $r=$reader->searchPage(113253,$section,$needle,0,100);
        ensure((int)$r['total']===$expected,'Original '. $section .' field search drift: '. $needle);
        $search[]=['table'=>$section,'needle'=>$needle,'matches'=>$r['total']];
    }
    $zen=new Zen($db);
    foreach([['exports','712438030E5C1A50'],['imports','49159F3AC1005A8F']] as [$section,$needle]) {
        $r=$zen->searchPage(1390232,$section,$needle,0,100);
        ensure((int)$r['total']===1,'Zen search value not found');
        $search[]=['table'=>'zen_'.$section,'needle'=>$needle,'matches'=>$r['total']];
    }
    $filter=Coverage::fileFilterPredicate('missing',false);
    $query=$db->prepare('SELECT COUNT(*) FROM ue_files f '
        .'WHERE f.game_id=? AND f.scan_status="verified" AND '.$filter);
    $query->execute([2]);
    ensure((int)$query->fetchColumn()===2,'Unreal II missing dependency file filter drift');
    $db->rollBack();
    echo json_encode([
        'pass'=>true,'read_only'=>true,
        'original_package_headers'=>$inspections,
        'blocked_unsafe_paths'=>4,
        'field_search_matches'=>$search,
        'unreal2_missing_file_filter_count'=>2,
        'ui_columns_header_collapse_and_source_semantics_checked'=>true,
        'native_runtime_verifyimport_inferred'=>false,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
