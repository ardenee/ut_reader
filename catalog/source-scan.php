<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogParser.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/CatalogRedirectArchive.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function clean_relative_path(string $base, string $path): string
{
    $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : basename($path);
}

function allowed_source_extension(string $path, array $profile, array $config): bool
{
    if (catalog_redirect_archive_is_supported_filename($path)) return true;
    $cleanName = catalog_clean_unreal_filename(basename($path));
    return in_array(catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION)), scanner_profile_extensions($profile, $config), true);
}

function record_file_location(PDO $db, PDOStatement $upsert, int $fileId, int $sourceId, string $relativePath): void
{
    $upsert->execute([$fileId, $sourceId, $relativePath, 1]);
}

function source_scan_tmp_copy(string $path): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ue_src_scan_');
    if (!$tmp) throw new RuntimeException('Could not create temporary file for profiled source import.');
    if (!copy($path, $tmp)) { @unlink($tmp); throw new RuntimeException('Could not copy source file to temporary scan file.'); }
    return $tmp;
}

function source_scan_work_file(string $path): array
{
    $name = basename($path);
    if (!catalog_redirect_archive_is_supported_filename($name)) return ['path'=>$path,'name'=>catalog_clean_unreal_filename($name),'temp'=>false,'redirect'=>false,'source_extension'=>''];
    $decoded = catalog_redirect_archive_decompress_to_temp($path, $name);
    return ['path'=>(string)$decoded['path'],'name'=>catalog_clean_unreal_filename((string)$decoded['filename']),'temp'=>true,'redirect'=>true,'source_extension'=>(string)$decoded['source_extension']];
}

function source_scan_cleanup_work_file(array $work): void
{
    if (!empty($work['temp']) && is_file((string)$work['path'])) @unlink((string)$work['path']);
}

function source_scan_scanner_relative_path(string $relative, array $work): string
{
    $relative = scanner_normalize_source_relative_path($relative);
    if ($relative === '' || empty($work['redirect'])) return $relative;
    $dir = trim(str_replace('\\', '/', dirname($relative)), '. /');
    return scanner_normalize_source_relative_path(($dir !== '' ? $dir . '/' : '') . (string)$work['name']);
}

function source_scan_import_work_file(PDO $db, array $config, array $source, array $work, string $relative, bool $strictProfile): array
{
    return scanner_scan_uploaded_file($db,$config,(int)$source['game_id'],source_scan_tmp_copy((string)$work['path']),(string)$work['name'],$_SESSION['user']['id'] ?? null,$strictProfile,null,false,['source_relative_path'=>source_scan_scanner_relative_path($relative,$work)]);
}

function source_scan_result_sample(string $path, array $work, string $message): string
{
    return (!empty($work['redirect']) ? $path . ' → ' . (string)$work['name'] : $path) . ' - ' . $message;
}

function source_scan_record_import_result(PDO $db, PDOStatement $upsert, int $sourceId, string $relative, array $result, int &$imported, int &$duplicates, int &$locations): void
{
    if (($result[0] ?? '') === 'duplicate') {
        $duplicates++;
        if (!empty($result[1])) { record_file_location($db,$upsert,(int)$result[1],$sourceId,$relative); $locations++; }
        return;
    }
    $imported++;
    record_file_location($db,$upsert,(int)$result[1],$sourceId,$relative);
    $locations++;
}

function source_scan_stage_failed(PDO $db, array $config, array $source, array $work, string $relative, Throwable $error): ?array
{
    $path = (string)$work['path'];
    if (!is_file($path)) return null;
    $sourceRelativePath = source_scan_scanner_relative_path($relative,$work);
    $reason = 'Local Source Scan import failed for ' . $sourceRelativePath . ': ' . $error->getMessage();
    $stager = new \UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager($db,$config);
    $result = $stager->stageFailedCopy(
        (int)$source['game_id'],
        $path,
        (string)$work['name'],
        $reason,
        isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null,
        $sourceRelativePath
    );
    return $result === null ? null : ['queue_name'=>(string)$result['queue_name'],'file_id'=>(int)$result['file_id']];
}

