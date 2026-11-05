<?php
/**
 * Focused regression for catalogue original-game clues. This is a UI/catalogue
 * provenance contract, not an Epic source-serialization acceptance test.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogGameOwnershipEvidence;
use UnrealDb\Catalog\Presentation\Ui\CatalogGameOwnershipEvidenceUi;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY, game_id INTEGER, scan_status TEXT, md5 TEXT, sha1 TEXT, file_size INTEGER)');
$db->exec('CREATE INDEX idx_game_md5 ON ue_files(game_id,md5)');
$add=$db->prepare('INSERT INTO ue_files(id,game_id,scan_status,md5,sha1,file_size) VALUES(?,?,?,?,?,?)');
$md5a=str_repeat('a',32); $sha1a=str_repeat('b',40);
$md5c=str_repeat('c',32); $sha1c=str_repeat('d',40);
$add->execute([101,5,'verified',$md5a,$sha1a,100]);
$add->execute([102,4,'verified',$md5c,$sha1c,200]);
$add->execute([103,5,'verified',$md5c,$sha1c,200]);
$games=[
 ['id'=>2,'name'=>'Unreal II','slug'=>'unreal2'],
 ['id'=>4,'name'=>'UT2003','slug'=>'ut2003'],
 ['id'=>5,'name'=>'UT2004','slug'=>'ut2004'],
 ['id'=>12,'name'=>'Unreal Gold','slug'=>'unrealgold'],
];
$source=[
 ['id'=>1,'md5'=>$md5a,'sha1'=>$sha1a,'file_size'=>100,'source_relative_path'=>'Unreal Tournament 2004/System/U2Weapons.u','detected_engine_key'=>'UE2','detected_package_version'=>127,'detected_licensee_version'=>29],
 ['id'=>2,'md5'=>$md5a,'sha1'=>$sha1a,'file_size'=>100,'source_relative_path'=>'Unreal II The Awakening/textures/343T.utx','detected_engine_key'=>'UE2','detected_package_version'=>110,'detected_licensee_version'=>2481],
 ['id'=>3,'md5'=>str_repeat('e',32),'sha1'=>str_repeat('f',40),'file_size'=>300,'source_relative_path'=>'Unreal/Maps/DeathMatch/DM-ArizA][.ut2','detected_engine_key'=>'UE2','detected_package_version'=>127,'detected_licensee_version'=>29],
 ['id'=>4,'md5'=>str_repeat('1',32),'sha1'=>str_repeat('2',40),'file_size'=>400,'source_relative_path'=>'UT2003Weapons.utx','detected_engine_key'=>'UE2','detected_package_version'=>127,'detected_licensee_version'=>29],
 ['id'=>5,'md5'=>$md5c,'sha1'=>$sha1c,'file_size'=>200,'source_relative_path'=>'New folder/XWeapons.u','detected_engine_key'=>'UE2','detected_package_version'=>128,'detected_licensee_version'=>29],
 ['id'=>6,'md5'=>str_repeat('3',32),'sha1'=>str_repeat('4',40),'file_size'=>500,'source_relative_path'=>'UT2003/Archives/UT2004/System/example.u','detected_engine_key'=>'UE2','detected_package_version'=>127,'detected_licensee_version'=>29],
 ['id'=>7,'md5'=>str_repeat('5',32),'sha1'=>str_repeat('6',40),'file_size'=>600,'source_relative_path'=>'UT2003/Maps/example.ut2','detected_engine_key'=>'UE2','detected_package_version'=>127,'detected_licensee_version'=>29],
 ['id'=>8,'md5'=>$md5a,'sha1'=>$sha1a,'file_size'=>100,'source_relative_path'=>'New folder/343T.utx','detected_engine_key'=>'UE2','detected_package_version'=>110,'detected_licensee_version'=>2481],
];
$out=(new CatalogGameOwnershipEvidence($db))->assess($source,$games);
$checks=[
 'trusted_dir_and_exact_target'=>$out[1]['kind']==='corroborated' && $out[1]['suggested_game_id']===5,
 'uses_configured_game_slug_not_hardcoded_id'=>CatalogGameOwnershipEvidence::directoryGameIds(
     'Unreal Tournament 2004/System/U2Weapons.u', ['ut2004'=>55]
 )===[55],
 'legitimate_unreal2_reuse_not_conflict'=>$out[2]['kind']==='directory' && $out[2]['suggested_game_id']===2
     && $out[2]['exact_game_ids']===[5],
 'generic_unreal_folder_not_identified'=>$out[3]['kind']==='unknown' && $out[3]['suggested_game_id']===0,
 'filename_not_identified'=>$out[4]['kind']==='unknown' && $out[4]['suggested_game_id']===0,
 'identical_two_games_is_ambiguous'=>$out[5]['kind']==='reused' && $out[5]['suggested_game_id']===0,
 'one_exact_match_does_not_prove_origin'=>$out[8]['kind']==='identical'
     && $out[8]['suggested_game_id']===0 && $out[8]['exact_game_ids']===[5],
 'contradictory_directory_segments'=>$out[6]['kind']==='conflict' && $out[6]['suggested_game_id']===0,
 'standalone_directory_gives_advisory'=>$out[7]['kind']==='directory' && $out[7]['suggested_game_id']===4,
 'header_is_family_only'=>str_contains($out[3]['header_hint'],'not a unique game identifier'),
 'cross_game_target_existing_dup_notice'=>str_contains(CatalogGameOwnershipEvidenceUi::render($out[1],4,5),'already verified in the selected destination'),
 'no_html_injection'=>!str_contains(CatalogGameOwnershipEvidenceUi::render(['kind'=>'unknown','label'=>'<script>alert(1)</script>']),'<script>'),
 'game_lookup_is_bounded'=>count($out)===8,
];
$root=dirname(__DIR__,2);
$unverified=(string)file_get_contents($root.'/catalog/unverified-files.php');
$cross=(string)file_get_contents($root.'/catalog/dependency-cross-examine.php');
$query=(string)file_get_contents($root.'/catalog/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php');
$checks['unverified_page_renders_and_warns']=str_contains($unverified,'CatalogGameOwnershipEvidenceUi::render')
    && str_contains($unverified,'uv-ownership-selection-note')
    && str_contains($unverified,'data-ownership-game')
    && str_contains($unverified,'window.confirm');
$checks['cross_examine_page_renders']=str_contains($cross,'CatalogGameOwnershipEvidenceUi::render')
    && str_contains($cross,'Original-game clues are separate');
$checks['cross_examine_source_path_available']=str_contains($query,'f.relative_path,f.source_relative_path');
$pass=!in_array(false,$checks,true);
echo json_encode(['pass'=>$pass,'checks'=>$checks,'original_game_assignment_automatic'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($pass?0:2);
