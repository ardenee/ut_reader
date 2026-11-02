#!/usr/bin/env php
<?php
/** Independently compiled UT99 v432 LoadPackage / USoundExporterWAV oracle.
 * The commandlet has already run on DEV. This verifier only reads frozen
 * original native outputs and source package; no engine runs during tests.
 * Epic v400 Editor/Src/UnEdExp.cpp exports the EXACT USound::Data bytes.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Props;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$base='C:/Temp/UnrealDB/O06/Native-UCC';
$file=$base.'/System/UnrealShare.u';
$trace=$base.'/NativeUnrealShareSoundLoad.log';
$preflight=[];
$checkHash=static function(string $path,string $sha,string $name)use(&$preflight):void{
 if(!is_file($path)||hash_file('sha256',$path)!==$sha)$preflight[]="sealed_artifact_".$name;
};
$checkHash($file,'d75a7a5a4dbdcbcbfc363eadae810f99dfc4030c203032ed2dc88d255811155d','UnrealShare_u');
$checkHash($trace,'38d4da7f9fc4717d5289315690a1c9286ed174eb5412d57a691522f7f24c67d6','native_log');
$checkHash($base.'/System/ucc.exe','90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f','ucc_exe');
$source='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Editor/Src/UnEdExp.cpp';
$epic=(string)@file_get_contents($source);
if(!str_contains($epic,'USoundExporterWAV::ExportBinary')
 ||!str_contains($epic,'Sound->Data.Load();')
 ||!str_contains($epic,'Ar.Serialize( &Sound->Data(0), Sound->Data.Num() );'))
 $preflight[]='Epic_v400_WAV_exporter_raw_bytes_contract';
$log=(string)@file_get_contents($trace);
if(str_contains($log,"\x00"))$log=(string)iconv('UTF-16LE','UTF-8',$log);
preg_match_all('/^Exported Sound (UnrealShare\.\S+) to (.+\.wav)\r?$/mi',$log,$matches,PREG_SET_ORDER);
$native=[];
foreach($matches as $m){$key=strtolower($m[1]);if(isset($native[$key]))die("duplicate native path $key\n");$native[$key]=str_replace('\\','/',$m[2]);}
if(!str_contains($log,'Loading package UnrealShare.u...')
 ||!str_contains($log,'Success - 0 error(s), 0 warnings')
 ||str_contains($log,'Critical:')||str_contains($log,'Failed loading package'))
 $preflight[]='compiled_LoadPackage_did_not_complete_cleanly';
$package=new Package($file);$ex=$package->getExports();$im=$package->getImports();$found=[];$failed=$preflight;$statuses=[];
foreach($ex as $e) {
 $cl=(int)$e['classIndex'];
 if($cl>=0||strcasecmp((string)($im[-$cl-1]['objectNameText']??''),'Sound')!==0)continue;
 $idx=(int)$e['index'];$segments=[(string)$e['objectNameText']];$outer=(int)$e['outerIndex'];$seen=[];
 while($outer>0) {
  if(isset($seen[$outer])||!isset($ex[$outer-1])){ $failed[]="$idx bad outer";break;}
  $seen[$outer]=true;array_unshift($segments,(string)$ex[$outer-1]['objectNameText']);$outer=(int)$ex[$outer-1]['outerIndex'];
 }
 if($outer<0){$failed[]="$idx imported outer";continue;}
 if($outer>0){$failed[]="$idx unresolved outer";continue;}
 array_unshift($segments,'UnrealShare');
 $path=implode('.',$segments);$key=strtolower($path);
 try{
  $props=Props::inspect($file,$package,$idx,'ut99-v432');
  $obj=Native::inspect($file,$package,$idx,$props,'ut99-v432');
  $st=$obj['status']??'no status';$statuses[$st]=($statuses[$st]??0)+1;
  if($st!=='native_sound_record_complete'){$failed[]="$path incomplete native sound";continue;}
  if(!isset($native[$key])){$failed[]="$path not native";continue;}
  $wav=$native[$key];
  if(strtolower(dirname($wav))!==strtolower($base.'/exports/UnrealShareSounds')){
   $failed[]="$path invalid native export folder";continue;
  }
  if(!is_file($wav)){$failed[]="$path wav missing";continue;}
  $n=(int)($obj['audio_data_bytes']??-1);
  $pos=(int)($obj['audio_data_offset']??-1);
  if($n<0||$pos<0){$failed[]="$path no audio bytes status=$st";continue;}
  $fp=fopen($file,'rb');fseek($fp,$pos);$raw='';
  while(strlen($raw)<$n){$part=fread($fp,min(65536,$n-strlen($raw)));if($part===false||$part==='')break;$raw.=$part;}
  fclose($fp);
  $filebytes=filesize($wav);
  $same=$filebytes===$n&&hash_file('sha256',$wav)===hash('sha256',$raw);
  if(!$same)$failed[]="$path difference on-disk $n native $filebytes status=$st raw=".hash('sha256',$raw)." wav=".hash_file('sha256',$wav);
  else $found[$key]=[$idx,$n,$pos,hash('sha256',$raw)];
 }catch(Throwable $err){$failed[]="$path ".$err->getMessage();}
}
$extra=array_diff_key($native,$found);
ksort($found);$canonical=[];
foreach($found as $key=>$row)$canonical[]=$key."\t".$row[1]."\t".$row[3];
$rootHash=hash('sha256',implode("\n",$canonical)."\n");
if($rootHash!=='adb13e1895ab3aae967a8b47b803c2fbb9d12c0b9c348849e058e1f48ea09400')
 $failed[]='native_262_export_canonical_hash_changed';
if(count($native)!==262||count($found)!==262||array_sum(array_column($found,1))!==7225244)
 $failed[]='native_sound_object_or_byte_total_changed';
echo json_encode(['ok'=>$failed===[],'native_engine'=>'UT99 compiled v432','native_export_log_sha256'=>hash_file('sha256',$trace),'native_export_log_count'=>count($native),'package_export_count'=>count($ex),'matched'=>count($found),'canonical_root_sha256'=>$rootHash,'reader_statuses'=>$statuses,'failure_count'=>count($failed),'failure_sample'=>array_slice($failed,0,25),'unmatched_native'=>array_slice(array_keys($extra),0,10),'sum_matched_bytes'=>array_sum(array_column($found,1))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]&&count($native)===count($found)?0:1);
