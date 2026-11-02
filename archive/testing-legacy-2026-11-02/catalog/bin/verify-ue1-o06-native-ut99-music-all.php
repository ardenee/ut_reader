#!/usr/bin/env php
<?php
/**
 * O06 G5: independent compiled UT99 v432 UMusic exporter oracle.
 * Verifies 30 actual .umx archives and 30 frozen native exported payloads
 * against UnrealDB's production property and native-object readers.
 * Does NOT run the native executable, modify original .umx or test on PRD.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Props;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$base='C:/Temp/UnrealDB/O06/Native-UCC';
$manifestPath="$base/NativeMusicAllManifest.json";
$expectedManifest='5f6c9e7e557532c8660713af7892e6dad04b70e7b4c539c2994a0af1744d8fb5';
$errors=[];$matched=[];$types=[];$total=0;$nativeLogCount=0;
$check=static function(bool $ok,string $label)use(&$errors):void{if(!$ok)$errors[]=$label;};
$check(is_file($manifestPath)&&hash_file('sha256',$manifestPath)===$expectedManifest,'sealed_native_manifest_sha256');
$engine='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Engine/Src/UnAudio.cpp';
$exporter='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Editor/Src/UnEdExp.cpp';
$check(hash_file('sha256',$engine)==='c648a3106b1ea910da178b17324ccf8c9d9eaf04b647671277019c61291f7ea8','pinned_Epic_UMusic_source');
$check(hash_file('sha256',$exporter)==='dfeb1650641043996313da358f897daf3b5de4da66affe820d4e930e4d4f070f','pinned_Epic_exporter_source');
$source=(string)@file_get_contents($engine);$editor=(string)@file_get_contents($exporter);
$check(str_contains($source,'void UMusic::Serialize( FArchive& Ar )')
 &&str_contains($source,'Ar << FileType;')
 &&str_contains($source,'Ar << Data;'),'Epic_UMusic_serialized_FileType_and_Data');
$check(str_contains($editor,'UMusicExporterTracker::ExportBinary')
 &&str_contains($editor,'Music->Data.Load();')
 &&str_contains($editor,'Ar.Serialize( &Music->Data(0), Music->Data.Num() );'),
 'Epic_music_export_exact_lazy_data_bytes');
$manifestRaw=(string)file_get_contents($manifestPath);
// Native PowerShell 5.1 Set-Content -Encoding UTF8 emits a UTF-8 BOM.
$manifest=json_decode(substr($manifestRaw,0,3)==="\xef\xbb\xbf"?substr($manifestRaw,3):$manifestRaw,
    true,512,JSON_THROW_ON_ERROR);
$check(is_array($manifest)&&count($manifest)===30,'native_manifest_thirty_packages');
foreach($manifest as $entry){
    $name=(string)$entry['package'];
    if(!preg_match('/^[a-zA-Z0-9_-]+\.umx$/',$name)){ $errors[]='unsafe_music_package_name';continue;}
    $stem=pathinfo($name,PATHINFO_FILENAME);
    $file="$base/System/$name";$logPath="$base/NativeMusicAllLogs/$stem.log";
    $check(is_file($file)&&hash_file('sha256',$file)===(string)$entry['sha256'],"{$name}_package_digest");
    $check(is_file($logPath)&&hash_file('sha256',$logPath)===(string)$entry['log_sha256'],"{$name}_native_log_digest");
    $check(($entry['ok']??false)===true&&(int)$entry['exit']===0
        &&(int)$entry['exported_files']===1,"{$name}_native_success_manifest");
    $log=(string)@file_get_contents($logPath);
    if(str_contains($log,"\x00"))$log=(string)iconv('UTF-16LE','UTF-8',$log);
    $check(str_contains($log,"Loading package $name...")
       &&str_contains($log,'Success - 0 error(s), 0 warnings')
       &&!str_contains($log,'General protection fault')
       &&!str_contains($log,'Failed loading package'),"{$name}_clean_compiled_load");
    preg_match_all('/^Exported Music (\S+) to (.+\.it)\r?$/mi',$log,$matches,PREG_SET_ORDER);
    $nativeLogCount+=count($matches);
    $check(count($matches)===1,"{$name}_one_native_music_export");
    if(count($matches)!==1)continue;
    $fullPath=(string)$matches[0][1];
    $exported=str_replace('\\','/',(string)$matches[0][2]);
    $expectedDir="$base/exports/MusicAll/$stem";
    $check(strcasecmp(dirname($exported),$expectedDir)===0,"{$name}_correct_export_directory");
    $check(is_file($exported),"{$name}_export_file_exists");
    if(!is_file($exported))continue;
    try {
        $pkg=new Package($file);
        $check($pkg->validatePackage()===[],"{$name}_unrealdb_valid_package");
        $ex=$pkg->getExports();$im=$pkg->getImports();$music=[];
        foreach($ex as $e){
            $class=(int)$e['classIndex'];
            if($class<0&&strcasecmp((string)($im[-$class-1]['objectNameText']??''),'Music')===0)
                $music[]=$e;
        }
        $check(count($music)===1,"{$name}_unrealdb_one_music_export");
        if(count($music)!==1)continue;
        $e=$music[0];$idx=(int)$e['index'];
        $segments=[(string)$e['objectNameText']];$outer=(int)$e['outerIndex'];$seen=[];
        while($outer>0){
            if(isset($seen[$outer])||!isset($ex[$outer-1]))throw new RuntimeException('Invalid export outer');
            $seen[$outer]=true;array_unshift($segments,(string)$ex[$outer-1]['objectNameText']);
            $outer=(int)$ex[$outer-1]['outerIndex'];
        }
        if($outer!==0)throw new RuntimeException('Imported/invalid music outer');
        array_unshift($segments,$stem);$sourcePath=implode('.',$segments);
        $check(strcasecmp($sourcePath,$fullPath)===0,"{$name}_exact_native_outer_path");
        $prop=Props::inspect($file,$pkg,$idx,'ut99-v432');
        $native=Native::inspect($file,$pkg,$idx,$prop,'ut99-v432');
        $check(($native['status']??null)==='native_music_record_complete',"{$name}_complete_UMusic_archive");
        $len=(int)($native['audio_data_bytes']??-1);
        $offset=(int)($native['audio_data_offset']??-1);
        $check($offset>=0&&$len>=0&&$offset+$len<=filesize($file),"{$name}_audio_archive_bounds");
        if($offset<0||$len<0||$offset+$len>filesize($file))continue;
        $stream=fopen($file,'rb');if(!$stream)throw new RuntimeException('Cannot open .umx');
        try{
            if(fseek($stream,$offset)!==0)throw new RuntimeException('Cannot seek .umx');
            $context=hash_init('sha256');$left=$len;
            while($left>0){
                $piece=fread($stream,min(65536,$left));
                if($piece===false||$piece==='')throw new RuntimeException('Truncated .umx audio payload');
                hash_update($context,$piece);$left-=strlen($piece);
            }
            $payloadHash=hash_final($context);
        }finally{fclose($stream);}
        $nativeSize=filesize($exported);$nativeHash=hash_file('sha256',$exported);
        $check($nativeSize===$len&&$nativeHash===$payloadHash,"{$name}_compiled_native_identical_music_bytes");
        $check($nativeSize===(int)$entry['exported_bytes'],"{$name}_native_manifest_byte_count");
        $types[(string)($native['file_type_name']??'')]=true;
        $matched[]=strtolower($sourcePath)."\t".$len."\t".$payloadHash;
        $total+=$len;
    }catch(Throwable $e){$errors[]="$name: ".$e->getMessage();}
}
sort($matched,SORT_STRING);
$root=hash('sha256',implode("\n",$matched)."\n");
$check($root==='327fbff329e66ede3ded4a9cbf7d9a63b114fca7df7f6ba52fcfd9c11bdc9925',
       'thirty_native_payload_hash_manifest_sealed');
$check($total===49460075,'thirty_native_payload_bytes_sealed');
$check(count($matched)===30,'all_thirty_music_payloads_compared');
$check($nativeLogCount===30,'thirty_native_exports_observed');
echo json_encode(['ok'=>$errors===[],'native_engine'=>'UT99 compiled v432',
 'genuine_package_count'=>count($manifest),'native_export_count'=>$nativeLogCount,
 'matching_payload_count'=>count($matched),'matching_payload_bytes'=>$total,
 'source_file_type_names'=>array_keys($types),'canonical_payload_sha256'=>$root,
 'manifest_sha256'=>$expectedManifest,'errors'=>$errors],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:1);
