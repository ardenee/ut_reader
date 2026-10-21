#!/usr/bin/env php
<?php
/** Native Unreal v0.867 (v22/v23) primitive UDatabase archive fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileDatabaseDataReader as DB;

$checks=[];$fails=[];
$check=static function(string $k,bool $ok)use(&$checks,&$fails):void{
 $checks[$k]=$ok;if(!$ok)$fails[]=$k;
};
$types=[
 'Buffer'=>[2,3,"\x00\xffAB",'bytes'],
 'TextBuffer'=>[2,2,"Hi",'text_bytes'],
 'Words'=>[2,4,pack('v2',0x1234,0xabcd),'words'],
 'Ints'=>[2,4,pack('V2',0xffffffff,2),'ints'],
 'Array'=>[2,3,pack('V2',1,0),'object_references'],
 'EnumDef'=>[2,4,pack('v2',1,0),'name_references'],
 'Floats'=>[2,4,pack('g2',1.5,-2.25),'floats'],
 'Vectors'=>[1,2,pack('g3',1.0,-2.0,3.0),'vectors'],
];
// Buffer uses four bytes and Num=4.
$types['Buffer']=[4,5,"\x00\xffAB",'bytes'];
// Num/Max+Pos/Top for TextBuffer; no extra actor identity.
$build=static function(int $version)use($types):array{
 $names='';$objects=[];$blob='';
 $labels=[];
 foreach($types as $name=>$meta){$labels[]=$name;$labels[]=$name.'One';}
 foreach($labels as $name)$names.=$name."\0".pack('V',0);
 $off=56+strlen($names);$entries=[];$positions=[];
 foreach($types as $name=>$meta){
   [$num,$max,$data]=$meta;
   $hdr=pack('V2',0,0).str_repeat("\xff",8).pack('V2',$num,$max);
   if($name==='TextBuffer')$hdr.=pack('V2',1,0);
   $hOff=$off;$blob.=$hdr;$off+=strlen($hdr);
   $dataOff=$off;$blob.=$data;$off+=strlen($data);
   $positions[$name]=['header'=>$hOff,'data'=>$dataOff];
   $entries[]=['class'=>$name,'header'=>$hOff,'headSize'=>strlen($hdr),
       'data'=>$dataOff,'dataSize'=>strlen($data)];
 }
 $objectsOff=$off;$table='';
 foreach($entries as $i=>$e){
   $table.=pack('vvVV',$i*2+2,$i*2+1,0,$e['header'])
      .pack('V4',0,$e['data'],$e['headSize'],$e['dataSize']);
 }
 $summary=pack('a32V6',"Unrealfile\x1A",$version,count($entries),count($labels),
     56,$objectsOff,0);
 return ['bytes'=>$summary.$names.$blob.$table,'positions'=>$positions];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1v23db');
if(!is_string($tmp))throw new RuntimeException('No temporary database fixture');
try{
 foreach([22,23] as $ver){
   $fx=$build($ver);file_put_contents($tmp,$fx['bytes']);
   $parsed=DB::read($tmp,true);
   $rows=$parsed['database_records']??[];
   $by=[];
   foreach($rows as $r)$by[substr((string)$r['native_class'],1)]=$r;
   $check("v{$ver}_eight_source_database_classes",
      count($rows)===8&&($parsed['other_class_serializers_decoded']??true)===false);
   $check("v{$ver}_byte_and_text_data",
       ($by['Buffer']['opaque_byte_stream_hex']??null)==='00FF4142'
       && ($by['TextBuffer']['opaque_byte_stream_hex']??null)==='4869'
       && ($by['TextBuffer']['text_edit_position']??null)===1
       && ($by['TextBuffer']['text_top_position']??null)===0);
   $check("v{$ver}_signed_integer_and_word_values",
       ($by['Words']['record_values']??null)===[0x1234,0xabcd]
       && ($by['Ints']['record_values']??null)===[-1,2]);
   $check("v{$ver}_one_based_native_reference_values",
       ($by['Array']['record_values']??null)===[
           ['raw_one_based_ref'=>1,'object_index'=>0],
           ['raw_one_based_ref'=>0,'object_index'=>null]]
       && ($by['EnumDef']['record_values']??null)===[
           ['raw_one_based_ref'=>1,'name'=>'Buffer'],
           ['raw_one_based_ref'=>0,'name'=>null]]);
   $check("v{$ver}_float_and_archive_vector",
       ($by['Floats']['record_values']??null)===[1.5,-2.25]
       && ($by['Vectors']['record_values']??null)===[[1.0,-2.0,3.0]]
       && ($by['Vectors']['serialized_record_bytes']??null)===12);
   $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
       $tmp,['format'=>'early_unrealfile','version'=>$ver]);
   $r=$stage['native_metadata']['unrealfile_database_records']['database_records']??[];
   $check("v{$ver}_native_staging_unverified",
       count($r)===8&&($stage['parse_complete']??true)===false);
   $p=$fx['positions']['Words']['header'];
   file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',9),$p+16,4));
   try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_invalid_active_count_rejected",$reject);
   $p=$fx['positions']['Array']['data'];
   file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',9),$p,4));
   try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_invalid_object_ref_rejected",$reject);
   $p=$fx['positions']['EnumDef']['data'];
   file_put_contents($tmp,substr_replace($fx['bytes'],pack('v',17),$p,2));
   try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_invalid_name_ref_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fails===[],'passed'=>count($checks)-count($fails),
 'total'=>count($checks),'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
