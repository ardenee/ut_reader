#!/usr/bin/env php
<?php
/**
 * Genuine source-defined 1997 May-vs-July UScript native bytecode envelopes.
 * Both advertise Unrealfile version 22 or 23, despite incompatible token
 * conversion semantics, iterator opcodes and FRotation archive widths.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileScriptExpressionReader as Script;
$pass=[];$fail=[];
$check=static function(string $name,bool $yes)use(&$pass,&$fail):void{
    if($yes)$pass[]=$name;else $fail[]=$name;
};
$pack=static function(int $v,string $code):string{
    $names="Script\0".pack('V',0)."SourceCode\0".pack('V',0);
    $headOff=56+strlen($names);
    $header=pack('V2',0,0).str_repeat("\xff",8)
        .pack('V3',strlen($code),strlen($code),0);
    $dataOff=$headOff+strlen($header);
    $objsOff=$dataOff+strlen($code);
    $object=pack('vvVV',2,1,0,$headOff)
        .pack('V4',0,$dataOff,strlen($header),strlen($code));
    $summary=pack('a32V6',"Unrealfile\x1a",$v,1,2,56,$objsOff,0);
    return $summary.$names.$header.$code.$object;
};
$tmp=tempnam(sys_get_temp_dir(),'ue1scriptmayjul');
if(!is_string($tmp))throw new RuntimeException('No source fixture temp');
try{
    foreach([22,23] as $v){
        $key="v{$v}_";
        // May UnScript.cpp EX_RotationConst advances three WORDs, six bytes.
        // July would require 12 bytes and therefore cannot parse this stream.
        file_put_contents($tmp,$pack($v,chr(0x22).pack('v3',65535,32768,42)));
        $p=Script::read($tmp,true)['script_expressions'][0]??[];
        $check($key.'May_WORD_rotation_uniquely_inferred',
            ($p['status']??null)==='native_script_expressions_decoded'
            &&($p['source_lineage']??null)==='unreal_1997_may'
            &&($p['source_lineage_inferred_from_exact_script_parse']??false)===true
            &&($p['expression_tree'][0]['fields']['rotation_signed']??null)===[65535,32768,42]
            &&($p['serialized_bytes_consumed']??null)===7);
        // Explicit May test: 0x32 is EX_ByteToInt, invalid as a July token.
        file_put_contents($tmp,$pack($v,chr(0x32).chr(0x25)));
        $p=Script::read($tmp,true)['script_expressions'][0]??[];
        $c=$p['expression_tree'][0]??[];
        $check($key.'May_conversion_source_semantic_inferred',
            ($p['source_lineage']??null)==='unreal_1997_may'
            &&($c['fields']['conversion_name']??null)==='ByteToInt'
            &&($c['fields']['conversion_is_source_defined']??false)===true
            &&($c['children'][0]['token']??null)===0x25);
        try{Script::read($tmp,true,'unreal_1997_july');$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check($key.'May_conversion_rejected_as_July',$rejected);
        // July's iterator does not exist in May v0.86x source.
        file_put_contents($tmp,$pack($v,chr(0x2f).chr(0x25).pack('v',2)));
        $p=Script::read($tmp,true)['script_expressions'][0]??[];
        $check($key.'July_iterator_uniquely_inferred',
            ($p['source_lineage']??null)==='unreal_1997_july'
            &&($p['expression_tree'][0]['children'][0]['token']??null)===0x25
            &&($p['expression_tree'][0]['fields']['jump_offset']??null)===2);
        try{Script::read($tmp,true,'unreal_1997_may');$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check($key.'July_iterator_rejected_as_May',$rejected);
        // The SAME 0x38 byte and child are syntactically valid in both
        // families yet refer to entirely DIFFERENT conversion semantics.
        file_put_contents($tmp,$pack($v,chr(0x40).chr(0x25)));
        $u=Script::read($tmp,false)['script_expressions'][0]??[];
        $check($key.'May_July_same_bytes_distinct_conversion_not_assumed',
            ($u['status']??null)==='source_1997_script_lineage_required'
            &&($u['source_branch_candidates']??null)===['unreal_1997_may','unreal_1997_july']
            &&!isset($u['expression_tree']));
        try{Script::read($tmp,true);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check($key.'ambiguous_conversion_strict_rejected',$rejected);
        $may=Script::read($tmp,true,'unreal_1997_may')['script_expressions'][0]??[];
        $july=Script::read($tmp,true,'unreal_1997_july')['script_expressions'][0]??[];
        $check($key.'conversion_ID40_different_May_July_meanings',
            ($may['expression_tree'][0]['fields']['conversion_name']??null)==='NameToBool'
            &&($july['expression_tree'][0]['fields']['conversion_name']??null)==='BoolToByte'
            &&($may['serialized_bytes_consumed']??null)===2
            &&($july['serialized_bytes_consumed']??null)===2);
        // July EX_RotationConst writes three INTs, including signed values.
        file_put_contents($tmp,$pack($v,chr(0x22).pack('V3',0xffffffff,0x80000000,42)));
        $july=Script::read($tmp,true,'unreal_1997_july')['script_expressions'][0]??[];
        $check($key.'July_INT_rotation_source_value',
            ($july['expression_tree'][0]['fields']['rotation_signed']??null)===[-1,-2147483648,42]
            &&($july['serialized_bytes_consumed']??null)===13);
        // No version-based guess: identical shared token has same semantics.
        file_put_contents($tmp,$pack($v,chr(0x25)));
        $u=Script::read($tmp,true)['script_expressions'][0]??[];
        $check($key.'shared_wire_equivalent_without_fake_source',
            ($u['status']??null)==='native_script_expressions_decoded'
            &&($u['source_lineage']??null)===null
            &&($u['source_lineage_wire_equivalent']??false)===true
            &&($u['expression_tree'][0]['token']??null)===0x25);
        // Staging must preserve the ambiguity, never report full parse.
        file_put_contents($tmp,$pack($v,chr(0x40).chr(0x25)));
        $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unrealfile','version'=>$v]);
        $s=$staged['native_metadata']['unrealfile_script_expressions']['script_expressions'][0]??[];
        $check($key.'ambiguous_staging_reports_incomplete',
            ($s['status']??null)==='source_1997_script_lineage_required'
            &&!isset($s['expression_tree'])&&($staged['parse_complete']??true)===false);
        // Caller-supplied source provenance is carried by the production
        // unverified staging adapter to the source-family code reader.
        foreach(['unreal_1997_may'=>'NameToBool','unreal_1997_july'=>'BoolToByte'] as $family=>$semantic){
            $r=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
                $tmp,['format'=>'early_unrealfile','version'=>$v,'source_lineage'=>$family]);
            $read=$r['native_metadata']['unrealfile_script_expressions']['script_expressions'][0]??[];
            $check($key.'explicit_'.$family.'_staging_preserves_semantic',
                ($r['native_metadata']['source_lineage_provenance']??null)==='caller_explicit'
                &&($r['native_metadata']['source_lineage_provided']??null)===$family
                &&($read['source_lineage']??null)===$family
                &&($read['expression_tree'][0]['fields']['conversion_name']??null)===$semantic
                &&($r['parse_complete']??true)===false);
        }
        try{
            \UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
                $tmp,['format'=>'early_unrealfile','version'=>$v,'source_lineage'=>'incorrect_build']);
            $badLineage=false;
        }catch(InvalidArgumentException){$badLineage=true;}
        $check($key.'invalid_explicit_staging_lineage_rejected',$badLineage);
        try{
            \UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
                $tmp,['format'=>'early_unrealfile','version'=>$v,'source_lineage'=>false]);
            $badType=false;
        }catch(RuntimeException){$badType=true;}
        $check($key.'non_string_source_provenance_rejected',$badType);
        file_put_contents($tmp,$pack($v,chr(0x22).pack('v2',1,2)));
        try{Script::read($tmp,true);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check($key.'truncated_rotation_no_guess',$rejected);
        try{Script::read($tmp,true,'unsourced_1997');$rejected=false;}
        catch(InvalidArgumentException){$rejected=true;}
        $check($key.'invalid_source_id_rejected',$rejected);
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($pass),'total'=>count($pass)+count($fail),
    'failures'=>$fail,'source_families'=>['unreal_1997_may','unreal_1997_july']],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
