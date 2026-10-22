#!/usr/bin/env php
<?php
/**
 * Executable Epic source -> UnrealDB serializer token switch inventory.
 * Intended to fail if any Epic SerializeExpr source case or range is
 * missing from PHP. Tests coverage of dispatch, not compiled UE execution.
 */
declare(strict_types=1);
$root='L:/Source/Games/Unreal/Unreal [v0.867] [1997-07-25]/Src';
$sourceFile=$root.'/Engine/UnScript.cpp';
$headerFile=$root.'/Inc/UnScript.h';
$readerFile=dirname(__DIR__).'/src/Infrastructure/Readers/CatalogUE1OriginalUnrealfileScriptExpressionReader.php';
$tests=[];$fail=[];
$check=static function(string $name,bool $yes)use(&$tests,&$fail):void{
    $tests[$name]=$yes;if(!$yes)$fail[]=$name;
};
$source=@file_get_contents($sourceFile);
$header=@file_get_contents($headerFile);
$php=@file_get_contents($readerFile);
if(!is_string($source)||!is_string($header)||!is_string($php)){
    fwrite(STDERR,"Original v0.867 Epic source or UnrealDB reader unavailable\n");
    exit(2);
}
$start=strpos($source,'static EExprToken SerializeExpr(');
$end=strpos($source,'void UScript::SerializeData(',($start===false?0:$start));
$check('epic_serializer_body_found',$start!==false&&$end!==false&&$end>$start);
if($start===false||$end===false||$end<=$start){
    echo json_encode(['ok'=>false,'failures'=>$fail]),PHP_EOL;exit(1);
}
$body=substr($source,$start,$end-$start);
preg_match_all('/\b(EX_[A-Za-z0-9_]+)\s*=\s*0x([a-fA-F0-9]+)/',$header,$enums,PREG_SET_ORDER);
$names=[];
foreach($enums as $m)$names[$m[1]]=hexdec($m[2]);
preg_match_all('/\bcase\s+(EX_[A-Za-z0-9_]+)\s*:/',$body,$sourceCases,PREG_SET_ORDER);
preg_match_all('/\bcase\s+0x([a-fA-F0-9]+)\s*:/',$php,$phpCases,PREG_SET_ORDER);
$covered=[];
foreach($phpCases as $m)$covered[hexdec($m[1])]=true;
$unmapped=[];$missing=[];
foreach($sourceCases as $m){
    $name=$m[1];
    if(!isset($names[$name]))$unmapped[$name]=true;
    elseif(!isset($covered[$names[$name]]))$missing[$name]=sprintf('0x%02X',$names[$name]);
}
$check('all_source_case_names_in_epic_enum',$unmapped===[]);
$check('every_epic_case_dispatched_by_php',$missing===[]);
$check('distinct_explicit_epic_cases_covered',count($sourceCases)>=40&&count($covered)>=40);
$required=[
    'EX_MinConversion'=>0x38,'EX_MaxConversion'=>0x5a,
    'EX_ExtendedIntrinsic'=>0x60,'EX_FirstIntrinsic'=>0x70,
    'EX_FinalFunction'=>0x1c,'EX_BoolVariable'=>0x2d,
    'EX_EndFunctionParms'=>0x16
];
foreach($required as $name=>$value)$check('source_enum_'.$name,($names[$name]??null)===$value);
$check('epic_conversion_range_exists',
    str_contains($body,'Expr >= EX_MinConversion && Expr < EX_MaxConversion'));
$check('php_conversion_range_matches',
    (bool)preg_match('/\$token\s*>=\s*0x38\s*&&\s*\$token\s*<\s*0x5a/',$php));
$check('epic_intrinsic_order_uses_normal_then_extended',
    strpos($body,'Expr >= EX_FirstIntrinsic')<strpos($body,'Expr >= EX_ExtendedIntrinsic'));
$check('php_intrinsic_ranges_match',
    (bool)preg_match('/\$token\s*>=\s*0x70/',$php)
    &&(bool)preg_match('/\$token\s*>=\s*0x60/',$php));
$check('source_final_function_uses_stack_node_ptr',
    (bool)preg_match('/case\s+EX_FinalFunction\s*:[\s\S]*?FStackNodePtr[\s\S]*?break\s*;/',$body));
$check('source_stack_node_ptr_conditional_index',
    (bool)preg_match('/if\s*\(\s*Link\.Class\s*\)\s*Ar\s*<<\s*Link\.iNode/',$header));
$check('php_nullable_final_function_index',
    (bool)preg_match('/\$fields\[.stack_node_index.\][\s\S]{0,100}class_reference_raw[\s\S]{0,70}===\s*0\s*\?\s*null\s*:\s*self::int/',$php));
$check('source_bool_variable_selector_guard',
    (bool)preg_match('/case\s+EX_BoolVariable\s*:[\s\S]*?checkState\s*\(\s*Expr==EX_LocalVariable/',$body));
$check('php_bool_variable_bin_guard',
    (bool)preg_match('/\$bin\s*=\s*\$fields\[.bool_variable_selector.\]\s*>>\s*5/',$php)
    &&str_contains($php,'in_array($bin,[0,1,2],true)'));
$check('source_checked_label_table_alignment',
    str_contains($body,'checkState((iCode&3)==0)'));
$check('php_checked_label_table_alignment',
    str_contains($php,'($start+1)%4!==0'));
$check('source_recurses_until_end_params',
    (bool)preg_match('/while\s*\(\s*SerializeExpr\(\s*Script\s*,\s*iCode\s*,\s*Ar\s*\)\s*!=\s*EX_EndFunctionParms\s*\)/',$body));
$check('php_recurses_until_end_params',
    str_contains($php,"}while(\$sub['token']!==0x16)"));
echo json_encode([
 'ok'=>$fail===[],'passed'=>count($tests)-count($fail),'total'=>count($tests),
 'source_case_count'=>count($sourceCases),
 'source_unique_case_count'=>count(array_unique(array_column($sourceCases,'1'))),
 'php_explicit_case_count'=>count($covered),
 'missing_epic_dispatches'=>$missing,
 'unmapped_epic_case_names'=>array_keys($unmapped),
 'source_sha256'=>hash_file('sha256',$sourceFile),
 'enum_sha256'=>hash_file('sha256',$headerFile),
 'reader_sha256'=>hash_file('sha256',$readerFile),
 'failures'=>$fail
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
