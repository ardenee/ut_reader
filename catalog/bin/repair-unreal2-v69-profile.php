#!/usr/bin/env php
<?php
/** One-time source-backed Unreal II v60-69 UE2 compatibility profile correction. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/GameProfiles.php';
$apply = in_array('--apply', $argv, true);
$db = catalog_bootstrap()->db;
$sql = 'SELECT p.id,p.engine_key,p.package_version_min,p.package_version_max,p.compatibility_rules_json '
    .'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=2 AND g.slug="unreal2" LIMIT 1';
$profile = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
if (!is_array($profile) || (int)$profile['id'] !== 4 || strtoupper((string)$profile['engine_key']) !== 'UE2'
    || (int)$profile['package_version_min'] !== 83 || (int)$profile['package_version_max'] !== 130) {
    throw new RuntimeException('Unreal II profile has changed; refusing implicit compatibility adjustment.');
}
$rule = ['label'=>'Unreal II 2000-12-09 UE2 v69 source compatibility',
    'detected_engine'=>'UE2','reader_engine'=>'UE2',
    'package_version_min'=>60,'package_version_max'=>69];
$existing = json_decode((string)$profile['compatibility_rules_json'],true,512,JSON_THROW_ON_ERROR);
if (!is_array($existing)) { throw new RuntimeException('Invalid compatibility rules JSON.'); }
$already = false;
foreach ($existing as $entry) {
    if (($entry['label'] ?? '') === $rule['label']) {
        if (($entry['detected_engine'] ?? '') !== 'UE2'
            || ($entry['reader_engine'] ?? '') !== 'UE2'
            || (int)($entry['package_version_min'] ?? 0) !== 60
            || (int)($entry['package_version_max'] ?? 0) !== 69) {
            throw new RuntimeException('Conflicting source compatibility rule.');
        }
        $already = true;
    }
}
$files = $db->query('SELECT id,package_version,licensee_version FROM ue_files WHERE game_id=2 AND package_version BETWEEN 60 AND 69 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
$expected = [149492,149508,1363207];
foreach ($expected as $fileId) {
    if (!in_array($fileId, array_map('intval',array_column($files,'id')),true)) {
        throw new RuntimeException('Known failed Unreal II file missing from source-version population: '.$fileId);
    }
}
if (!$already && $apply) {
    $existing[] = $rule;
    $newJson = json_encode($existing,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $update = $db->prepare('UPDATE ue_game_profiles SET compatibility_rules_json=? WHERE id=4 AND engine_key="UE2" AND package_version_min=83 AND package_version_max=130 AND SHA2(CAST(compatibility_rules_json AS CHAR),256)=?');
    $update->execute([$newJson,hash('sha256',(string)$profile['compatibility_rules_json'])]);
    if ($update->rowCount() !== 1) { throw new RuntimeException('Unreal II profile changed concurrently.'); }
}
$effective = $profile;
$effective['compatibility_rules_json'] = json_encode($already ? $existing : array_merge($existing,[$rule]),JSON_THROW_ON_ERROR);
foreach ($files as $file) {
    $decision = gp_profile_version_decision($effective,(int)$file['package_version'],(int)$file['licensee_version'],'UE2');
    if (empty($decision['ok']) || ($decision['compatibility']['reader_engine'] ?? '') !== 'UE2') {
        throw new RuntimeException('Expected UE2 compatibility did not match file #'.$file['id']);
    }
}
echo json_encode(['ok'=>true,'apply'=>$apply,'already_configured'=>$already,
    'compatibility_rule'=>$rule,'affected_file_ids'=>array_map('intval',array_column($files,'id'))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
