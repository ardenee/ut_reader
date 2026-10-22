#!/usr/bin/env php
<?php
/** Epic v0.867 ULevel native SerializeHeader + Num*UObject data. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileLevelReader as Level;
$checks=[];$errors=[];
$check=static function(string $id,bool $ok)use(&$checks,&$errors):void{
    $checks[$id]=$ok;if(!$ok)$errors[]=$id;
};
$make=static function(int $version):array{
    $names="Level\0".pack('V',0)."MyLevel\0".pack('V',0);
    $nameOff=56;$stack=pack('V2',0,0).str_repeat("\xff",8);
    $texts=array_fill(0,16,0);$texts[0]=1;$texts[15]=1;
    $native=pack('V21',2,3,1,0,1,...$texts);
    if(strlen($native)!==84)throw new RuntimeException('Bad ULevel native fixture header');
    $header=$stack.$native;
    $headOff=$nameOff+strlen($names);
    $data=pack('V2',1,0);
    $dataOff=$headOff+strlen($header);
    $objectsOff=$dataOff+strlen($data);
    $object=pack('vvVV',2,1,0,$headOff).pack('V4',0,$dataOff,100,8);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,1,2,$nameOff,$objectsOff,0);
    return ['bytes'=>$summary.$names.$header.$data.$object,
        'header'=>$headOff,'native'=>$headOff+16,
        'data'=>$dataOff,'object'=>$objectsOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1level97');
if(!is_string($tmp))throw new RuntimeException('No temporary file');
try{
 foreach([22,23] as $v){
    $f=$make($v);file_put_contents($tmp,$f['bytes']);
    $rows=Level::read($tmp,true)['level_records'];$r=$rows[0]??[];
    $check("v{$v}_source_header_counts",
        count($rows)===1
        && ($r['status']??null)==='native_level_header_and_data_decoded'
        && ($r['active_actor_count']??null)===2
        && ($r['actor_capacity']??null)===3
        && ($r['native_member_bytes']??null)===84);
    $check("v{$v}_typed_header_references",
        ($r['model_raw_one_based_ref']??null)===1
        && ($r['brush_array_raw_one_based_ref']??null)===0
        && ($r['reach_specs_raw_one_based_ref']??null)===1
        && count($r['text_block_raw_one_based_refs']??[])===16
        && $r['text_block_raw_one_based_refs'][0]===1
        && $r['text_block_raw_one_based_refs'][15]===1);
    $check("v{$v}_actor_element_references",
        ($r['actor_records']??null)===
        [['element_index'=>0,'raw_one_based_ref'=>1,'object_index'=>0],
         ['element_index'=>1,'raw_one_based_ref'=>0,'object_index'=>null]]
        && ($r['runtime_level_instantiated']??true)===false);
    $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unrealfile','version'=>$v]);
    $n=$staged['native_metadata']['unrealfile_level_records']['level_records'][0]??[];
    $check("v{$v}_source_typed_staging",
        ($n['active_actor_count']??null)===2
        && ($staged['parse_complete']??true)===false);
    // Num > Max must be rejected.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',4),$f['native'],4));
    try{Level::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
    $check("v{$v}_invalid_num_max_rejected",$bad);
    // ULevel header UObject references are strictly one-based ResMap.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['native']+8,4));
    try{Level::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
    $check("v{$v}_out_of_range_header_ref_rejected",$bad);
    // Data contains Num UObject references, each 32-bit.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data'],4));
    try{Level::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
    $check("v{$v}_out_of_range_actor_ref_rejected",$bad);
    // Native fixed members must be present, never guessed.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',96),$f['object']+20,4));
    $partial=Level::read($tmp,false)['level_records'][0]??[];
    try{Level::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
    $check("v{$v}_short_native_header_stays_unverified",
        ($partial['status']??null)==='incomplete_original_level_header'&&$bad);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$errors===[],'passed'=>count($checks)-count($errors),
 'total'=>count($checks),'failures'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:1);
