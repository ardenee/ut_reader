#!/usr/bin/env php
<?php
/** Source-backed UT2003 compatibility for genuine UE2 packages v60-99. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/GameProfiles.php';
$apply=in_array('--apply',$argv,true);
$db=catalog_bootstrap()->db;
$profile=$db->query('SELECT p.id,p.engine_key,p.package_version_min,p.package_version_max,p.compatibility_rules_json FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=4 AND g.slug="ut2003" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!is_array($profile) || (int)$profile['id']!==5 || strtoupper((string)$profile['engine_key'])!=='UE2'
    || (int)$profile['package_version_min']!==100 || (int)$profile['package_version_max']!==130) {
    throw new RuntimeException('UT2003 active profile differs from the reviewed v2107 boundary.');
}
$rule=['label'=>'UT2003 v2107 UE2 package compatibility','detected_engine'=>'UE2',
    'reader_engine'=>'UE2','package_version_min'=>60,'package_version_max'=>99];
$existing=json_decode((string)$profile['compatibility_rules_json'],true,512,JSON_THROW_ON_ERROR);
if (!is_array($existing)) { throw new RuntimeException('Invalid profile compatibility JSON.'); }
$already=false;
foreach($existing as $entry) {
    if (($entry['label']??'')!==$rule['label']) { continue; }
    foreach($rule as $key=>$value) {
        if (($entry[$key]??null)!==$value) { throw new RuntimeException('Conflicting UT2003 compatibility rule.'); }
    }
    $already=true;
}
$files=$db->query('SELECT id,package_version,licensee_version FROM ue_files WHERE game_id=4 AND package_version BETWEEN 60 AND 99 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$ids=array_map('intval',array_column($files,'id'));
foreach([149416,1213602,1213624,1213633] as $id) {
    if (!in_array($id,$ids,true)) { throw new RuntimeException('Expected source-backed file missing: '.$id); }
}
if (!$already && $apply) {
    $new=$existing; $new[]=$rule;
    $next=json_encode($new,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $stmt=$db->prepare('UPDATE ue_game_profiles SET compatibility_rules_json=? WHERE id=5 AND engine_key="UE2" AND package_version_min=100 AND package_version_max=130 AND SHA2(CAST(compatibility_rules_json AS CHAR),256)=?');
    $stmt->execute([$next,hash('sha256',(string)$profile['compatibility_rules_json'])]);
    if ($stmt->rowCount()!==1) { throw new RuntimeException('Profile changed during guarded update.'); }
}
$effective=$profile;
$effective['compatibility_rules_json']=json_encode($already?$existing:array_merge($existing,[$rule]),JSON_THROW_ON_ERROR);
foreach($files as $file) {
    $decision=gp_profile_version_decision($effective,(int)$file['package_version'],(int)$file['licensee_version'],'UE2');
    if (empty($decision['ok']) || ($decision['compatibility']['reader_engine']??'')!=='UE2') {
        throw new RuntimeException('UT2003 UE2 compatibility rule rejected file #'.$file['id']);
    }
}
echo json_encode(['ok'=>true,'apply'=>$apply,'already_configured'=>$already,
    'rule'=>$rule,'affected_file_ids'=>$ids],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
