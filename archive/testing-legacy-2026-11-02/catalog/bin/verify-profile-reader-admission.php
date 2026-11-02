#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/GameProfiles.php';

$failures = [];
$check = static function (string $label, bool $value) use (&$failures): void {
    if (!$value) { $failures[] = $label; }
};
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,is_active INTEGER,engine_key TEXT,profile_name TEXT,allowed_extensions_json TEXT,compatibility_rules_json TEXT,package_version_min INTEGER,package_version_max INTEGER,licensee_version_min INTEGER,licensee_version_max INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,1,'UE1','Unreal','[\"unr\"]','[]',60,69,0,0)");
$db->exec("INSERT INTO ue_game_profiles VALUES(2,1,'UE2','UT2004','[\"ut2\"]','[{\"detected_engine\":\"UE1\",\"reader_engine\":\"UE1\",\"package_version_min\":34,\"package_version_max\":99,\"label\":\"legacy UE1 reader\"}]',100,129,0,29)");
$db->exec("INSERT INTO ue_games VALUES(12,'Unreal','unrealgold',1)");
$db->exec("INSERT INTO ue_games VALUES(5,'UT2004','ut2004',2)");
$path = tempnam(sys_get_temp_dir(), 'ue-profile-');
if (!is_string($path)) { throw new RuntimeException('Temp file unavailable'); }
try {
    foreach ([34, 54, 69, 75, 99, 130, 250, 868] as $version) {
        $bytes = pack('Vvv', 0x9e2a83c1, $version, 0) . str_repeat("\0", 32);
        file_put_contents($path, $bytes);
        $classification = gp_classify_file($db, 12, $path, 'sample.unr');
        $check('unreal_' . $version . '_attempts_selected_reader',
            !empty($classification['ok_for_selected_game'])
            && ($classification['reader_engine'] ?? null) === 'UE1'
            && ($classification['package_version'] ?? null) === $version);
    }
    file_put_contents($path, pack('Vvv',0x9e2a83c1,54,0) . str_repeat("\0",32));
    $legacy = gp_classify_file($db,5,$path,'sample.ut2');
    $check('explicit_legacy_reader_dispatch_preserved',
        !empty($legacy['ok_for_selected_game'])
        && ($legacy['reader_engine'] ?? null) === 'UE1');
    $check('unbounded_licensee_decision',
        gp_profile_version_decision(['package_version_min'=>60,'package_version_max'=>69,'licensee_version_min'=>0,'licensee_version_max'=>0,'compatibility_rules_json'=>'[]'],54,987,'UE1')['ok']);
} finally {
    @unlink($path);
}
// Profile editor must preserve old metadata when version inputs are removed.
$db->exec('ALTER TABLE ue_game_profiles ADD COLUMN game_id INTEGER');
$db->exec('ALTER TABLE ue_game_profiles ADD COLUMN confidence_policy TEXT');
$db->exec('ALTER TABLE ue_game_profiles ADD COLUMN notes TEXT');
$admin = new \UnrealDb\Catalog\Infrastructure\Games\CatalogGameProfileAdminService($db);
$updated = $admin->save('update', [
    'profile_id'=>1,'profile_name'=>'Unreal','engine_key'=>'UE1',
    'extensions'=>'unr, u','compatibility_rules_json'=>'[]',
    'confidence_policy'=>'normal','notes'=>'range metadata unchanged'
]);
$oldRange = $db->query('SELECT package_version_min,package_version_max FROM ue_game_profiles WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$check('admin_edit_keeps_historic_range', $updated === 1
    && (int)$oldRange['package_version_min'] === 60 && (int)$oldRange['package_version_max'] === 69);
$added = $admin->save('add', [
    'profile_name'=>'Other','engine_key'=>'UE2',
    'extensions'=>'u,ut2','compatibility_rules_json'=>'[]',
    'confidence_policy'=>'normal'
]);
$check('admin_add_without_version_inputs', $added > 2);
echo json_encode(['ok'=>$failures===[],'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
