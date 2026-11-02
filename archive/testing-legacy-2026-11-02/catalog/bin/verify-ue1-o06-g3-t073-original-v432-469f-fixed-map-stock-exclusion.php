<?php
/** G3 T073 genuine original compiled v432 / 469f FixedArray/Map archive field order and 469f stock physical scope. */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicPropertyNativeReader as Property;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
$m=json_decode((string)file_get_contents(
 $repo.'/catalog/compliance/evidence/o06/20261101-G3-T073-469f-v432-original-fixed-map-stock-exclusion-manifest.json'
),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];
$ck=static function(bool $ok,string $name)use(&$checks,&$fail):void{
    $checks++;if(!$ok&&count($fail)<25)$fail[]=$name;
};
foreach($m['pins'] as $name=>$pin){
 $path=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
 $exists=is_file($path);
 $size=$exists?filesize($path):-1;
 $sha=$exists?hash_file('sha256',$path):null;
 $real=$sha===$pin['sha256']&&$size===$pin['bytes'];
 $canonical=isset($pin['canonical_git_blob_sha256'])
    &&$sha===$pin['canonical_git_blob_sha256']
    &&$size===$pin['canonical_git_blob_bytes'];
 $ck($exists&&($real||$canonical),'SHA: '.$name);
}
$code469=(string)file_get_contents($repo.'/'.$m['pins']['original_compiled_469f_FixedMap_disassembly']['path']);
$code432=(string)file_get_contents($repo.'/'.$m['pins']['original_compiled_v432_FixedMap_disassembly']['path']);
foreach(['UFixedArrayProperty','UMapProperty'] as $type){
 $ck(str_contains($code469,'Core!'.$type.'::Serialize:')
    &&str_contains($code432,'Core!'.$type.'::Serialize:'),
    'both compiled original engines contain '.$type.' Serialize');
}
preg_match('/Core!UFixedArrayProperty::Serialize:\r?\n(?<body>.*?)Core!UMapProperty::Serialize:/s',$code469,$a);
preg_match('/Core!UFixedArrayProperty::Serialize:\r?\n(?<body>.*?)Core!UMapProperty::Serialize:/s',$code432,$b);
$ck(!empty($a['body'])&&!empty($b['body']),'both original native fixed methods disassembled');
$ck(str_contains($a['body'],'call    Core!UProperty::Serialize')
    &&str_contains($a['body'],'lea     eax,[edi+5Ch]')
    &&str_contains($a['body'],'lea     ecx,[edi+60h]'),
    'compiled original 469f FixedArray inherits Property then Inner+0x5c, Count+0x60');
$ck(str_contains($b['body'],'call    Core!UProperty::Serialize')
    &&str_contains($b['body'],'lea     ecx,[esi+5Ch]')
    &&str_contains($b['body'],'add     esi,60h'),
    'compiled original v432 FixedArray inherits Property then Inner+0x5c, Count+0x60');
preg_match('/Core!UMapProperty::Serialize:\r?\n(?<body>.*?)quit:/s',$code469,$a);
preg_match('/Core!UMapProperty::Serialize:\r?\n(?<body>.*?)quit:/s',$code432,$b);
$ck(!empty($a['body'])&&!empty($b['body']),'both compiled original Map methods');
$ck(str_contains($a['body'],'call    Core!UProperty::Serialize')
    &&str_contains($a['body'],'lea     eax,[edi+5Ch]')
    &&str_contains($a['body'],'lea     ecx,[edi+60h]'),
    'compiled original 469f Map inherited Property Key+0x5c Value+0x60');
$ck(str_contains($b['body'],'call    Core!UProperty::Serialize')
    &&str_contains($b['body'],'lea     ecx,[esi+5Ch]')
    &&str_contains($b['body'],'add     esi,60h'),
    'compiled original v432 Map inherited Property Key+0x5c Value+0x60');

$scan=json_decode((string)file_get_contents(
    $repo.'/'.$m['pins']['original_469f_real_24_package_type_inventory']['path']),
    true,512,JSON_THROW_ON_ERROR);
$ck($scan['real_stock_469f_original_system_packages']===24
    &&count($scan['full_genuine_system_corpus'])===24,
    'actual stock 469f original physical System .u is exactly 24 packages');
