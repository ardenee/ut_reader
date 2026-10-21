#!/usr/bin/env php
<?php
/** UE1 1996 original UModel, ULevel x86 source-member layout. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceModelLevelReader as Reader;
$checks=[];$bad=[];$check=static function(string $k,bool $ok)use(&$checks,&$bad):void{
    $checks[$k]=$ok;if(!$ok)$bad[]=$k;
};
$make=static function(int $version,int $type):array{
    $prefix="Unreal Resource\x1A\0";$name=pack('a32V','MODELLEVEL',0);
    $nameOff=strlen($prefix);
    $vec=static fn(float $x,float $y,float $z,int $w):string=>pack('g3V',$x,$y,$z,$w);
    $scale=static fn(float $s,int $axis):string=>
        pack('g3VgV',$s,$s,$s,55,1.5,$axis);
    $bound=$vec(-1.0,-2.0,-3.0,10)
         .$vec(1.0,2.0,3.0,11).$vec(0.0,0.0,0.0,12);
    if($type===21){
        $derived=pack('V9',1,2,3,4,5,6,7,8,9)
          .$vec(10.0,20.0,30.0,13)
          .pack('V3',0xffffffff,2,3)
          .$vec(4.0,5.0,6.0,14)
          .$scale(2.0,7)
          .pack('V5',1,2,3,4,5)
          .$vec(7.0,8.0,9.0,15)
          .$scale(3.0,8).$scale(4.0,9)
          .$bound.$bound.str_repeat("\0",32);
        $size=392;
    }else{
        $derived=pack('V2',2,5)
          .pack('C4',3,0,0,0)
          .pack('V5',10,11,12,13,14)
          .pack('V16',15,...array_fill(0,15,0));
        $size=172;
    }
    $dataOff=$nameOff+strlen($name);
    $exOff=$dataOff;
    $ex=pack('a32VVV','MemberOne',1,$type,$size);
    $headerOff=$exOff+strlen($ex);
    $header=pack('V2a32V9',0,$type,'MemberOne',0,0,1,0xffffffff,0,$dataOff,0,0,0).$derived;
    if(strlen($header)!==$size)throw new RuntimeException('ABI header fixture differs from source');
    $tail=pack('V9',$version,1,0,1,$nameOff,0,$exOff,$headerOff,$size)
       .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$name.$ex.$header.'[SUMMARY]'.$tail,
        'header'=>$headerOff,'export'=>$exOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1modellevel');
if(!is_string($tmp))throw new RuntimeException('Fixture unavailable');
try{
 foreach([9,10] as $v)foreach([21,22] as $type){
    $f=$make($v,$type);file_put_contents($tmp,$f['bytes']);
    $r=Reader::read($tmp,true)['model_level_headers'][0]??[];
    $name=$type===21?'UModel':'ULevel';$key="v{$v}_type{$type}";
    $check("{$key}_native_model_level_header",
      ($r['status']??null)===($type===21?'native_model_header_decoded':'native_level_header_decoded')
      && ($r['native_class']??null)===$name
      && ($r['header_bytes']??null)===($type===21?392:172)
      && ($r['serialized_data_bytes']??null)===0
      && ($r['runtime_resource_references_resolved']??true)===false);
    if($type===21){
        $check("{$key}_source_vector_scale_and_bounds",
          ($r['resource_reference_raw_words']['vectors']??null)===1
          && ($r['resource_reference_raw_words']['terrain']??null)===9
          && ($r['location']['xyz']??null)===[10.0,20.0,30.0]
          && ($r['location']['w_or_flags_raw_word']??null)===13
          && ($r['rotation_angles_signed']??null)===[-1,2,3]
          && ($r['scale']['sheer_axis']??null)===7
          && ($r['world_bounds'][0]['min']['xyz']??null)===[-1.0,-2.0,-3.0]
          && ($r['world_bounds'][1]['sphere']['w_or_flags_raw_word']??null)===12);
    }else{
        $check("{$key}_source_begin_play_and_references",
          ($r['level_state']??null)===2
          && ($r['begin_play_flags_raw_word']??null)===5
          && ($r['begin_play_net_flags']['cooperative']??false)===true
          && ($r['begin_play_net_flags']['deathmatch']??true)===false
          && ($r['begin_play_net_flags']['persistent']??false)===true
          && ($r['difficulty']??null)===3
          && ($r['resource_reference_raw_words']['model']??null)===10
          && ($r['text_block_reference_raw_words'][0]??null)===15);
    }
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unreal_resource','version'=>$v]
    );
    $stored=$stage['native_metadata']['resource_model_level_headers']['model_level_headers'][0]??[];
    $check("{$key}_native_staging_unverified",
      ($stored['native_class']??null)===$name
      && ($stored['runtime_resource_references_resolved']??true)===false
      && ($stage['parse_complete']??true)===false);
    file_put_contents($tmp,substr_replace($f['bytes'],
       pack('V',($type===21?388:168)),$f['export']+40,4));
    $partial=Reader::read($tmp,false)['model_level_headers'][0]??[];
    try{Reader::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$key}_truncated_source_layout_rejected",
      ($partial['status']??null)==='incomplete_source_model_level_header'&&$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',1),$f['header']+64,4));
    try{Reader::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$key}_unexpected_payload_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
