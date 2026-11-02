<?php
declare(strict_types=1);
/**
 * Frozen G3 T073/T075/T076: five genuine shipped UT99 v61/v68/v69
 * Detail.utx metadata as decoded by current UnrealDB source-selected reader.
 * No native engine execution, no PRD network access; DEV SHA-staged originals only.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$dir='C:/Temp/UnrealDB/O06/G3-BULK-T076-original-shipped-Detail-PRD/';
$manifest=json_decode((string)file_get_contents($dir.'shipped-five-real-indexed-originals-all-SHA-pinned.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$rows=[];
$ck=static function(bool $ok,string $msg)use(&$checks,&$errors):void{$checks++;if(!$ok)$errors[]=$msg;};
$ck(count($manifest)===5,'five actual same-name physically indexed and SHA-verified original files');
foreach($manifest as $r){
 $file=(string)$r['scratch_path'];$id=(int)$r['file_id'];
 $raw=file_get_contents($file);$ck(is_string($raw),'original binary available '.$id);
 if(!is_string($raw))continue;
 $ck(strlen($raw)===(int)$r['bytes'],'authentic shipped bytes '.$id);
 $ck(hash('sha256',$raw)===(string)$r['original_PRD_sha256'],'authentic shipped original SHA256 '.$id);
 $ck(hash('sha1',$raw)===(string)$r['sha1'],'authentic shipped original SHA1 '.$id);
 $ck(md5($raw)===(string)$r['md5'],'authentic shipped original MD5 '.$id);
 $reader=new CatalogUE1PackageReader($file);
 $h=$reader->getHeader();$exports=$reader->getExports();
 $names=array_map(static fn(array $x):string=>strtolower((string)($x['objectNameText']??'')),$exports);
 $ck((int)$h['version']===(int)$r['version'],'source selected header version '.$id);
 $ck((int)$h['exportCount']===count($exports),'source selected ExportMap counts '.$id);
 $ck(in_array('stones',$names,true),'real shared texture class object '.$id);
 $rows[]=['file_id'=>$id,'version'=>(int)$h['version'],'bytes'=>strlen($raw),
 'exports'=>(int)$h['exportCount'],'names'=>(int)$h['nameCount'],
 'package_guid_dwords'=>(string)$h['guidDwords'],
 'original_SHA256'=>hash('sha256',$raw),
 'loaded_source_stones_export_exists'=>true,
 'paint1_export_exists'=>in_array('paint1',$names,true),
 'rough_export_exists'=>in_array('rough',$names,true)];
}
$by=[];foreach($rows as $r)$by[$r['file_id']]=$r;
$ck(count($by)===5,'five indexed file IDs distinct');
$ck($by[113274]['package_guid_dwords']===$by[148267]['package_guid_dwords'],
 'real v61 and v69 same GUID but different physical file identity');
$ck($by[113274]['bytes']!==$by[148267]['bytes']
  && $by[113274]['version']!==$by[148267]['version'],
 'same-GUID archive instances are physically distinct across source version branch');
$result=['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,'source_game'=>'UT99',
 'actual_original_shipped_same_name_physical_candidates'=>$rows,
 'original_compiled_native_executions_by_current_reader_check'=>0,
 'native_oracle'=>'original stock v432/469f compiled source-loaded linker FPackageFileSummary'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
