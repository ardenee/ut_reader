<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

$config = catalog_config();
$db = catalog_db($config);
$targetGameId = max(1, (int)($argv[1] ?? 0));
$packageName = trim((string)($argv[2] ?? 'ParticleSystems'));
$game = catalog_one($db, 'SELECT g.id,g.name,p.engine_key FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?', [$targetGameId]);
if (!$game) { fwrite(STDERR, "Target game not found.\n"); exit(2); }

$missing = catalog_all($db,
    'SELECT l.file_id,f.original_name consumer_file,f.package_name consumer_package,l.import_index,l.status,'
    . 'CONVERT(obj.value_prefix USING utf8mb4) required_object,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,CONVERT(cn.value_prefix USING utf8mb4) class_name,'
    . 'HEX(l.required_path_hash) required_path_hash '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id AND f.game_id=? AND f.scan_status="verified" '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'LEFT JOIN ue_terms obj ON obj.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE l.status=0 AND CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci=? '
    . 'ORDER BY f.original_name,l.import_index', [$targetGameId,$packageName]);

$allRequirements = catalog_all($db,
    'SELECT l.file_id,f.original_name consumer_file,l.import_index,l.status,l.resolved_file_id,'
    . 'CONVERT(obj.value_prefix USING utf8mb4) required_object,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,CONVERT(cn.value_prefix USING utf8mb4) class_name,'
    . 'HEX(l.required_path_hash) required_path_hash '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id AND f.game_id=? AND f.scan_status="verified" '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'LEFT JOIN ue_terms obj ON obj.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci=? '
    . 'ORDER BY f.original_name,l.import_index', [$targetGameId,$packageName]);

$providers = catalog_all($db,
    'SELECT f.id,f.game_id,g.name game_name,p.engine_key,f.original_name,f.package_name,f.package_version,f.licensee_version,'
    . 'f.package_guid,f.md5,f.sha1,m.format_version,m.export_count '
    . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id JOIN ue_game_profiles p ON p.id=g.profile_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3 '
    . 'WHERE f.scan_status="verified" AND f.package_name COLLATE utf8mb4_unicode_ci=? '
    . 'ORDER BY p.engine_key,g.name,f.id', [$packageName]);

$requiredHashes=[]; foreach($allRequirements as $r){$h=strtoupper((string)($r['required_path_hash']??'')); if($h!=='')$requiredHashes[$h]=true;}
$providerDetail=[];
foreach($providers as $provider){
    $exports=catalog_all($db,
        'SELECT e.export_index,CONVERT(o.value_prefix USING utf8mb4) object_name,CONVERT(lp.value_prefix USING utf8mb4) local_path,'
        . 'CONVERT(cls.value_prefix USING utf8mb4) class_name,HEX(e.path_hash) path_hash '
        . 'FROM ue_export_lookup e LEFT JOIN ue_terms o ON o.id=e.object_term_id LEFT JOIN ue_terms lp ON lp.id=e.local_path_term_id '
        . 'LEFT JOIN ue_terms cls ON cls.id=e.class_term_id '
        . 'WHERE e.file_id=? ORDER BY e.export_index', [(int)$provider['id']]);
    $matching=[]; $byHash=[];
    foreach($exports as $e){$h=strtoupper((string)($e['path_hash']??'')); if($h!=='')$byHash[$h][]=$e; if(isset($requiredHashes[$h]))$matching[]=$e;}
    $missingPaths=[];
    foreach($allRequirements as $r){$h=strtoupper((string)($r['required_path_hash']??'')); if($h!==''&&!isset($byHash[$h])){$missingPaths[(string)($r['required_object']??$h)]=['class_package'=>$r['class_package']??null,'class_name'=>$r['class_name']??null,'path_hash'=>$h];}}
    $providerDetail[]=['provider'=>$provider,'matching_required_exports'=>$matching,'missing_required_paths'=>$missingPaths];
}
echo json_encode([
    'target_game'=>$game,'package'=>$packageName,
    'missing_rows'=>$missing,
    'all_target_requirements'=>$allRequirements,
    'providers'=>$providerDetail
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
