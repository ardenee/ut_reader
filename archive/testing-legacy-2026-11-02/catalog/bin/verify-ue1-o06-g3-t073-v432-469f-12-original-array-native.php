<?php
/** Original stock UT99 v432 and distinct 469f compiled UArrayProperty::Serialize: genuinely loaded Engine.u values. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$repo=dirname(__DIR__,2);
$proof=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T073-v432-469f-original-12-array-native-methods.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $label,bool $pass)use(&$checks,&$errors):void{
    ++$checks; if(!$pass && count($errors)<30)$errors[]=$label;
};
$check('frozen owner T073, no unproven whole-source-family acceptance',
    $proof['frozen_owner']==='T073'&&$proof['complete_T073']===false);
foreach($proof['pins'] as $key=>$pin){
    $file=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
    $check('original engine/source/native SHA pin '.$key,is_file($file)
        &&filesize($file)===(int)$pin['bytes']&&hash_file('sha256',$file)===$pin['sha256']);
}
$code432=(string)file_get_contents($repo.'/'.$proof['pins']['v432_method_disassembly']['path']);
$code469=(string)file_get_contents($repo.'/'.$proof['pins']['469f_method_disassembly']['path']);
$check('v432 actual compiled x86 only archives Inner via member +0x5c',
    str_contains($code432,'Core!UArrayProperty::Serialize:')
    &&str_contains($code432,'add     esi,5Ch')
    &&!str_contains($code432,'add     esi,64h'));
$check('469f actual compiled x86 version <=69 branch gates extra +0x64',
    str_contains($code469,'Core!UArrayProperty::Serialize:')
    &&str_contains($code469,'cmp     dword ptr [esi+4],45h')
    &&str_contains($code469,'lea     eax,[edi+64h]')
    &&str_contains($code469,'Core!UArrayProperty::Serialize+0x96'));
$mode=(string)file_get_contents($repo.'/'.$proof['pins']['469f_branch_execution_log']['path']);
$check('actual original 469f v69 LOAD has eight array ends',
    preg_match_all('/^T073_469F_END\s*$/m',$mode)===8);
$check('actual original v69 LOAD never enters new extra archive read',
    preg_match('/^T073_469F_EXTRA\s*$/m',$mode)!==1);
$seenCounts=[];
foreach([
    'v432'=>['dir'=>'v432','source'=>'ut99-v432','total'=>4,'export_count'=>5370,
        'indexes'=>[2947,4525,4532,4749]],
    '469f'=>['dir'=>'469f','source'=>'ut99-v469f','total'=>8,'export_count'=>5740,
        'indexes'=>[2947,4525,4532,4749,5445,5595,5597,5627]],
] as $build=>$setting){
    $packagePath=$proof['pins'][$build.'_engine_u']['path'];
    $pkg=new Package($packagePath);
    $exports=$pkg->getExports();$imports=$pkg->getImports();
    $check("$build original genuine Engine.u header is v69",
        ($pkg->getHeader()['version']??null)===69
        &&count($exports)===$setting['export_count']);
    $data=(string)file_get_contents($repo.'/'.$proof['pins'][$build.'_native_load_log']['path']);
    $prefix=$build==='v432'?'T073_V432':'T073_469F';
    $pattern=$build==='v432'
       ?'/^T073_V432_ARRAY idx=(\d+) linker=([0-9a-f]{8}) inner=([0-9a-f]{8})\r?$/mi'
       :'/^T073_469F_ARRAY idx=(\d+) linker=([0-9a-f]{8}) inner=([0-9a-f]{8}) next=([0-9a-f]{8}) staticdim=(-?\d+) archivever=(\d+) flags=([0-9a-f]{8}),([0-9a-f]{8}),([0-9a-f]{8})\r?$/mi';
    preg_match_all($pattern,$data,$observed,PREG_SET_ORDER);
    preg_match_all('/^'.preg_quote($prefix,'/').'_INNER idx=(\d+) linker=([0-9a-f]{8})\r?$/mi',
        $data,$targets,PREG_SET_ORDER);
    $check("$build original compiled callback count",$setting['total']===count($observed)
        &&count($observed)===count($targets));
    $linker=null;$seen=[];$matches=0;
    foreach($observed as $i=>$entry){
        $id=(int)$entry[1];$savedIndex=(int)($targets[$i][1]??-99999)+1;
        $linker??=$entry[2];
        $check("$build source linker unchanged for object $id",
            $entry[2]===$linker&&($targets[$i][2]??null)===$linker);
        $check("$build actual original file-exported array identity $id",
            !isset($seen[$id])&&isset($exports[$id]));
        $seen[$id]=true;
        $row=$exports[$id]??null;
        if(!is_array($row))continue;
        $classIndex=(int)$row['classIndex'];
        $check("$build original type really ArrayProperty $id",
            $classIndex<0&&($imports[-$classIndex-1]['objectNameText']??null)==='ArrayProperty');
        $reader=Native::inspect($packagePath,$pkg,$id,
            Tags::inspect($packagePath,$pkg,$id,$setting['source']),$setting['source']);
        $record=$reader['subclass_fields']??[];
        $ok=($record['inner_reference']??null)===$savedIndex;
        $check("$build actual native UArrayProperty.Inner pointer target equals signed saved reference $id",$ok);
        $check("$build original saved ArrayProperty consumed exact archive $id",
            ($reader['status']??null)==='native_property_record_complete'
            &&($reader['extension_bytes']??null)===0);
        if($build==='469f'){
            $dim=(int)$entry[5];
            $check("469f actual v69 LOAD flags/branch scope $id",
                (int)$entry[6]===69
                &&array_map('strtolower',array_slice($entry,7,3))===['00000001','00000000','00000000']);
            $check("469f special static array runtime dimension explicitly observed $id",
                $dim===($id===5627?4:0)
                &&strcasecmp($entry[4],'00000000')===0);
            // Do NOT equate this live StaticArrayDim to saved UProperty.ArrayDim=1.
            $check("469f archived ArrayDim is distinct from compiled runtime StaticArrayDim $id",
                ($reader['array_dim']??null)===1);
        }
        if($ok)$matches++;
    }
    $ids=array_keys($seen);sort($ids);
    $check("$build expected genuine Engine.u original ArrayProperty identities",
        $ids===$setting['indexes']);
    $check("$build original compiled-to-current reader all exact linked Inner identities",
        $matches===$setting['total']);
    $seenCounts[$build]=$matches;
}
$check('new compiled observation does not assert 469f-v432 equivalent engines',
    $proof['claim_all_methods_equivalent']===false
    &&$proof['full_G3_accepted']===false);
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'failed'=>$errors,
    'matched_original_live_array_records'=>$seenCounts,
    'additional_469f_v69_archive_read_executions'=>0,
    '469f_runtime_StaticArrayDim_Touching'=>4,
    'full_T073'=>false,'full_G3'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
