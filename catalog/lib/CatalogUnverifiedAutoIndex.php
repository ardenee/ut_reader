<?php
declare(strict_types=1);

/**
 * Temporary compatibility hook for queue-producing routes that have not yet
 * adopted the explicit UnverifiedFileStager contract. Converted writers must
 * stage and receive their ue_files identity during the request instead of
 * relying on a directory diff at shutdown.
 */
function catalog_unverified_auto_index_enabled(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return false;
    }

    return in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), [
        'profiled-upload.php',
        'http-source-scan.php',
    ], true);
}

/** @return array<string,array{mtime:int,size:int,game_id:int,queue_name:string}> */
function catalog_unverified_auto_index_inventory(array $config, array $gameIdsBySlug): array
{
    $storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($storage === '' || !is_dir($storage)) return [];
    $directories=[];
    foreach($gameIdsBySlug as $slug=>$gameId)$directories[]=['path'=>$storage . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'unverified','game_id'=>(int)$gameId];
    $inventory=[];
    foreach($directories as $entry){
        $dir=(string)$entry['path'];if(!is_dir($dir)||!is_readable($dir))continue;
        foreach(scandir($dir)?:[] as $name){
            if($name==='.'||$name==='..'||str_starts_with($name,'.')||str_ends_with(strtolower($name),'.txt'))continue;
            $path=$dir.DIRECTORY_SEPARATOR.$name;if(!is_file($path))continue;$real=realpath($path);if($real===false)continue;
            $inventory[$real]=['mtime'=>(int)(filemtime($real)?:0),'size'=>(int)(filesize($real)?:0),'game_id'=>(int)$entry['game_id'],'queue_name'=>$name];
        }
    }
    return $inventory;
}

function catalog_unverified_register_auto_index(): void
{
    if(!catalog_unverified_auto_index_enabled()||!catalog_support_is_admin())return;
    try{
        $config=catalog_config();$db=catalog_db($config);$gameIdsBySlug=[];
        foreach(catalog_all($db,'SELECT id,slug FROM ue_games') as $game){$slug=strtolower(trim((string)$game['slug']));$slug=preg_replace('/[^a-z0-9]+/','-',$slug)??'';$slug=trim($slug,'-');if($slug!=='')$gameIdsBySlug[$slug]=(int)$game['id'];}
        $before=catalog_unverified_auto_index_inventory($config,$gameIdsBySlug);
        $userId=isset($_SESSION['user']['id'])?(int)$_SESSION['user']['id']:null;
        $uploadedNames=is_array($_FILES['packages']['name']??null)?array_values($_FILES['packages']['name']):[];
        $uploadedRelativePaths=is_array($_POST['relative_paths']??null)?array_values($_POST['relative_paths']):[];
    }catch(Throwable $error){error_log('[UnrealDB unverified auto-index] setup failed: '.$error->getMessage());return;}

    register_shutdown_function(static function()use($config,$db,$gameIdsBySlug,$before,$userId,$uploadedNames,$uploadedRelativePaths):void{
        try{
            require_once __DIR__ . '/UnverifiedFileManager.php';
            require_once __DIR__ . '/CatalogUnverifiedIndex.php';
            catalog_unverified_schema_ensure($db);
            $sourcePathsByName=[];
            foreach($uploadedNames as $index=>$uploadedName){$clean=strtolower(scanner_clean_original_filename((string)$uploadedName));$relative=scanner_normalize_source_relative_path((string)($uploadedRelativePaths[$index]??''));if($clean!==''&&$relative!=='')$sourcePathsByName[$clean]=$relative;}
            foreach(catalog_unverified_auto_index_inventory($config,$gameIdsBySlug) as $path=>$entry){
                $old=$before[$path]??null;if($old!==null&&(int)$old['mtime']===(int)$entry['mtime']&&(int)$old['size']===(int)$entry['size'])continue;
                $queueGameId=(int)$entry['game_id'];$queueName=(string)$entry['queue_name'];if(catalog_unverified_find($db,$queueGameId,$queueName))continue;
                $reasonPath=$path.'.txt';$reason=is_file($reasonPath)?trim((string)@file_get_contents($reasonPath,false,null,0,65535)):'';$originalName=uvf_original_name_from_queue_name($queueName);
                $sourceRelativePath=$sourcePathsByName[strtolower(scanner_clean_original_filename($originalName))]??'';
                try{catalog_unverified_index_path($db,$config,$queueGameId,$queueName,$path,$originalName,$reason,$userId,$sourceRelativePath,false);}
                catch(Throwable $error){error_log('[UnrealDB unverified auto-index] '.$originalName.': '.$error->getMessage());@file_put_contents($reasonPath,"\nDatabase staging failed: ".$error->getMessage(),FILE_APPEND);}
            }
        }catch(Throwable $error){error_log('[UnrealDB unverified auto-index] shutdown failed: '.$error->getMessage());}
    });
}

catalog_unverified_register_auto_index();
