<?php
/**
 * Unreal II source-specific legacy loadable versions: v60..69/licensee 0 or 127.
 * Actual original package-byte differential: 2026-11-06 source conformance evidence.
 * This test exercises source selection, explicit UEDB5 provenance and fail-closed
 * profile discrimination, not arbitrary native game package mount state.
 */
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue2VerifyImportSourceProfile;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver;

$expectations = [
    [2,59,0,null],
    [2,60,0,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000],
    [2,61,0,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000],
    [2,68,0,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000],
    [2,69,0,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000],
    [2,69,127,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000],
    [2,69,63,null],
    [2,70,0,null],
    [2,110,2481,null],
    [2,126,0,PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V126_2003],
    [2,126,29,null],
    [4,61,0,null],
    [4,120,28,PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107],
    [4,121,29,null],
    [5,128,29,PdoUe2VerifyImportProjectionResolver::PROFILE_UT2004_V129],
];
$errors=[];
foreach($expectations as [$game,$v,$licensee,$expected]){
    $got=Uedb5Ue2VerifyImportSourceProfile::fromGameVersion($game,$v,$licensee);
    if($got!==$expected)$errors[]="Game $game $v/$licensee expected ".var_export($expected,true).", got ".var_export($got,true);
}
$schemas=[
    [61,0,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,true],
    [68,0,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,true],
    [69,0,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,true],
    [69,63,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,false],
    [69,0,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC,false],
    [110,2481,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC,false],
    [126,0,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GAME,true],
    [126,0,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC,false],
];
foreach($schemas as [$version,$licensee,$policy,$valid]){
    $snapshot=['file'=>['game_id'=>2], 'sections'=>['summary'=>[['package_version'=>$version,'licensee_version'=>$licensee]]],
        'source_policy'=>$policy, 'section_schemas'=>['imports'=>'ue2.unreal2.object-import.v1']];
    $got=Uedb5Ue2VerifyImportSourceProfile::fromSnapshot($snapshot);
    if(($got!==null)!==$valid)$errors[]="Unreal II $version/$licensee policy $policy gate is wrong";
}
if(Uedb5Ue2VerifyImportSourceProfile::fromGameVersion(4,120,28)===null)$errors[]='UT2003 exact source-profile regression';
if(Uedb5Ue2VerifyImportSourceProfile::fromGameVersion(5,127,29)===null)$errors[]='UT2004 exact source-profile regression';
echo json_encode(['ok'=>$errors===[], 'legacy_cases'=>count($expectations),'snapshot_gates'=>count($schemas),'errors'=>$errors,'source_min_version'=>60,'fail_closed_on_wrong_game_licensee_and_policy'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:2);