function scan_local_source(PDO $db, array $config, int $sourceId, bool $importUnknown, bool $strictProfile): array
{
    $source = catalog_one($db,'SELECT s.*,g.name game_name,g.slug game_slug,p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?',[$sourceId]);
    if (!$source) throw new RuntimeException('Source not found');
    if ($source['source_type'] !== 'local_path') throw new RuntimeException('Only local folder sources can be scanned by this page.');
    $profile = gp_required_profile_for_game($db,(int)$source['game_id']);
    $profileEngine = strtoupper((string)$profile['engine_key']);
    $basePath = rtrim((string)$source['base_path'],DIRECTORY_SEPARATOR);
    if (!is_dir($basePath) || !is_readable($basePath)) throw new RuntimeException('Source path is not readable: ' . $basePath);

    $c = array_fill_keys(['found','redirect_archives','matched_md5','matched_guid','guid_ambiguous','parse_failed','unknown','locations','imported','duplicates','import_failed','staged_unverified'],0);
    $unknownSamples=$parseFailedSamples=$importSamples=[];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath,FilesystemIterator::SKIP_DOTS|FilesystemIterator::FOLLOW_SYMLINKS),RecursiveIteratorIterator::SELF_FIRST);
    $upsert = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source),last_seen_at=NOW()');

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) continue;
        $path=$item->getPathname();
        if (!allowed_source_extension($path,$profile,$config)) continue;
        $c['found']++;
        $relative=clean_relative_path($basePath,$path);
        $work=null;
        try {
            $work=source_scan_work_file($path);
            if(!empty($work['redirect']))$c['redirect_archives']++;
            $md5=md5_file((string)$work['path']);
            if(!$md5){$c['unknown']++;if(count($unknownSamples)<50)$unknownSamples[]=source_scan_result_sample($path,$work,'could not hash file');continue;}
            $file=catalog_one($db,'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',[(int)$source['game_id'],$md5]);
            if($file){record_file_location($db,$upsert,(int)$file['id'],$sourceId,$relative);scanner_record_source_relative_path($db,(int)$file['id'],source_scan_scanner_relative_path($relative,$work));$c['matched_md5']++;$c['locations']++;continue;}

            try{$header=catalog_try_read_package_header($config,$profileEngine,(string)$work['path']);$guid=catalog_header_guid($header);}catch(Throwable $parseError){
                if(!$importUnknown){$c['parse_failed']++;if(count($parseFailedSamples)<50)$parseFailedSamples[]=source_scan_result_sample($path,$work,$parseError->getMessage());continue;}
                try{$result=source_scan_import_work_file($db,$config,$source,$work,$relative,$strictProfile);source_scan_record_import_result($db,$upsert,$sourceId,$relative,$result,$c['imported'],$c['duplicates'],$c['locations']);if(count($importSamples)<50)$importSamples[]=source_scan_result_sample($path,$work,(string)$result[2]);}
                catch(Throwable $scanError){$c['import_failed']++;try{if(source_scan_stage_failed($db,$config,$source,$work,$relative,$scanError))$c['staged_unverified']++;}catch(Throwable $stageError){$scanError=$stageError;}if(count($parseFailedSamples)<50)$parseFailedSamples[]=source_scan_result_sample($path,$work,'profiled import failed: '.$scanError->getMessage());}
                continue;
            }

            if($guid!==''){
                $matches=catalog_all($db,'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id',[(int)$source['game_id'],$guid]);
                if(count($matches)===1){record_file_location($db,$upsert,(int)$matches[0]['id'],$sourceId,$relative);scanner_record_source_relative_path($db,(int)$matches[0]['id'],source_scan_scanner_relative_path($relative,$work));$c['matched_guid']++;$c['locations']++;continue;}
                if(count($matches)>1){$c['guid_ambiguous']++;if(count($unknownSamples)<50)$unknownSamples[]=source_scan_result_sample($path,$work,'GUID matches multiple catalog files: '.$guid);continue;}
            }

            if(!$importUnknown){$c['unknown']++;if(count($unknownSamples)<50)$unknownSamples[]=source_scan_result_sample($path,$work,$guid===''?'no GUID found':'GUID not in catalog: '.$guid);continue;}
            try{$result=source_scan_import_work_file($db,$config,$source,$work,$relative,$strictProfile);source_scan_record_import_result($db,$upsert,$sourceId,$relative,$result,$c['imported'],$c['duplicates'],$c['locations']);if(count($importSamples)<50)$importSamples[]=source_scan_result_sample($path,$work,(string)$result[2]);}
            catch(Throwable $scanError){$c['import_failed']++;try{if(source_scan_stage_failed($db,$config,$source,$work,$relative,$scanError))$c['staged_unverified']++;}catch(Throwable $stageError){$scanError=$stageError;}if(count($unknownSamples)<50)$unknownSamples[]=source_scan_result_sample($path,$work,($guid===''?'no GUID':'GUID not in catalog: '.$guid).'; profiled import failed: '.$scanError->getMessage());}
        } catch(Throwable $error){$c['parse_failed']++;if($importUnknown&&is_array($work)){try{if(source_scan_stage_failed($db,$config,$source,$work,$relative,$error))$c['staged_unverified']++;}catch(Throwable $stageError){$error=$stageError;}}if(count($parseFailedSamples)<50)$parseFailedSamples[]=$path.' - '.$error->getMessage();}
        finally{if(is_array($work))source_scan_cleanup_work_file($work);}
    }
    return $c+['source'=>$source,'unknown_samples'=>$unknownSamples,'parse_failed_samples'=>$parseFailedSamples,'import_samples'=>$importSamples];
}

