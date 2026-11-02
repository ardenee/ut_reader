#!/usr/bin/env php
<?php
/** 1996 one-based imported UClass resource name/type static provider. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader as Actor;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceClassImportResolver as Resolve;
$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$tail=static fn(int $version,int $exportCount,int $importCount,int $nameCount,
  int $nameOff,int $importOff,int $expOff,int $headerOff,int $headerLen):string=>
  pack('V9',$version,$exportCount,$importCount,$nameCount,$nameOff,$importOff,
    $expOff,$headerOff,$headerLen)
  .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
$props=pack('v2V7',1,0,2,400,0,1,4,0,0)
  .pack('v2V7',2,0,4,488,0,1,4,1,0);
$provider=static function(int $v)use($tail,$props):string{
 $pre="Unreal Resource\x1A\0";$nm=pack('a32V','Family',0);
 $dataOff=strlen($pre)+strlen($nm);$expOff=$dataOff+strlen($props);
 $exp=pack('a32VVV','OtherClass',1,10,1272);$hOff=$expOff+strlen($exp);
 $actor=str_repeat("\0",1148);
 $actor=substr_replace($actor,pack('V',44),400,4);
 $actor=substr_replace($actor,pack('g',9.5),488,4);
 $header=pack('V2a32V9',0,10,'OtherClass',0,0,1,0xffffffff,0,
   $dataOff,strlen($props),0,0)
   .pack('V2',2,2).str_repeat("\0",40).$actor;
 if(strlen($header)!==1272)throw new RuntimeException('Provider UClass native size mismatch');
 return $pre.$nm.$props.$exp.$header.'[SUMMARY]'
   .$tail($v,1,0,1,strlen($pre),0,$expOff,$hOff,1272);
};
$consumer=static function(int $v)use($tail):string{
 $pre="Unreal Resource\x1A\0";$nm=pack('a32V','GroupName',0);
 $import=pack('a32VVV','OtherClass',1,10,1272);
 $importOff=strlen($pre)+strlen($nm);$dataOff=$importOff+strlen($import);
 $actor=str_repeat("\0",1148);
 $actor=substr_replace($actor,pack('V',1),0,4);
 $actor=substr_replace($actor,pack('V',55),400,4);
 $actor=substr_replace($actor,pack('g',3.0),488,4);
 $expOff=$dataOff+strlen($actor);
 $exp=pack('a32VVV','ActorGroup',1,11,116);$hOff=$expOff+strlen($exp);
 $h=pack('V2a32V9',0,11,'ActorGroup',0,0,1,0xffffffff,0,$dataOff,1148,0,0)
   .pack('V2',1,1).pack('V8',0,1,0,0,0,0,0,0);
 if(strlen($h)!==116)throw new RuntimeException('Consumer UActorList native header mismatch');
 return $pre.$nm.$import.$actor.$exp.$h.'[SUMMARY]'
   .$tail($v,1,1,1,strlen($pre),$importOff,$expOff,$hOff,116);
};
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ue1-src-class-import-'.bin2hex(random_bytes(5));
if(!mkdir($folder,0700))throw new RuntimeException('Unable to create class import fixtures');
$prov=$folder.DIRECTORY_SEPARATOR.'Provider.original';
$cons=$folder.DIRECTORY_SEPARATOR.'Consumer.original';
$dupe=$folder.DIRECTORY_SEPARATOR.'Duplicate.original';
try {
 foreach([9,10] as $v){
   $providerBytes=$provider($v);$consumerBytes=$consumer($v);
   file_put_contents($prov,$providerBytes);
   file_put_contents($cons,$consumerBytes);
   $record=Actor::read($cons,true);
   $row=$record['actor_resources'][0]['actors'][0]??[];
   $values=$row['class_property_values']['property_values']??[];
   $check("v{$v}_source_private_lookup_name_type",
      ($row['class_reference_status']??null)==='source_unique_sibling_import_UClass'
      && ($row['class_resource_name']??null)==='OtherClass'
      && ($row['class_provider_filename']??null)==='Provider.original'
      && ($row['class_provider_sha256']??null)===hash_file('sha256',$prov));
   $check("v{$v}_imported_actor_global_and_static",
      ($values[0]['values']??null)===[55]
      && ($values[1]['values']??null)===[9.5]
      && ($record['runtime_class_links_resolved']??true)===false);
   $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $cons,['format'=>'early_unreal_resource','version'=>$v]);
   $native=$stage['native_metadata']['resource_actor_records']['actor_resources'][0]['actors'][0]??[];
   $check("v{$v}_production_stages_native_imported_class",
      ($native['class_property_values']['property_values'][0]['values'][0]??null)===55
      && ($stage['parse_complete']??true)===false);
   file_put_contents($dupe,$providerBytes);
   $ambiguous=Actor::read($cons,true)['actor_resources'][0]['actors'][0];
   $check("v{$v}_ambiguous_provider_refused",
      ($ambiguous['class_reference_status']??null)==='imported_UClass_provider_unavailable'
      && ($ambiguous['class_import_resolution_status']??null)==='source_UClass_import_provider_ambiguous'
      && array_key_exists('class_property_values',$ambiguous)
      && $ambiguous['class_property_values']===null);
   unlink($dupe);unlink($prov);
   $missing=Actor::read($cons,true)['actor_resources'][0]['actors'][0];
   $check("v{$v}_missing_provider_refused",
      ($missing['class_import_resolution_status']??null)==='source_UClass_import_provider_not_found'
      && array_key_exists('class_property_values',$missing)
      && $missing['class_property_values']===null);
   file_put_contents($prov,$providerBytes);
   $invalid=substr_replace($providerBytes,pack('V',84),
      strlen("Unreal Resource\x1A\0")+36+strlen($props)+40,4);
   file_put_contents($prov,$invalid);
   $badProvider=Actor::read($cons,true)['actor_resources'][0]['actors'][0];
   $check("v{$v}_invalid_native_UClass_header_refused",
      ($badProvider['class_reference_status']??null)==='imported_UClass_provider_unavailable'
      && ($badProvider['class_import_resolution_status']??null)==='source_UClass_provider_structure_invalid');
 }
}finally{@unlink($prov);@unlink($cons);@unlink($dupe);@rmdir($folder);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
   'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
