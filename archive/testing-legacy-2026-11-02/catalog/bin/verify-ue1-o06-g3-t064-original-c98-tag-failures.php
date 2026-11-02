<?php
/** Original compiled C98 native T064 subset: malformed class-default tag handling.
 * Native original x86 engine executions were completed ONCE in C:/Temp,
 * and their independently captured Editor.log files are SHA-pinned here.
 * This evaluator reuses those originals without executing CDB, Unreal.exe,
 * or previously accepted tests.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$e=$repo.'/catalog/compliance/evidence/o06/';
$original=(string)file_get_contents($e.'20261031-G3-B08-T069-C98-original-compiled-G3C98Defaults-761.u');
$checks=0;$fail=[];
$ck=static function(bool $good,string $label)use(&$checks,&$fail):void {
 ++$checks;if(!$good)$fail[]=$label;
};
$ck(strlen($original)===761&&hash('sha256',$original)==='766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf','pristine genuine original 1998 UCC-compiled v61 class fixture SHA');
$native='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/';
$ck(hash_file('sha256',$native.'Core.dll')==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92','original compiled May17 C98 Core.dll SHA');
$ck(hash_file('sha256',$native.'G3C98Defaults.u')===hash('sha256',$original),'original native fixture restored and byte identical');
$cases=[
 ['type15-unknown',362,47,'be024d5bb7213537375ceb9360cc9a6db2309b26b213adf203750c282ed94db5',
   '4bf99d5e9baad978a983f2c9541674b43021621a61525d15f2d40a1dc9621e2e',
   'Warning: Type mismatch in G3Magic of G3C98Parent: file 15, class 2',null],
 ['array123-bounds',362,162,'275a3e56e82bc0c4399b09a287219447c57bc68348b85effa50ed129ce964e46',
   '4dbf92173d03cc1834245414b1cbc41aec0e3127bc7fb7e9d36feed08fee0741',
   'Warning: Array bounds in G3Magic of G3C98Parent: 123/1','UE1 Bad name index 36/16 at archive cursor 369'],
 ['missing-terminator-knownname',373,7,'5ed61dc690bcfdc0de869990102aa9883d62742eeca8130150f025c124512aab',
   'eb97581abfc9e8e50bfef1cc78fb01982e2cdd42ed67381e29bd359577eb03dd',
   'Critical: Bad name index -8191/16','UE1 Bad name index -8191/16 at archive cursor 382']
];
foreach($cases as [$id,$offset,$value,$fixtureSha,$logSha,$nativeNeedle,$expectedError]){
 $prefix='20261031-G3-T064-C98-original-'.$id;
 $f=$e.$prefix.'.u';$l=$e.$prefix.'-Editor.log';
 $ck(is_file($f)&&hash_file('sha256',$f)===$fixtureSha,'original one-byte class fixture pinned '.$id);
 $ck(is_file($l)&&hash_file('sha256',$l)===$logSha,'actual 1998 original compiled engine native log pinned '.$id);
 $bytes=(string)file_get_contents($f);
 $delta=[];if(strlen($bytes)===strlen($original)){
   for($i=0;$i<strlen($original);++$i)if($bytes[$i]!==$original[$i])$delta[]=$i;
 }
 $ck(strlen($bytes)===761&&$delta===[$offset]&&ord($bytes[$offset])===$value,'precisely one archive byte mutated from real original 1998 UCC compiler '.$id);
 $log=(string)file_get_contents($l);
 $ck(str_contains($log,'Init: Compiled: May 17 1998')&&str_contains($log,'Bound to Core.dll')&&str_contains($log,$nativeNeedle),'genuine original 1998 native UStruct class load and warning/failure '.$id);
 $p=new P($f);$h=$p->getHeader();
 $ck($h['version']===61&&$h['licensee']===0&&count($p->getNames())===16&&count($p->getImports())===7&&count($p->getExports())===6,'original linker map and selected C98 profile '.$id);
 $got=null;$metadata=null;try{$metadata=C::inspect($f,$p,0,true,'epic-ue1');}catch(Throwable $x){$got=$x->getMessage();}
 if($expectedError!==null){
  $ck($got===$expectedError,'reader matches original native source family error and physical FArchive Tell '.$id.' got:'.$got);
  $nativeText=$id==='array123-bounds'?'Bad name index 36/16':'Bad name index -8191/16';
  $nativeTell=$id==='array123-bounds'?'LinkerLoad4 369 ':'LinkerLoad4 382 ';
  $ck(str_contains($log,$nativeText)&&str_contains($log,$nativeTell)&&str_contains($log,'UStruct::SerializeTaggedProperties'),'original compiled C98 error and physical native linker cursor '.$id);
 }else{
  $details=$metadata['default_property_declaration_validation']??[];
  $ck($got===null&&($details['status']??null)==='known_incompatible_tags_skipped'
      &&($details['known_incompatible_tags_skipped']??null)===1,'native tolerated mismatched tag and reader preserves skip/not-fabricated-value '.$id);
  $ck(($metadata['defaults_tag_count']??0)===2
      &&($metadata['default_property_tags'][1]['name']??null)==='G3Scale'
      &&($metadata['default_property_tags'][1]['value']??null)===1.25,'original native source type mismatch skips exactly 4 bytes then reads following native Float tag');
 }
}
$out=['ok'=>$fail===[],'checks'=>$checks,'errors'=>$fail,
 'fixed_T064'=>'PARTIAL_C98_ONLY_NOT_FULL_PASS',
 'original_compiled_native_engine'=>'May17-1998 retail C98 x86 Core.dll',
 'original_compiled_cases'=>['type15_mismatch_recovered'=>'Warning file15/class2',
    'array123_bounds_then_bad_index'=>'123/1; Bad name36/16 Tell369',
    'missing_None_cross_export'=>'Bad name-8191/16 Tell382'],
 'original_engines_reexecuted_by_verifier'=>0,'reader_compiled_source_behavior_corrected'=>true,
 'remaining_to_close_T064'=>'source-defined distinct property-tag error branches for stock UT99 v432 and Unreal C227; native loaded/rejected values and exact physical cursor'];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
