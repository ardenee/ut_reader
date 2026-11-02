#!/usr/bin/env php
<?php
/** G5 focused genuine UT99 v432 UFont archive compatibility.
 * SHA-pinned 34 real v68/v69 font exports against compiled archive field contract.
 * Not a native in-memory value oracle; does not establish full G5 acceptance.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root='H:/Epic Games/UnrealTournament/';
$files=[
 'System/BotPack.u'=>[69,5,'101127c7025bdbfd1a0d6fc5856ed93784e7f1da653b9b108c7ed3638acd20c7'],
 'System/Engine.u'=>[69,4,'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 'System/UnrealShare.u'=>[68,5,'d75a7a5a4dbdcbcbfc363eadae810f99dfc4030c203032ed2dc88d255811155d'],
 'Textures/LadderFonts.utx'=>[68,10,'f9bfa74c37f7be1b3ccbaa5749f109d6f6fa5485c14113082304232e2912cd1b'],
 'Textures/Scripted.utx'=>[68,1,'781d877b695c6bfea6426e01c64afda903a05127ad7da69aef24b27fca73baea'],
 'Textures/UWindowFonts.utx'=>[68,9,'91ac2a325d361f46948ebbbda5cf5431add4930e7466667f0fa36819606642c7'],
];
$nativeDll='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll';
$failed=[];$cases=0;$complete=0;$v69=0;$v68=0;$zeroMap=0;$exceptionCount=0;$records=[];
$check=static function(string $what,bool $ok)use(&$failed,&$cases):void{
 $cases++; if(!$ok)$failed[]=$what;
};
$check('compiled_engine_dll_sha',is_file($nativeDll)
 &&hash_file('sha256',$nativeDll)==='f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac');
$contract=json_decode((string)file_get_contents(dirname(__DIR__).'/compliance/UE1-UT99-V432-COMPILED-SERIALIZERS.json'),true,512,JSON_THROW_ON_ERROR);
$font=$contract['native_methods']['UFont::Serialize']??[];
$check('native_compiled_version_gate_69',($font['version_gate']??null)===69);
$check('native_compiled_font_method_846D0',($font['compiled_method_rva']??null)==='0x846D0');
$check('native_compiled_tmap_pair_84D00',($font['compiled_char_remap_tmap_rva']??null)==='0x84D00');
foreach($files as $relative=>[$version,$wanted,$sha]){
 $path=$root.$relative;
 $hashOk=is_file($path)&&hash_file('sha256',$path)===$sha;
 $check('file_sha_'.basename($path),$hashOk);
 if(!$hashOk)continue;
 $p=new P($path);
 $check('no_structural_errors_'.basename($path),$p->validatePackage()===[]);
 $check('version_'.basename($path),(int)$p->getHeader()['version']===$version);
 $im=$p->getImports();$ex=$p->getExports();$found=0;
 foreach($ex as $e){
  $idx=(int)($e['classIndex']??0);
  $class=$idx<0?(string)($im[-$idx-1]['objectNameText']??''):
     ($idx>0?(string)($ex[$idx-1]['objectNameText']??''):'Class');
  if(strcasecmp($class,'Font')!==0)continue;
  $found++;
  try{
   $tag=T::inspect($path,$p,(int)$e['index'],'ut99-v432');
   $v=N::inspect($path,$p,(int)$e['index'],$tag,'ut99-v432');
   $ok=($tag['status']??null)==='tag_envelope_decoded'
     &&($v['status']??null)==='native_font_record_complete'
     &&($v['extension_bytes']??-1)===0
     &&($v['page_count']??0)>0
     &&count($v['pages']??[])===$v['page_count']
     &&($v['characters_per_page']??0)>0;
   if($version===69){
    $v69++;
    $ok=$ok&&($v['source_v432_font_remap_archived']??null)===true
      &&($v['char_remap']['count']??-1)===0
      &&($v['char_remap']['archive_data_bytes']??-1)===0
      &&($v['is_remapped_raw']??-1)===0;
    if(($v['char_remap']['count']??-1)===0)$zeroMap++;
   }else{
    $v68++;
    $ok=$ok&&($v['source_v432_font_remap_archived']??null)===false
      &&($v['source_is_remapped_zeroed_on_legacy_load']??null)===true
      &&array_key_exists('char_remap',$v)&&$v['char_remap']===null
      &&($v['is_remapped_raw']??-1)===0;
   }
   $check('genuine_font_'.basename($path).'_'.(int)$e['index'],$ok);
   if($ok)$complete++;
  }catch(Throwable $t){
   $exceptionCount++;$check('exception_'.basename($path).'_'.(int)$e['index'],false);
   if(count($records)<10)$records[]=['file'=>basename($path),'index'=>$e['index'],'error'=>$t->getMessage()];
  }
 }
 $check('font_count_'.basename($path),$found===$wanted);
}
$check('all_34_genuine_fonts_complete',$complete===34);
$check('nine_real_v69_font_remap_tails',$v69===9&&$zeroMap===9);
$check('twentyfive_real_v68_legacy_fonts',$v68===25);
$check('no_font_reader_exceptions',$exceptionCount===0);
echo json_encode(['ok'=>$failed===[],'checks'=>$cases,'passed'=>$cases-count($failed),
 'failed'=>$failed,'file_count'=>count($files),'font_complete'=>$complete,
 'v69_with_empty_remap'=>$zeroMap,'v68_legacy'=>$v68,
 'exceptions'=>$exceptionCount,'errors'=>$records,
 'limits'=>'Actual compiled method/archive evidence plus genuine on-disk fields; NOT compiled native in-memory loaded-value or reference equality.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
