#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';
require_once $root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5PhysicalProviderSelector;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$proofDir=$root.'/compliance/evidence/o06/';
$proof=json_decode((string)file_get_contents($proofDir.'20261031-G3-T073-T075-T076-20-real-UT99-shipped-native-and-5-indexed-ownership.json'),true,512,JSON_THROW_ON_ERROR);
$indexed=json_decode((string)file_get_contents($proofDir.'20261031-G3-T073-T075-T076-source-PRD-five-indexed-candidate-rows.json'),true,512,JSON_THROW_ON_ERROR);
$checkCount=0;$bad=[];
$check=static function(bool $v,string $name)use(&$checkCount,&$bad):void{$checkCount++;if(!$v)$bad[]=$name;};
$check($proof['actual_original_indexed_shipped_candidates']===5,'five original verified physically different real candidates');
$check($indexed['read_only']===true && ($indexed['package_key_hex']??null)==='951DA6B7179A4F697CC89D36ACF74E52','original PRD read only provider key evidence');
$files=[];
$realIndexed=[];
foreach($indexed['bounded_actual_indexed_candidate_rows'] as $r){
    $realIndexed[(int)$r['file_id']]=$r;
}
foreach($proof['original_UE1_shipped_file_metadata'] as $f){
    $id=(int)$f['file_id'];$file=(string)$f['scratch_path'];
    $bytes=file_get_contents($file);
    $check(is_string($bytes),'real original shipped package source exists '.$id);
    if(!is_string($bytes))continue;
    $check(hash('sha256',$bytes)===$f['original_PRD_sha256']
        && hash('sha1',$bytes)===$f['sha1']&&md5($bytes)===$f['md5']
        && strlen($bytes)===$f['bytes'],'original physical archive three cryptographic identities '.$id);
    $reader=new CatalogUE1PackageReader($file);
    $header=$reader->getHeader();
    $check((int)$header['version']===(int)$f['version']
        && count($reader->getExports())===(int)$header['exportCount']
        && (int)$realIndexed[$id]['package_version']===(int)$f['version'],
        'real source selected archive header and exported objects '.$id);
    $files[$id]=$f;
}
$check(count($files)===5,'five distinct original physical catalog candidate IDs');
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-classic-native-original-'.bin2hex(random_bytes(5));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$consumerId=900001; $gameId=3;
$name=static fn(string $v):array=>['text'=>$v];
$sourceSnapshot=[
 'file'=>['id'=>$consumerId,'game_id'=>$gameId,'package_name'=>'G3RealShippedProviderFixture','original_name'=>'G3RealShippedProviderFixture.u'],
 'package_family'=>'classic-linkerload','source_policy'=>'ue1-ut99-retail-v1400-1999-11-30',
 'section_schemas'=>[
  'summary'=>'ue1.ut99.package-summary.v1','names'=>'ue1.ut99.name-entry.v1',
  'imports'=>'ue1.ut99.object-import.v1','exports'=>'ue1.ut99.object-export.v1',
 ],
 'sections'=>[
  'summary'=>[['package_version'=>68]],'names'=>[],
  'imports'=>[
   ['index'=>0,'class_package'=>$name('Core'),'class_name'=>$name('Package'),
    'object_name'=>$name('Detail'),'outer_index'=>0],
   ['index'=>1,'class_package'=>$name('Core'),'class_name'=>$name('Class'),
    'object_name'=>$name('DetailAsset'),'outer_index'=>-1],
  ],'exports'=>[],
 ],
];
$writer->write($sourceSnapshot);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,uploaded_at TEXT,file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec('CREATE TABLE ue_invalid_file_identities(file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT)');
$db->exec('CREATE TABLE ue_uedb5_provider_keys(source_kind INTEGER,source_id INTEGER,game_id INTEGER,package_key_kind INTEGER,package_key BLOB,file_id INTEGER)');
$add=$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)');
$named=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)');
$provider=$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)');
$add->execute([$consumerId,$gameId,'verified','2026-11-01 00:00:00',1,md5('consumer'),sha1('consumer')]);
$named->execute([$consumerId,$gameId,'G3RealShippedProviderFixture']);
$keyKind=Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME;
$key=Uedb5SqlProjectionContract::classicPackageKeyBinary('Detail',$keyKind);
$check(strtoupper(bin2hex($key))===$indexed['package_key_hex'],'genuine original FName provider key byte identity');
foreach($files as $id=>$f){
    $add->execute([$id,$gameId,'verified','2026-10-01 00:00:00',(int)$f['bytes'],$f['md5'],$f['sha1']]);
    $named->execute([$id,$gameId,'Detail']);
    $provider->execute([1,$id,$gameId,$keyKind,$key,$id]);
}
$selector=new PdoUedb5PhysicalProviderSelector($db,$tmp);
$baseline=$selector->select($gameId,$consumerId);
$check(count($baseline)===1 && ($baseline[0]['selection_status']??'')==='ambiguous'
    && count($baseline[0]['candidate_file_ids']??[])===5,
    'without authentic runtime mount evidence original same-name physical files never guessed');
