#!/usr/bin/env php
<?php
/** G3-04 actual original v432 compiled UFunction/UState postload parity. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFunctionLinkReader as Link;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily;
$r=dirname(__DIR__,2);
$folder=$r.'/catalog/compliance/evidence/o06/20261027-';
$sys='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$source='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/';
$hashes=[
 [$sys.'Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'],
 [$sys.'Core.u','ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70'],
 [$sys.'Engine.u','d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 [$sys.'UCC.exe','90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f'],
 [$source.'Src/UnClass.cpp','6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380'],
 [$source.'Src/UnProp.cpp','693194ef489989f1c2ff ee8542cf4c2d8950d07ed0b041eff154531c5b097457'],
 [$source.'Inc/UnClass.h','b1c41959ef8b6eb421b3e2f8c54df904117a5cfc77eeaac26af6aac2078f7972'],
 [$source.'Inc/UnType.h','4800e81c76fbdfe612bb9d2bbbe8b302d41c55ab39f603186d09be9517f85a65'],
 [$folder.'G3-04-T99-V432-function-state-original.log','21a9963cc450b2af69c2237559bb54a9244f720cfd357fbef1ec12e582e5b80a'],
 [$folder.'G3-04-T99-V432-function-state-original.cmd','ae73b5d7b806e33fa3b4e8de56f4545f93c65421021542b23981106fc687a3b0'],
 [$folder.'G3-04-T99-V432-function-state-parse.py','a7cd3996923bd5faa20f4b8d3d6642a6883a59333fc7ee4506b88dc051e23f8f'],
 [$folder.'G3-04-T99-V432-function-state-parsed.json','5627186963d71d32ce16f4f242d929749e801561a2c12a4eae41741907dc0bf7'],
];
$checks=0;$failed=[];$checkedPersisted=0;$checkedLoaded=0;$networked=0;$functionCount=0;$stateCount=0;$kinds=[];
$assert=static function(bool $yes,string $label)use(&$checks,&$failed):void {
    $checks++;
    if(!$yes&&count($failed)<25)$failed[]=$label;
};
foreach($hashes as [$path,$sha]){
    $assert(is_file($path)&&hash_file('sha256',$path)===str_replace(' ','',$sha),
        'SHA '.basename($path));
}
$sourceCPP=(string)file_get_contents($source.'Src/UnClass.cpp');
$assert(str_contains($sourceCPP,'void UFunction::Serialize( FArchive& Ar )')
    &&str_contains($sourceCPP,'ParmsSize = Property->Offset + Property->GetSize();')
    &&str_contains($sourceCPP,'NumParms          = 0;'),'original source recomputes function loaded state');
$header=(string)file_get_contents($source.'Inc/UnClass.h');
$assert(str_contains($header,'UBOOL MergeBools() {return 0;}'),'UFunction never packs consecutive bool parameters');
$parsed=json_decode((string)file_get_contents($folder.'G3-04-T99-V432-function-state-parsed.json'),true,512,JSON_THROW_ON_ERROR);
$assert(($parsed['summary']['events']??null)===650
    &&($parsed['summary']['parsed']??null)===650
    &&($parsed['summary']['errors']??null)===[],'650 original compiled debugger records parsed');
$files=[41296464=>'Core.u',41416128=>'Engine.u'];$pkgs=[];$exports=[];$imports=[];
foreach($files as $link=>$file){
    $pkgs[$link]=new P($sys.$file);
    $exports[$link]=$pkgs[$link]->getExports();
    $imports[$link]=$pkgs[$link]->getImports();
    $assert(($pkgs[$link]->getHeader()['version']??null)===69,$file.' genuine version69');
}
foreach($parsed['records'] as $i=>$row){
    $link=$row['linker'];$idx=$row['idx'];
    $assert(isset($pkgs[$link]),'native linker ID '.$i);if(!isset($pkgs[$link]))continue;
    $p=$pkgs[$link];$e=$exports[$link][$idx]??null;
    $assert(is_array($e),'genuine exported object '.$i);if(!is_array($e))continue;
    $ci=(int)$e['classIndex'];
    $klass=$ci===0?'Class':($ci<0?($imports[$link][-$ci-1]['objectNameText']??null):($exports[$link][$ci-1]['objectNameText']??null));
    $function=$row['kind']==='function';
    $assert($function?$klass==='Function':in_array($klass,['State','Class'],true),
        'compiled virtual method matches original export class '.$i);
    if($function&&$klass!=='Function')continue;
    if(!$function&&!in_array($klass,['State','Class'],true))continue;
    try{
        $v=$ci===0?C::inspect($sys.$files[$link],$p,$idx,false,'ut99-v432')
        :N::inspect($sys.$files[$link],$p,$idx,
            T::inspect($sys.$files[$link],$p,$idx,'ut99-v432'),'ut99-v432');
    }catch(Throwable $exception){$assert(false,'reader exception '.$i.' '.$exception->getMessage());continue;}
    $key=$files[$link].':'.$klass;$kinds[$key]=($kinds[$key]??0)+1;
    $raw=$function?['function_flags','native_index','operator_precedence']:
        ['state_flags','label_table_offset','probe_mask_hex','ignore_mask_hex'];
    if($function && (($row['native']['function_flags']&0x40)!==0)){
        $raw[]='rep_offset';$networked++;
    }
    foreach($raw as $field){
        ++$checkedPersisted;
        $assert(isset($v[$field])&&$v[$field]===$row['native'][$field],
            $files[$link].':'.$idx.' persisted '.$field);
    }
    if($function){
        $functionCount++;
        $assert(($v['source_function_link_status']??null)==='source_property_layout_computed'
            &&($v['source_function_loaded_fields_computed']??null)===true
            &&($v['runtime_function_param_chain_linked']??null)===false,
            $files[$link].':'.$idx.' source-accurate values, no fabricated pointer linking');
        foreach(['num_parms','parms_size','return_value_offset'] as $field){
            ++$checkedLoaded;
            $assert(isset($v['loaded_'.$field])
                &&$v['loaded_'.$field]===$row['native'][$field],
                $files[$link].':'.$idx.' post-LOAD '.$field);
        }
    }else{$stateCount++;}
}
$assert($functionCount===619&&$stateCount===31&&$networked===44,
    'exact original compiled method event and FUNC_Net counts');
$assert($checkedPersisted===2025&&$checkedLoaded===1857,
    'complete persisted and postload comparison contract');
$expectedKinds=['Core.u:Function'=>161,'Core.u:Class'=>7,
    'Engine.u:Function'=>458,'Engine.u:Class'=>9,'Engine.u:State'=>15];
$assert(count($kinds)===count($expectedKinds)
    &&array_diff_assoc($expectedKinds,$kinds)===[]
    &&array_diff_assoc($kinds,$expectedKinds)===[],
    'native file/type groups cover all 650 original objects');
$sample=N::inspect($sys.'Core.u',$pkgs[41296464],629,
    T::inspect($sys.'Core.u',$pkgs[41296464],629,'ut99-v432'),'ut99-v432');
$bad=Link::recompute($sys.'Core.u',$pkgs[41296464],629,
    array_replace($sample,['children_reference'=>count($exports[41296464])+200]));
$assert(($bad['source_function_link_status']??null)==='source_layout_unverified'
    &&!isset($bad['loaded_num_parms'])
    &&($bad['runtime_function_param_chain_linked']??null)===false,
    'malformed field-chain reference fails closed without invented loaded values');
$unsupported=Link::recompute($sys.'Core.u',$pkgs[41296464],629,
    $sample,CatalogUE1ClassicSourceFamily::EPIC_UE1);
$assert(($unsupported['source_function_link_status']??null)==='source_profile_unverified'
    &&!isset($unsupported['loaded_num_parms']),
    'C98 source profile cannot borrow UT99 function parameter link semantics');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'fail'=>$failed,
    'actual_original_compiled_events'=>650,'v432_functions'=>$functionCount,
    'states_and_classes'=>$stateCount,'net_functions'=>$networked,
    'original_persisted_matches'=>$checkedPersisted,
    'new_source_reconstructed_loaded_matches'=>$checkedLoaded,
    'total_native_field_comparisons'=>$checkedPersisted+$checkedLoaded,
    'previously_sealed_compiled_runs_reexecuted'=>0,
    'G3_04_full'=>'OPEN (other original variants, SAVE and malformed native branches)'],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
