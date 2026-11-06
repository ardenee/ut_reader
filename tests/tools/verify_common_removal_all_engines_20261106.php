#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * FRESH cross-engine common-outcome removal acceptance.
 * Preserves historical UEDB5 evidence while testing current source resolution
 * and live read-model interpretation of legacy common counts as unresolved.
 * All data access read-only. Originals are SHA-pinned byte-identical copies.
 */
$root=dirname(__DIR__,2);
require_once $root.'/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactDependencyReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyEncoding;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameFileListQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogListQuery;

$fixtureRoot=(string)(getenv('ALL_GAMES_COMMON_FIXTURE_ROOT')?:'');
if($fixtureRoot==='')throw new RuntimeException('ALL_GAMES_COMMON_FIXTURE_ROOT must identify isolated original-copies fixture root.');
$manifestJson=(string)file_get_contents($fixtureRoot.'/manifest.json');
$manifest=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$manifestJson),true,512,JSON_THROW_ON_ERROR);
if(!is_array($manifest)||count($manifest)!==5)throw new RuntimeException('Missing original fixture manifest.');
$reader=new Uedb5MetadataReader($fixtureRoot);
$cases=[];
foreach($manifest as $item){
    $id=(int)$item['file_id'];$game=(int)$item['game_id'];
    $file=$fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/','\\',(string)$item['relative_path']);
    if(!is_file($file)||hash_file('sha256',$file)!==(string)$item['sha256'])throw new RuntimeException('Original fixture SHA mismatch: '.$id);
    $snapshot=$reader->snapshot($game,$id);
    $imports=count((array)($snapshot['sections']['imports']??[]));
    if($imports<1)throw new RuntimeException('No original import rows: '.$game.'/'.$id);
    $previous=array_values(array_filter((array)($snapshot['sections']['dependency_results']??[]),
        static fn($r):bool=>($r['outcome']??'')==='common'));
    $out=Uedb5ClassicDependencyResolver::resolve(
        $snapshot,[],['common_packages'=>['Core','Engine','Editor','Fire','IpDrv','UWindow','Botpack','UnrealShare','UnrealI','Gameplay','UnrealEd']]
    );
    if(count($out)!==$imports)throw new RuntimeException('Import count drift: '.$game.'/'.$id.' got '.count($out).' expected '.$imports);
    $statuses=array_count_values(array_map(static fn(array $row):string=>(string)($row['status']??''),$out));
    if(($statuses['common']??0)!==0||($statuses['resolved']??0)!==0||($statuses['package_only']??0)!==0)
        throw new RuntimeException('Unselected original provider was promoted: '.$game.'/'.$id.' '.json_encode($statuses));
    $cases[]=['game_id'=>$game,'file_id'=>$id,'fixture_sha256'=>(string)$item['sha256'],
      'import_rows'=>$imports,'original_persisted_common'=>count($previous),
      'new_unselected_resolver_outcomes'=>$statuses];
}

if(CatalogCompactDependencyReadService::statusLabel(3)!=='unresolved')
    throw new RuntimeException('Historic common enum still displayed as common.');
if(CompactDependencyEncoding::codes('common')!==[4,4,0])
    throw new RuntimeException('A new writer could still emit legacy outcome 3.');
if(method_exists(UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver::class,'isCommonImport'))
    throw new RuntimeException('The old common-name bypass was not removed.');

$config=catalog_config();$config['db']['host']='192.168.0.55';
$db=catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try{
    $query=new PdoGameFileListQuery($db);
    $rows=[];
    foreach([[3,113253],[6,112300],[7,113526],[12,110902]] as [$game,$fileId]){
        $stmt=$db->prepare('SELECT COALESCE(SUM(common_count),0) legacy_common,COALESCE(SUM(unresolved_count),0) source_unresolved FROM ue_uedb5_dependency_packages WHERE file_id=? AND game_id=?');
        $stmt->execute([$fileId,$game]);$old=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        $page=$query->fetchNumberedPage('WHERE f.game_id=? AND f.id=?',[$game,$fileId],'package','asc',10,1);
        $record=$page['rows'][0]??null;
        if(!is_array($record))throw new RuntimeException('No live game-file row: '.$game.'/'.$fileId);
        $expected=(int)($old['legacy_common']??0)+(int)($old['source_unresolved']??0);
        if((int)$record['unresolved_count']!==$expected)
            throw new RuntimeException('Legacy common not included in unresolved: '.$game.'/'.$fileId
                .' expected '.$expected.' got '.$record['unresolved_count']);
        if($expected<=0)throw new RuntimeException('Fixture does not demonstrate legacy common outcome: '.$game.'/'.$fileId);
        $rows[]=['game_id'=>$game,'file_id'=>$fileId,'historical_common'=>(int)$old['legacy_common'],'new_unresolved_display'=>(int)$record['unresolved_count']];
    }
    $list=(new PdoGameCatalogListQuery($db))->all();
    $gameIds=[3=>true,6=>true,7=>true,12=>true];
    $totals=[];
    foreach($list as $g) {
        $id=(int)$g['id'];if(!isset($gameIds[$id]))continue;
        if($g['unresolved_dependency_count']===null)
            throw new RuntimeException('Original game stats not current: '.$id);
        $totals[$id]=[
          'game'=>(string)$g['name'],
          'dependency_count'=>(int)$g['dependency_count'],
          'unresolved_display'=>(int)$g['unresolved_dependency_count'],
          'resolved_source_count'=>(int)$g['resolved_dependency_count']
        ];
        if($totals[$id]['unresolved_display']<1)
            throw new RuntimeException('Missing legacy-common unresolved total for game '.$id);
    }
    if(count($totals)!==4)throw new RuntimeException('Missing game-level counts.');
    $db->rollBack();
    echo json_encode(['ok'=>true,'mode'=>'READ_ONLY','scope'=>'UE1 Unreal & UT99, UE3 UT3, UE4 UT4; UE2 original fixture separately',
        'original_fixture_cases'=>$cases,'real_prd_read_model_cases'=>$rows,'game_totals'=>$totals,
        'legacy_outcome3_maps_to_unresolved'=>true,'new_common_writer_disabled'=>true,'no_common_bypass_in_classic_resolver'=>true],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    exit(0);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