$context=static function(array $mountOrder)use($gameId,$consumerId):array{
    return ['classic_runtime_context'=>[
        'mount_order_authoritative'=>true,'game_id'=>$gameId,
        'consumer_file_id'=>$consumerId,
        'mounted_file_ids_search_order'=>$mountOrder,
    ]];
};
$native=0;$profiles=[];
$versionFileIds=[68=>1256343,69=>1276716];
foreach($proof['original_native_cases'] as $case){
    $native++;
    $profile=(string)$case['profile'];$profiles[$profile]=($profiles[$profile]??0)+1;
    $entry=$case['native_outcome'];
    if(isset($entry['native_loaded_linker_summary'])){
        $system=$entry['source_system_id'];
        $textures=$entry['source_textures_id'];
        $expected=$entry['source_selected_id_under_original_source_SearchPaths'];
    }elseif(array_key_exists('original_stock_469f_Core_SHA',$entry)){
        $system=$entry['system_shipped_version']!==null?$versionFileIds[(int)$entry['system_shipped_version']]:null;
        $textures=$entry['textures_shipped_version']!==null?$versionFileIds[(int)$entry['textures_shipped_version']]:null;
        $expected=$system??$textures;
        $check($expected===null ? $entry['actual_469f_native_selected_version']===0
          : (int)$entry['actual_469f_native_selected_version']===(int)$files[$expected]['version'],
          'compiled 469f original exact v68/v69 texture object markers '.$native);
    }else{
        $system=$entry['shipped_system_catalog_provider_version']!==null?$versionFileIds[(int)$entry['shipped_system_catalog_provider_version']]:null;
        $textures=$entry['shipped_textures_catalog_provider_version']!==null?$versionFileIds[(int)$entry['shipped_textures_catalog_provider_version']]:null;
        $expected=$system??$textures;
        $check($expected===null ? $entry['native_reported_version']===0
          : (int)$entry['native_reported_version']===(int)$files[$expected]['version'],
          'compiled v432 actual original texture pointer identity '.$native);
    }
    $order=array_values(array_filter([$system,$textures],static fn($id)=>$id!==null));
    $selected=$selector->select($gameId,$consumerId,$context($order));
    $check(count($selected)===1 && ($selected[0]['selection_status']??'')===($expected===null?'missing':'selected')
      && ($selected[0]['file_id']??null)===$expected,
      'original native engine real physical mount precedence equals current UEDB5 '.$profile.' case '.$native);
    if(isset($entry['native_loaded_linker_summary'])){
        $f=$files[$expected];$summary=$entry['native_loaded_linker_summary'];
        $check($summary['file_bytes']===$f['bytes']&&$summary['version']===$f['version'],
          'native original true loader file identity matches selected source header '.$native);
    }
    $pin=$case['native_log'];$p=dirname($root).'/'.$pin['path'];
    $check(hash_file('sha256',$p)===$pin['sha256']&&filesize($p)===$pin['bytes'],
      'retained original compiled x86 native output SHA '.$native);
}
$check($native===20&&($profiles['v432']??null)===10&&($profiles['469f']??null)===10,
  'twenty previously executed original compiled x86 cases across two distinct UT99 profiles');
