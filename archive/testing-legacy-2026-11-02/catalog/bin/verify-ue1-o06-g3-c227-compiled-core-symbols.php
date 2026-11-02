<?php
/** G3 ORIGINAL C227 compiled import surface ONLY; not a loader oracle. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$c=$root.'/catalog/compliance';
$e=json_decode((string)file_get_contents($c.'/UE1-O06-G3-C227-COMPILED-CORE-SYMBOLS.json'),true,512,JSON_THROW_ON_ERROR);
$reg=json_decode((string)file_get_contents($c.'/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$cnt=0;$fail=[];$check=static function(string $id,bool $pass)use(&$cnt,&$fail){++$cnt;if(!$pass)$fail[]=$id;};
$f=$e['pinned_227_Core_lib']['path'];
$h=$e['pinned_227_UnType_header']['path'];
$lib=is_file($f)?file_get_contents($f):false;
$header=is_file($h)?file_get_contents($h):false;
$check('C227 original compiled Core.lib SHA',is_string($lib)
    &&strlen($lib)===$e['pinned_227_Core_lib']['bytes']
    &&hash('sha256',$lib)===$e['pinned_227_Core_lib']['sha256']);
$check('C227 original Core UnType.h SHA',is_string($header)
    &&hash('sha256',$header)===$e['pinned_227_UnType_header']['sha256']);
$all=$e['G3_relevant_exported_decorated_symbols'];
$check('57 original C227 Core imported methods',count($all)===57);
$check('25 distinct original C227 Core classes',$e['G3_relevant_distinct_class_count']===25);
$names=[];$seen=[];
foreach($all as $v){
  $cl=$v['class'];$method=$v['method'];
  $key=$cl.'::'.$method;
  $check('compiled library exported '.$key,
    is_string($lib)&&str_contains($lib,$v['decorated']));
  $check('C227 scoped serializer name '.$key,
    preg_match('/^U[A-Za-z0-9_]+$/',$cl)===1
    &&in_array($method,['Serialize','SerializeItem','NetSerializeItem','ShouldSerializeItem',
                         'SerializeBin','SerializeExpr','SerializeTaggedProperties'],true));
  $check('unregistered C227 Core '.$key,
    !array_filter($reg['entries'],static fn($x)=>$x['branch']==='C227'
         &&$x['symbol']===$key));
  $check('no duplicate C227 symbol '.$key,!isset($seen[$v['decorated']]));
  $seen[$v['decorated']]=true;$names[$cl]=true;
}
$check('all 25 classes accounted for',count($names)===25
    &&array_keys($names)!==[]);
$methods=[
  'UPointerProperty'=>'NAME_IntProperty',
  'UButtonProperty'=>'NAME_IntProperty',
  'UAnyProperty'=>'NAME_VectorProperty'];
foreach($methods as $class=>$id){
  $start=strpos((string)$header,'class CORE_API '.$class.' : public UProperty');
  $end=$start!==false?strpos((string)$header,"\n};",$start):false;
  $body=($start!==false&&$end!==false)?substr($header,$start,$end-$start):'';
  $check('native GetID '.$class,$e['new_227_licensee_derived_properties'][$class]['GetID']===$id
      &&preg_match('/BYTE GetID\(\) const\s*\{\s*return '.$id.'\s*;/s',$body)===1);
  $check('native distinct SerializeItem '.$class,
      $e['new_227_licensee_derived_properties'][$class]['source_has_explicit_SerializeItem']
      &&str_contains($body,'void SerializeItem('));
  $check('cannot infer loaded bytes '.$class,
      !$e['C227_original_Core_Src_UnClass_UnProp_available']
      &&$e['does_not_prove_function_body_or_actual_loaded_value']);
}
$check('C227 Core CPP still absent',
  !is_file(dirname(dirname($h)).'/Src/UnClass.cpp')
  &&!is_file(dirname(dirname($h)).'/Src/UnProp.cpp'));
$check('import surface not native runtime parity',
    $e['G3_contract_status']==='SOURCE_SYMBOL_DECLARATION_VERIFIED_IMPLEMENTATION_AND_ORACLE_OPEN');
$ok=!$fail;
echo json_encode(['ok'=>$ok,'checks'=>$cnt,'passed'=>$cnt-count($fail),
  'failed'=>$fail,'scope'=>'C227 Core.lib authentic compiled IMPORT SYMBOL inventory and original header-only property GetID aliases',
  'method_symbols'=>$e['G3_relevant_exported_symbol_count'],
  'classes'=>$e['G3_relevant_distinct_class_count'],
  'new_source_defined_raw_type_aliases'=>$methods,
  'C227_native_loaded_value_proven'=>false,
  'C227_serialization_CPP_source_available'=>false,
  'G3_full_gate_decision'=>'OPEN',
  'previous_24_historical_CLASSIC_tests_executed'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
