#!/usr/bin/env php
<?php
/** Epic v0.867 UnMesh.h, UnMath.h and UnObj.h native record serializers. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileDatabaseDataReader as DB;
$tests=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$tests,&$failed):void{
    $tests[$name]=$ok;if(!$ok)$failed[]=$name;
};
$cases=[
 'MeshVerts'=>[pack('V',1|(2046<<11)|(3<<22)),4],
 'MeshTris'=>[pack('v3C2C6C2',1,2,3,4,5,6,7,8,9,10,11,12,13),16],
 'MeshAnimSeqs'=>[pack('v5g',3,2,4,5,6,1.5),14],
 'MeshAnimNotifys'=>[pack('gv',0.5,4),6],
 'MeshVertConnects'=>[pack('V2',2,9),8],
 'Bounds'=>[pack('g6C',-1.0,-2.0,-3.0,1.0,2.0,3.0,1),25],
 'Palette'=>[pack('C4',10,20,30,40),4],
];
$make=static function(int $version,string $class,string $payload):array{
 $names='';
 foreach([$class,'Instance','AnimName','FuncName'] as $n)$names.=$n."\0".pack('V',0);
 $nameOff=56;$stack=pack('V2',0,0).str_repeat("\xff",8);
 $hdr=$stack.pack('V2',1,1);$hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($hdr);$tableOff=$dataOff+strlen($payload);
 $objects=pack('vvVV',2,1,0,$hOff)
  .pack('V4',0,$dataOff,strlen($hdr),strlen($payload));
 $summary=pack('a32V6',"Unrealfile\x1A",$version,1,4,$nameOff,$tableOff,0);
 return ['bytes'=>$summary.$names.$hdr.$payload.$objects,
  'data'=>$dataOff,'header'=>$hOff+16,'table'=>$tableOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1meshdb');
if(!is_string($tmp))throw new RuntimeException('No temp fixture');
try{
 foreach([22,23] as $version)foreach($cases as $class=>[$data,$stride]){
  if(strlen($data)!==$stride)throw new RuntimeException('Bad source stride '.$class);
  $f=$make($version,$class,$data);file_put_contents($tmp,$f['bytes']);
  $r=DB::read($tmp,true)['database_records'][0]??[];
  $v=$r['record_values'][0]??null;$prefix="v{$version}_{$class}";
  $check($prefix.'_source_record_contract',
    ($r['native_class']??null)==='U'.$class
    && ($r['status']??null)==='native_database_records_decoded'
    && ($r['serialized_record_bytes']??null)===$stride
    && ($r['active_count']??null)===1
    && ($r['capacity']??null)===1
    && ($r['runtime_references_resolved']??true)===false);
  $expected=match($class){
   'MeshVerts'=>is_array($v)&&$v===['x'=>1,'y'=>-2,'z'=>3],
   'MeshTris'=>is_array($v)&&$v['vertex_indices']===[1,2,3]
    && $v['texture_uv_bytes']===[6,7,8,9,10,11]&&$v['flags']===13,
   'MeshAnimSeqs'=>is_array($v)&&$v['name']==='AnimName'
    && $v['num_frames']===4&&$v['num_notifies']===6&&$v['rate']===1.5,
   'MeshAnimNotifys'=>is_array($v)&&$v['time']===0.5
    && $v['function_name']==='FuncName',
   'MeshVertConnects'=>is_array($v)
    && $v['num_vertex_triangles']===2&&$v['triangle_list_offset']===9,
   'Bounds'=>is_array($v)&&$v['min']===[-1.0,-2.0,-3.0]
    && $v['max']===[1.0,2.0,3.0]&&$v['is_valid']===1,
   'Palette'=>is_array($v)&&$v===['r'=>10,'g'=>20,'b'=>30,'remap_index'=>40],
  };
  $check($prefix.'_typed_values',$expected);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unrealfile','version'=>$version]
  );
  $stored=$stage['native_metadata']['unrealfile_database_records']['database_records'][0]??[];
  $check($prefix.'_unverified_staging',
    ($stored['active_count']??null)===1
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header'],4));
  try{DB::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
  $check($prefix.'_impossible_count_rejected',$rejected);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',$stride+1),$f['table']+24,4));
  try{DB::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
  $check($prefix.'_record_length_mismatch_rejected',$rejected);
  if($class==='MeshAnimSeqs'||$class==='MeshAnimNotifys'){
   $offset=$f['data']+($class==='MeshAnimSeqs'?0:4);
   file_put_contents($tmp,substr_replace($f['bytes'],pack('v',5),$offset,2));
   try{DB::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
   $check($prefix.'_invalid_name_reference_rejected',$rejected);
  }
  if($class==='Bounds'){
   file_put_contents($tmp,substr_replace($f['bytes'],chr(2),$f['data']+24,1));
   try{DB::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
   $check($prefix.'_invalid_bbox_flag_rejected',$rejected);
  }
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($tests)-count($failed),
 'total'=>count($tests),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