$negative=[
  ['wrong_game',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>4,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>[1256343]]]],
  ['wrong_consumer',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>12,'mounted_file_ids_search_order'=>[1256343]]]],
  ['duplicate',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>[1256343,1256343]]]],
  ['invalid_zero',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>[0]]]],
  ['invalid_string',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>['1256343']]]],
  ['not_a_list',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>[9=>1256343]]]],
  ['missing_order',['classic_runtime_context'=>['mount_order_authoritative'=>true,'game_id'=>3,'consumer_file_id'=>$consumerId]]],
  ['non_array',['classic_runtime_context'=>'untrusted']],
];
foreach($negative as [$label,$opts]){
    $threw=false;try{$selector->select($gameId,$consumerId,$opts);}catch(RuntimeException){$threw=true;}
    $check($threw,'reject non-source-backed or mismatched original runtime mount context '.$label);
}
$unproven=$selector->select($gameId,$consumerId,[
    'classic_runtime_context'=>['mount_order_authoritative'=>false,
      'game_id'=>$gameId,'consumer_file_id'=>$consumerId,'mounted_file_ids_search_order'=>[1276716]],
]);
$check(($unproven[0]['selection_status']??null)==='ambiguous',
    'unauthoritative runtime mount hint must never silently determine physical original package');
$unknown=$selector->select($gameId,$consumerId,$context([9999999]));
$check(($unknown[0]['selection_status']??null)==='missing'
    && array_key_exists('file_id',$unknown[0]) && $unknown[0]['file_id']===null,
    'even an authoritative runtime mount cannot select nonindexed physical provider');
$trailing=$selector->select($gameId,$consumerId,$context([9999999,1256343]));
$check(($trailing[0]['file_id']??null)===1256343,
    'unused earlier unrelated mount key never overrides matched source provider');
$extra=json_decode((string)file_get_contents($proofDir.'20261101-G3-T076-six-real-original-C227i-C227k-duplicate-mount-and-missing.json'),true,512,JSON_THROW_ON_ERROR);
$check(($extra['native_original_new_executions']??null)===6,'six separately executed native 2012 and 2026 C227 source cases');
foreach($extra['new_native_cases'] as $case){
    $which=(string)$case['case'];
    $mounted=array_values(array_filter([
        $case['original_source_mount_system_id'],
        $case['original_source_mount_textures_id'],
    ],static fn($id)=>$id!==null));
    $expected=$case['original_source_mount_system_id']??$case['original_source_mount_textures_id'];
    $answer=$selector->select($gameId,$consumerId,$context($mounted));
    $check(count($answer)===1 && $answer[0]['selection_status']===($expected===null?'missing':'selected')
        && $answer[0]['file_id']===$expected,
        'real 2012/2026 original compiled engine duplicate or missing provider agrees with UEDB5 '.$case['profile'].' '.$which);
    $expectedExports=$which==='system_v68_textures_v69'?4:($which==='system_v69_textures_v68'?11:0);
    $check($case['native_output_BMP_count']===$expectedExports
        && $case['real_source_native_executable_exit']===0,
        'C227 authentic original UCC loaded actual source package texture identity, or returned zero on source load failure '.$case['profile'].' '.$which);
    $p=dirname($root).'/'.$case['committed_original_stdout_proof']['path'];
    $check(is_file($p) && hash_file('sha256',$p)===$case['native_stdout_SHA256']
        && filesize($p)===$case['committed_original_stdout_proof']['bytes'],
        'C227 authentic compiled stock original complete stdout log SHA '.$case['profile'].' '.$which);
    $actual=(string)file_get_contents($p);
    $check($expected===null ? str_contains($actual,'Failed loading package:')
        : ($expectedExports===4 ? str_contains($actual,'Detail.paint1')
            && !str_contains($actual,'Detail.Rough')
            : str_contains($actual,'Detail.Rough') && !str_contains($actual,'Detail.paint1')),
        'original C227 source-selected physical file texture object markers and accurate missing diagnostics '.$case['profile'].' '.$which);
}
$check(count($extra['source_SHA_pins']??[])===6,'all six distinct original 2012 and 2026 compiler outputs independently archived');
$result=[
 'ok'=>$bad===[],'checks'=>$checkCount,'errors'=>$bad,
 'actual_original_compiled_native_shipped_cases_correlated'=>$native,
 'previous_native_executions_replayed'=>0,'native_profiles'=>$profiles,
 'source_real_same_name_indexed_candidates'=>count($files),
 'runtime_provider_context'=>'authoritative scoped original physical mount order only',
 'source_ambiguity_without_mounts_preserved'=>true,
 'original_engine_assets_modified'=>false,
 'full_T076_source_family_gate_status'=>'BLOCKED: C98 and C227i/k original native imported-provider oracle and production mount provenance not fully accepted',
];
file_put_contents($proofDir.'20261101-G3-T076-native20-actual-classic-runtime-mount-selector-acceptance.json',
 json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
exit($bad===[]?0:1);
