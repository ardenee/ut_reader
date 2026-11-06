<?php
/** UT2004-only source-equivalent v127/v128 selected-loader authorization. READ ONLY. */
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue2VerifyImportSourceProfile as Profiles;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder as Sources;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver as Verify;

$options=getopt('',['db-host::','storage-root::']);
$base = static function(int $game,int $version,int $licensee,string $policy,string $schema='ue2.ut2004.object-import.v1'):array {
    return [
        'file'=>['game_id'=>$game],
        'sections'=>['summary'=>[['package_version'=>$version,'licensee_version'=>$licensee]]],
        'source_policy'=>$policy,'section_schemas'=>['imports'=>$schema],
    ];
};
$testCases=[
    ['v127_own_v127', $base(5,127,29,Sources::POLICY_V127),true],
    ['v127_under_v128_loader', $base(5,127,29,Sources::POLICY_V128),true],
    ['v127_wrong_v129_policy', $base(5,127,29,Sources::POLICY_V129),false],
    ['v128_under_v128', $base(5,128,29,Sources::POLICY_V128),true],
    ['v128_cannot_use_v127_policy', $base(5,128,29,Sources::POLICY_V127),false],
    ['v127_wrong_licensee', $base(5,127,28,Sources::POLICY_V128),false],
    ['v127_wrong_game', $base(4,127,29,Sources::POLICY_V128),false],
    ['v127_wrong_schema', $base(5,127,29,Sources::POLICY_V128,'ue2.unreal2.object-import.v1'),false],
    ['v121_still_unproven', $base(5,121,29,Sources::POLICY_V128),false],
    ['v118_still_unproven', $base(5,118,2,Sources::POLICY_V128),false],
];
$checks=[];
foreach($testCases as [$label,$s,$expected]){
    $got=Profiles::fromSnapshot($s);
    $checks[$label]=($got !== null)===$expected;
}
$checks['source_profile_v127_still_ut2004']=
    Profiles::fromGameVersion(5,127,29)===Verify::PROFILE_UT2004_V129;
$checks['ut2003_v121_not_borrowed']=
    Profiles::fromGameVersion(4,121,29)===null;
$checks['unreal2_v110_not_borrowed']=
    Profiles::fromGameVersion(2,110,2481)===null;
$actual=null;
if(isset($options['storage-root'])){
    $config=catalog_config();
    if(isset($options['db-host']))$config['db']['host']=(string)$options['db-host'];
    $storage=(string)$options['storage-root'];
    $db=catalog_db($config);
    $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    try {
        $st=$db->prepare('SELECT id,game_id,scan_status,relative_path,original_name,package_version,licensee_version FROM ue_files WHERE id=111461');
        $st->execute();$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['game_id']!==5
            ||(int)$row['package_version']!==127 ||(int)$row['licensee_version']!==29
            ||(string)$row['scan_status']!=='verified'){
            throw new RuntimeException('Original UT2004 source identity changed for #111461.');
        }
        $path=rtrim(str_replace('\\','/',$storage),'/').'/../'.ltrim(str_replace('\\','/',(string)$row['relative_path']),'/');
        // The supplied catalogue storage root is catalog/storage and
        // relative_path is catalog/storage/games..., so step out once.
        $real=realpath($path);
        if($real===false)throw new RuntimeException('Original bytes not found for #111461.');
        $hash=strtolower((string)hash_file('sha256',$real));
        if($hash!=='04f292545b011359b071d781da35b531bfa22cb5afeceb395bb6e248d9680c62'){
            throw new RuntimeException('Original source SHA256 changed for #111461.');
        }
        $metadata=(new Uedb5MetadataReader($storage))->snapshot(5,111461);
        $summary=(array)($metadata['sections']['summary'][0]??[]);
        $actual=[
            'file_id'=>111461,
            'package_name'=>(string)$row['original_name'],
            'version'=>(int)($summary['package_version']??0),
            'licensee'=>(int)($summary['licensee_version']??0),
            'original_sha256'=>$hash,
            'stored_source_policy'=>$metadata['source_policy']??null,
            'name_count'=>count($metadata['sections']['names']??[]),
            'import_count'=>count($metadata['sections']['imports']??[]),
            'export_count'=>count($metadata['sections']['exports']??[]),
            'selected_verifyimport_profile'=>Profiles::fromSnapshot($metadata),
        ];
        $checks['actual_original_v127_v128_source_policy']=
            $actual['stored_source_policy']===Sources::POLICY_V128
            && $actual['version']===127 && $actual['licensee']===29
            && $actual['name_count']===77 && $actual['import_count']===4 && $actual['export_count']===57
            && $actual['selected_verifyimport_profile']===Verify::PROFILE_UT2004_V129;
        $db->rollBack();
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'mode'=>'READ_ONLY','cases'=>count($checks),
    'checks'=>$checks,'actual_original'=>$actual,
    'runtime_native_load_compatibility_claimed'=>false,
    'unreal2_and_ut2003_not_modified'=>true],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