try{
    $config=catalog_config();$db=catalog_db($config);
    if(!catalog_require_admin_page('Source scan'))exit;
    catalog_head('Source scan');
    catalog_page_header('Source scanner','Recursively scan game-owned folders. Failed valid package imports are copied into database-backed unverified staging with their source-relative paths.',['Game Sources'=>'sources.php','HTTP Source Scan'=>'http-source-scan.php','Upload Files'=>'profiled-upload.php','Unverified Files'=>'unverified-files.php']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        catalog_check_csrf('source_scan');
        $result=scan_local_source($db,$config,(int)($_POST['source_id']??0),(string)($_POST['import_unknown']??'0')==='1',(string)($_POST['strict_profile']??'1')==='1');
        echo '<div class="card"><h2>Scan result</h2><table><tr><th>Source</th><td>'.catalog_h((string)$result['source']['name']).'</td></tr><tr><th>Game</th><td>'.catalog_h((string)$result['source']['game_name']).'</td></tr>';
        foreach(['found'=>'Package-like files found','redirect_archives'=>'Redirect archives decompressed','matched_md5'=>'Matched by MD5','matched_guid'=>'Matched by GUID','guid_ambiguous'=>'Ambiguous GUID matches','parse_failed'=>'Parse failed','unknown'=>'Unknown / not cataloged','imported'=>'Imported by profiled scanner','duplicates'=>'Duplicate imports','import_failed'=>'Profiled import failed','staged_unverified'=>'Moved to unverified staging','locations'=>'Locations recorded'] as $key=>$label)echo '<tr><th>'.catalog_h($label).'</th><td>'.(int)$result[$key].'</td></tr>';echo '</table></div>';
        foreach(['import_samples'=>'Profiled import samples','unknown_samples'=>'Unknown / ambiguous samples','parse_failed_samples'=>'Parse failed samples'] as $key=>$title){if(!$result[$key])continue;echo '<div class="card"><h2>'.catalog_h($title).'</h2><table><tr><th>Path / result</th></tr>';foreach($result[$key] as $sample)echo '<tr><td class="mono path">'.catalog_h((string)$sample).'</td></tr>';echo '</table></div>';}
    }
    $sources=catalog_all($db,'SELECT s.*,g.name game_name,p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.is_active=1 ORDER BY g.name,s.name');
    echo '<div class="card"><h2>Run scan</h2>';
    if(!$sources)echo '<p class="muted">No sources configured. Add one in <a href="sources.php">Game Sources</a>.</p>';
    else{echo '<form method="post"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('source_scan')).'"><p><label>Source<br><select name="source_id">';foreach($sources as $source)echo '<option value="'.(int)$source['id'].'">'.catalog_h((string)$source['game_name'].' / '.((string)($source['profile_engine']?:'no profile')).' - '.(string)$source['name']).'</option>';echo '</select></label></p><p><label><input type="checkbox" name="import_unknown" value="1"> Import unknown files and stage failed valid packages</label></p><p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject mismatches</option><option value="0">Loose: use detected reader where possible</option></select></label></p><button>Scan selected source</button></form>';}
    echo '</div>';
    $recent=catalog_all($db,'SELECT l.*,f.package_name,f.original_name,s.name source_name FROM ue_file_locations l JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" JOIN ue_sources s ON s.id=l.source_id ORDER BY l.last_seen_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent source links</h2>';
    if(!$recent)echo '<p class="muted">No source links recorded yet.</p>';
    else{echo '<table><tr><th>Source</th><th>Package</th><th>File</th><th>Relative source path</th><th>Last seen</th></tr>';foreach($recent as $row)echo '<tr><td>'.catalog_h((string)$row['source_name']).'</td><td class="mono">'.catalog_h((string)$row['package_name']).'</td><td>'.catalog_h((string)$row['original_name']).'</td><td class="mono path">'.catalog_h((string)$row['source_relative_path']).'</td><td>'.catalog_h((string)$row['last_seen_at']).'</td></tr>';echo '</table>';}
    echo '</div>';catalog_foot();
}catch(Throwable $e){if(!headers_sent())catalog_head('Source scan error');echo '<div class="card"><h1>Error</h1><p>'.catalog_h($e->getMessage()).'</p></div>';catalog_foot();}
