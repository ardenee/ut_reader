#!/usr/bin/env php
<?php
/** UE1 native ULevel source archive: real package envelope + invalid cases. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $negative=$n<0;$n=abs($n);$h=($n&63)|($negative?128:0);$n>>=6;
 if(!$n)return chr($h);$s=chr($h|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$str=static function(int $v,string $text,bool $wide=false)use($idx):string{
 if($text==='')return $idx(0);
 if($wide){
  $utf=iconv('UTF-8','UTF-16LE',$text)."\x00\x00";
  return $idx(-intdiv(strlen($utf),2)).$utf;
 }
 return $idx(strlen($text)+1).$text."\0";
};
$source=static function(int $v,array $o=[])use($idx,$str):string {
 $raw='';
 if($v>=62){
  $actors=$o['actors']??[-1,1];
  $num=$o['badActorCount']??count($actors);
  $raw.=pack('V2',$num&0xffffffff,($o['actorMax']??count($actors))&0xffffffff);
  foreach($actors as $a){$raw.=$idx($a);}
 }
 if($v>=59){
  $f=$o['badUrlWide']??false;
  $raw.=$str($v,'unreal').$str($v,'localhost').$str($v,'ExampleMap')
        .$str($v,'Entry').$idx(2).$str($v,'Game=UT99')
        .$str($v,$f?'é':'SpectatorOnly',$f)
        .pack('V2',7777,1);
 }
 $raw.=$idx($o['modelRef']??-1);
 $reach=$o['reach']??1;
 $raw.=$idx($reach);
 for($i=0;$i<max(0,min($reach,3));$i++){
  $raw.=pack('V',123).$idx(-1).$idx(1);
  $raw.=$v<54?pack('g2V',12.5,26.5,0x22):
              pack('V3',12,26,0x22).chr(1);
 }
 $raw.=pack('g',17.5).$idx($o['firstDeleted']??0);
 for($i=0;$i<16;$i++){$raw.=$idx($i===0?-1:0);}
 if($v>=61&&$v<=62){
  $raw.=$idx(2).$str($v,'LevelName').$str($v,'Player')
       .$idx(2).$str($v,'Deck16').$str($v,'Unreal');
 }elseif($v>=63){
  $raw.=$idx(2).$str($v,'LevelName').$str($v,'Deck16')
       .$str($v,'Player').$str($v,'Unreal', $v>=64);
 }
 return $raw.($o['extension']??'');
};
$build=static function(int $v,string $payload)use($idx):string{
 $names=['None','Level','TestLevel','Engine','Class'];$nameBytes='';
 foreach($names as $n){$nameBytes.=($v>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);}
 $imp=$idx(3).$idx(4).($v<50?$idx(0):pack('V',0)).$idx(1);
 $prefix=$v<40?pack('V2',0,0):'';
 if($v<47){$prefix.=$idx(0).$idx(0).str_repeat("\0",8);}
 $body=$prefix."\0".($v<57?"\0":"").($v<58?"\0":"").$payload;
 $summarySize=$v>=68?56:44;
 $no=$summarySize;$io=$no+strlen($nameBytes);$eo=$io+strlen($imp);
 $ex=$idx(-1).$idx(0).($v<50?'':pack('V',0)).$idx(2).pack('V',0)
      .$idx(strlen($body)).$idx(512);
 $sum=pack('V9',0x9e2a83c1,$v,0,count($names),$no,1,$eo,1,$io)
      .($v>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $table=$sum.$nameBytes.$imp.$ex;
 if(strlen($table)>512)throw new RuntimeException('Test package table overflow');
 return $table.str_repeat("\0",512-strlen($table)).$body;
};
$paths=[];$checks=[];$failed=[];
$check=static function(string $case,bool $ok)use(&$checks,&$failed):void{
 $checks[$case]=$ok;if(!$ok)$failed[]=$case;
};
$parse=static function(int $v,string $raw)use($build,&$paths):array{
 $p=tempnam(sys_get_temp_dir(),'ue1level');
 if(!$p)throw new RuntimeException('Cannot create fixture');
 $paths[]=$p;file_put_contents($p,$build($v,$raw));
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Invalid synthetic UE1 package');
 $props=Tag::inspect($p,$pkg,0);
 if(($props['status']??'')!=='tag_envelope_decoded')throw new RuntimeException('Missing decoded UObject properties');
 return [Native::inspect($p,$pkg,0,$props),$props];
};
$reject=static function(int $v,string $raw)use($parse):bool{
 try{$parse($v,$raw);return false;}catch(RuntimeException){return true;}
};
try{
 foreach([35,50,53,54,58,59,60,61,62,63,64,69] as $v){
  [$rec]=$parse($v,$source($v));
  $ok=$rec['status']==='native_level_record_complete'
    &&count($rec['reach_specs'])===1
    &&$rec['reach_specs'][0]['distance']===123
    &&$rec['reach_specs'][0]['start_reference']===-1
    &&$rec['reach_specs'][0]['end_reference']===1
    &&count($rec['text_blocks'])===16
    &&$rec['text_blocks'][0]===-1
    &&$rec['model_reference']===-1
    &&$rec['time_seconds']===17.5;
  $check('source_level_v'.$v,$ok);
 }
 [$a]=$parse(35,$source(35));
 $check('v35_legacy_float_reach_spec',$a['reach_specs'][0]['collision_radius']===12.5
    &&$a['reach_specs'][0]['collision_height']===26.5
    &&$a['reach_specs'][0]['pruned']===null&&$a['url']===null);
 [$a]=$parse(54,$source(54));
 $check('v54_integer_reach_spec_and_pruned',
    $a['reach_specs'][0]['collision_radius']===12
    &&$a['reach_specs'][0]['pruned']===1);
 [$a]=$parse(59,$source(59));
 $check('v59_url_added_not_actor_table',
    $a['url']['protocol']['value']==='unreal'
    &&!isset($a['actor_database'])
    &&$a['url']['options'][1]['value']==='SpectatorOnly');
 [$a]=$parse(61,$source(61));
 $check('v61_old_travel_names_and_items',
    $a['travel_names'][0]['value']==='LevelName'
    &&$a['travel_items'][0]['value']==='Deck16');
 [$a]=$parse(62,$source(62));
 $check('v62_ut_actor_db_and_travel_arrays',
    $a['actor_database']['count']===2
    &&$a['actor_database']['actors']===[-1,1]
    &&$a['url']['port']===7777
    &&$a['travel_names'][1]['value']==='Player');
 [$a]=$parse(63,$source(63));
 $check('v63_travel_TMap_pairs_source_order',
    $a['travel_info_pairs'][0]['key']['value']==='LevelName'
    &&$a['travel_info_pairs'][0]['value']['value']==='Deck16');
 [$a]=$parse(69,$source(69));
 $check('v69_utf16_travel_pair',
    $a['travel_info_pairs'][1]['value']['value']==='Unreal'
    &&$a['travel_info_pairs'][1]['value']['encoding']==='UTF-16LE');
 [$a]=$parse(69,$source(69,['extension'=>"\xff"]));
 $check('unknown_extension_not_accepted',$a['status']==='native_level_has_extension'
    &&$a['extension_bytes']===1);
 $check('bad_model_ref',$reject(59,$source(59,['modelRef'=>-2])));
 $check('bad_reach_start_ref',$reject(50,str_replace($idx(-1),$idx(-2),$source(50))));
 $check('bad_actor_capacity',$reject(69,$source(69,['actorMax'=>1])));
 $check('negative_actor_num',$reject(69,$source(69,['badActorCount'=>-1])));
 $check('reach_array_huge',$reject(69,$source(69,['reach'=>999])));
 $check('url_unicode_before_v64',$reject(59,$source(59,['badUrlWide'=>true])));
 $check('truncated_16_textblocks',$reject(50,substr($source(50),0,-1)));
 $check('bad_firstdeleted_ref',$reject(50,$source(50,['firstDeleted'=>2])));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failed'=>$failed,'checks'=>$checks,
 'sources'=>['Unreal v1.200 Engine/Src/UnLevel.cpp:20,148; UnReach.h:40-64; UnURL.cpp:46',
  'UT99 v400 Engine/Src/UnLevel.cpp:22,186; UnReach.h:49-56; UnURL.cpp:62; Core/Inc/UnTemplate.h:1340,1499']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
