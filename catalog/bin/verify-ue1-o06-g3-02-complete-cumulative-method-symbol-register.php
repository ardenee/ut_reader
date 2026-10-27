#!/usr/bin/env php
<?php
/** G3-02 exhaustive source mapping, not class serialised-value behavior proof. */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
$base=$repo.'/catalog/compliance';
$m=json_decode((string)file_get_contents($base.'/UE1-O06-G3-CUMULATIVE-CORE-SERIALIZER-METHOD-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$old=json_decode((string)file_get_contents($base.'/UE1-O06-G3-C227-COMPILED-CORE-SYMBOLS.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failures=[];
$check=static function(string $label,bool $pass)use(&$checks,&$failures):void{
    $checks++;if(!$pass&&count($failures)<30)$failures[]=$label;
};
$check('full original 57 C227 method inventory',count($m['methods'])===57);
$check('original imported symbol record 57',count($old['G3_relevant_exported_decorated_symbols'])===57);
$check('source symbol and inheritance classification complete',$m['all_C227_57_symbols_source_mapped']===true);
$check('five complete lineage source declarations have behavior owners',$m['all_five_profile_source_methods_have_owner_gate']===true);
$check('method body acceptance reserved for exact specific behavior tests',
    $m['no_new_runtime_value_parity_claimed']===true
    &&$m['unimplemented_method_body_source_behavior_must_be_accepted_in_owner_G3_gate']===true);
$lib=$m['canonical_original_C227_Core_lib'];
$check('original real C227 import library hash and length unchanged',
    is_file($lib['path'])&&filesize($lib['path'])===$lib['bytes']
    &&hash_file('sha256',$lib['path'])===$lib['sha256']
    &&$lib['sha256']===$old['pinned_227_Core_lib']['sha256']);
$binary=(string)file_get_contents($lib['path']);
$sources=$m['original_game_source_header_SHA_pins'];
$check('all actual game source revisions source pinned',
    array_keys($sources)===['unreal-v1200','unreal-v1224','unreal-v1227','ut99-v400','ut99-v432']);
foreach($sources as $rev=>$pins){
    $check('exact source header set for '.$rev,
        array_keys($pins)===['UnType.h','UnClass.h','UnObjBas.h']);
    foreach($pins as $name=>$pin){
        $check('genuine Epic original header SHA '.$rev.' '.$name,
            is_file($pin['path'])&&filesize($pin['path'])===$pin['bytes']
            &&hash_file('sha256',$pin['path'])===$pin['sha256']);
    }
}
/** Parse original C++ class body, preserving raw braces for source SHA. */
$classBody=static function(string $s,string $class,string $sha):?string{
    $regex='/\bclass\s+(?:CORE_API\s+)?'.preg_quote($class,'/')
        .'\s*(?::\s*public\s+(?:U\w+))?\s*\{/';
    preg_match_all($regex,$s,$all,PREG_OFFSET_CAPTURE);
    foreach($all[0] as [$match,$pos]){
        $open=$pos+strlen($match);$depth=1;$i=$open;$length=strlen($s);
        while($i<$length&&$depth){
            if($s[$i]==='{')$depth++;
            elseif($s[$i]==='}')$depth--;
            $i++;
        }
        if($depth!==0)continue;
        $body=substr($s,$open,$i-$open-1);
        if(hash('sha256',$body)===$sha)return $body;
    }
    return null;
};
$raw=[];
$getSrc=static function(string $path)use(&$raw):string{
    return $raw[$path]??= str_replace(["\r\n","\r"],"\n",(string)file_get_contents($path));
};
$originalIndex=[];
foreach($old['G3_relevant_exported_decorated_symbols'] as $symbol){
    $key=$symbol['decorated'];
    $check('original lib decorated symbols distinct '.$key,!isset($originalIndex[$key]));
    $originalIndex[$key]=$symbol;
}
$found=[];$classes=[];$owners=[];
foreach($m['methods'] as $row){
    $symbol=$row['symbol'];
    $ref=$originalIndex[$symbol]??null;
    $check('full original import source 57 symbolic method '.$symbol,
        $ref!==null&&$ref['class']===$row['class']
        &&$ref['method']===$row['method']
        &&$ref['owner_contract']===$row['owner_contract']
        &&str_contains($binary,$symbol));
    $check('no duplicate source method '.$symbol,!isset($found[$symbol]));
    $found[$symbol]=true;
    $classes[$row['class']]=true;$owners[$row['owner_contract']]=true;
    $path=$row['current_source_header'];
    $check('original v227 full header hash per source symbol '.$symbol,
        hash_file('sha256',$path)===$row['current_source_header_sha256']);
    $body=$classBody($getSrc($path),$row['class'],$row['source_class_definition_body_sha256']);
    $check('original C++ class exact raw definition SHA '.$symbol,is_string($body));
    $check('method genuinely explicitly declared in its own source class body '.$symbol,
        $body!==null&&preg_match('/\b'.preg_quote($row['method'],'/')
            .'\s*\(/',$body)===1);
    $check('every method owner belongs to actual G3 behavior acceptance '.$symbol,
        preg_match('/^G3-(?:0[3-9]|1[0-5])$/',$row['owner_contract'])===1);
    $history=$row['revision_declarations'];
    $check('source chronology includes earlier method inheritance '.$symbol,
        count($history)===3
        &&array_column($history,'revision')===['unreal-v1200','unreal-v1224','unreal-v1227']
        &&$history[2]['method_declared']===true);
}
$check('57 original G3 method decorated symbols completely registered',count($found)===57);
$check('all original 25 relevant C227 classes accounted for',count($classes)===25);
$check('every G3 real method behavior target unambiguously routed',count($owners)===10);
$srcCount=0;$sourceKinds=[];
$lineage=$m['all_applicable_source_profiles_serialization_class_method_declarations'];
foreach($lineage as $version=>$rows){
    $local=[];
    foreach($rows as $row){
        $srcCount++;
        $id=$row['class'].'::'.$row['method'];
        $check('no source-revision class method duplicate '.$version.' '.$id,
            !isset($local[$id]));
        $local[$id]=true;
        $body=$classBody($getSrc($row['source_header']),$row['class'],
            $row['source_class_definition_body_sha256']);
        $check('source-specific exact original class definition matches '.$version.' '.$id,
            $body!==null
            &&hash_file('sha256',$row['source_header'])===$row['header_sha256']);
        $check('method explicitly present in correct original class '.$version.' '.$id,
            $body!==null&&preg_match('/\b'.preg_quote($row['method'],'/').'\s*\(/',$body)===1);
        $check('source method has a G3 owner, no inferred behavior equivalence '.$version.' '.$id,
            preg_match('/^G3-(?:0[3-9]|1[0-5])$/',$row['owner_contract'])===1);
    }
    $sourceKinds[$version]=count($local);
    $check('source lineage per-revision original method count '.$version,
        count($local)===$m['all_applicable_source_profiles_serialization_method_count'][$version]);
}
$check('all five revision-specific source declarations accounted for', $srcCount===205);
$check('C227 explicitly overrides UButtonProperty native Serialize',
    isset($lineage['unreal-v1227'])
    &&count(array_filter($lineage['unreal-v1227'],
        static fn(array $r):bool=>$r['class']==='UButtonProperty'&&$r['method']==='Serialize'))===1);
$check('C227 Pointer and Any property do NOT declare Serialize',
    count(array_filter($lineage['unreal-v1227'],static fn(array $r):bool=>
        in_array($r['class'],['UPointerProperty','UAnyProperty'],true)
        &&$r['method']==='Serialize'))===0);
$check('old source metadata mistakenly omitted UButton Serialize, corrected without erasing historical evidence',
    $old['new_227_licensee_derived_properties']['UButtonProperty']['source_has_explicit_Serialize']===false
    &&$m['new_v1227_explicit_new_Serialize_override']==='UButtonProperty');
$check('v227 new pointer and any inherit real base serializer',
    $m['new_v1227_absent_Serialize_override_inherits_UProperty']===
        ['UPointerProperty','UAnyProperty']);
$check('v400 and v432 separate source headers analyzed, not presumed identical',
    $sources['ut99-v400']['UnClass.h']['sha256']!==$sources['ut99-v432']['UnClass.h']['sha256']
    &&$sources['ut99-v400']['UnType.h']['sha256']!==$sources['ut99-v432']['UnType.h']['sha256']);
$check('source revisions carry all expected class-method inventory sizes',
    $sourceKinds===['unreal-v1200'=>28,'unreal-v1224'=>33,'unreal-v1227'=>58,
    'ut99-v400'=>43,'ut99-v432'=>43]);
$ok=$failures===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($failures),
    'failed'=>$failures,'c227_original_exported_methods_source_declared'=>count($found),
    'c227_original_class_owners'=>count($classes),
    'five_revision_source_defined_class_methods'=>$srcCount,
    'per_source_revision_counts'=>$sourceKinds,
    'explicit_new_v227_button_override_mapped_to_G3_08_not_claimed_behavior_verified'=>true,
    'G3_02_source_symbol_mapping_complete'=>$ok,
    'G3_03_to_G3_15_loaded_value_and_error_semantics_newly_certified'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