$expc=0;$files=[];
foreach($scan['full_genuine_system_corpus'] as $record){
 $name=$record['package'];$files[$name]=true;$expc+=$record['exports']??0;
 $physical=$m['original_469f_stock_system_directory'].'/'.$name;
 $expected=$m['actual_stock_469f_genuine_package_SHA'][$name]??null;
 $ck(is_array($expected)&&is_file($physical)
    &&filesize($physical)===$expected['bytes']
    &&hash_file('sha256',$physical)===$expected['sha256'],
    'actual immutable stock 469f original package SHA '.$name);
 $ck(isset($m['actual_stock_469f_genuine_package_SHA'][$name])
    &&($record['fixedarrayproperty']??-1)===0
    &&($record['mapproperty']??-1)===0
    &&!isset($record['error']),
    'direct exported Fixed/Map genuinely absent from 469f stock '.$name);
}
$ck($expc===49983&&count($files)===24,
    'all 49,983 original 469f exported object type classifications in stock inventory');
$ck(($scan['source_specific_property_types']['ArrayProperty']??null)===28
    &&($scan['source_specific_property_types']['PointerProperty']??null)===31,
    'observed actual 469f supported subclasses make inventory nonempty');
$ck($m['no_genuine_stock_FixedArray_or_Map_direct_export']===true,
    'stock loaded-value exclusion is bounded to these shipped files; not global');
$ck($m['full_T073_pass']===false,'do not promote all T073 tests without other source oracles');

$file=$m['pins']['original_469f_Engine_u']['path'];$pkg=new P($file);
$exp=$pkg->getExports()[5627];$p=T::inspect($file,$pkg,5627,Source::UT99_V469F);
$offset=$exp['serialOffset']+$exp['serialSize']-$p['native_tail_bytes'];
$stream=fopen($file,'rb');fseek($stream,$offset);$original=fread($stream,$p['native_tail_bytes']);fclose($stream);
$ck(strlen($original)===15&&$p['native_tail_bytes']===15,
    'real original Engine.Touching native inherited-property prefix 15 bytes');
$readAs=static function(string $kind,string $bytes,string $profile)use($pkg):array{
 $pos=0;$limit=strlen($bytes);
 $read=static function(int $n)use($bytes,$limit,&$pos):string{
    if($n<0||$pos+$n>$limit)throw new RuntimeException('synthetic native field truncated');
    $raw=substr($bytes,$pos,$n);$pos+=$n;return $raw;
 };
 $byte=static fn():int=>ord($read(1));
 $compact=static function()use($byte):int{
  $f=$byte();$neg=($f&128)!==0;$v=$f&63;$more=($f&64)!==0;$shift=6;$n=1;
  while($more){
   if(++$n>5)throw new RuntimeException('compact overflow');
   $next=$byte();$v|=($next&127)<<$shift;$more=($next&128)!==0;$shift+=7;
  }
  return $neg?-$v:$v;
 };
 $u32=static fn():int=>(int)unpack('V',$read(4))[1];
 $result=Property::inspect($kind,$pkg,69,$read,$compact,$u32,$pos,$limit,$profile);
 return [$result,$pos];
};
foreach([Source::UT99_V432,Source::UT99_V469F] as $family){
 [$fx,$fpos]=$readAs('fixedarrayproperty',$original.pack('V',4),$family);
 $ck($fpos===19&&$fx['status']==='native_property_record_complete','source '.$family.' fixed-array exact 19-byte archive field count');
 $ck(($fx['subclass_fields']['inner_reference']??null)===5627
    &&($fx['subclass_fields']['fixed_count']??null)===4,
    'source '.$family.' compiled inherited FixedArray serial field order and value');
 [$map,$mpos]=$readAs('mapproperty',$original.chr(1),$family);
 $ck($mpos===16&&$map['status']==='native_property_record_complete',
    'source '.$family.' map exact 16-byte archive field count');
 $ck(($map['subclass_fields']['key_reference']??null)===5627
    &&($map['subclass_fields']['value_reference']??null)===1,
    'source '.$family.' compiled inherited Map key then value saved compact refs');
 [$negative,$npos]=$readAs('fixedarrayproperty',$original.pack('V',0xFFFFFFFF),$family);
 $ck($npos===19&&$negative['subclass_fields']['fixed_count']===-1,
    'source '.$family.' fixed array signed 32bit count native grammar');
 foreach(['fixedarrayproperty'=>$original.str_repeat("\x00",3),
          'mapproperty'=>$original] as $kind=>$bad){
  try{$readAs($kind,$bad,$family);$ck(false,$family.' truncated '.$kind.' accepted');}
  catch(RuntimeException $e){$ck(str_contains($e->getMessage(),'truncated'),$family.' truncated '.$kind.' rejected');}
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,
 'genuine_stock_469f_u_files'=>24,'genuine_stock_direct_exports'=>49983,
 'original_compiled_469f_and_v432_fixed_map_source_field_order'=>true,
 'genuine_stock_FixedArray_and_Map_direct_instances'=>0,
 'synthetic_archive_does_not_claim_original_native_load'=>true,
 'full_T073_pass'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
