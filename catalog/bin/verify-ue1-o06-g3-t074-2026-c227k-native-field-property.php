<?php
declare(strict_types=1);
$cat=dirname(__DIR__);
require_once $cat.'/bootstrap/autoload.php';
require_once $cat.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as Classes;
$repo=dirname(__DIR__,2);
$proof=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-T074-C227k-native-1051-753-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$file=$proof['authentic_runtime']['Core.u']['path'];
$log=$repo.'/'.$proof['evidence']['native_compiled_load_trace']['path'];
$pkg=new Package($file);
$exports=$pkg->getExports();
$trace=file_get_contents($log);
$checks=0;$errors=[];$check=static function(string $key,bool $ok)use(&$checks,&$errors):void{
    $checks++; if(!$ok){if(count($errors)<35)$errors[]=$key;}
};

foreach(['authentic_runtime','source_headers','current_reader','evidence'] as $group){
    foreach($proof[$group] as $key=>$pin){
        $onDisk=($pin['location']==='repo')?$repo.'/'.$pin['path']:$pin['path'];
        $check('SHA256 certified source/oracle/evidence '.$group.'/'.$key,
            is_file($onDisk)&&filesize($onDisk)===$pin['bytes']
            &&hash_file('sha256',$onDisk)===$pin['sha256']);
    }
}
$check('exact original compiled 227k method scope and no false full T074',
    $proof['frozen_owner']==='T074'&&$proof['complete_T074']===false
    &&$proof['source_profile']==='unreal-v227k');
$method=file_get_contents($repo.'/'.$proof['evidence']['original_method_disassembly']['path']);
$check('2026 original UField loaded refs +0x30/+0x34 source method',
    str_contains($method,'Core!UField::Serialize:')
    &&str_contains($method,'8d4730')
    &&str_contains($method,'8d4734')
    &&str_contains($method,'Core!UField::Serialize+0x83'));
$check('2026 original UProperty loaded dimension/flags source method',
    str_contains($method,'Core!UProperty::Serialize:')
    &&str_contains($method,'8d463c')
    &&str_contains($method,'8d5e44')
    &&str_contains($method,'Core!UProperty::Serialize+0x97'));

$check('Core.u original genuine valid',$pkg->validatePackage()===[]);
$check('2026 Core.u v69 lic227 1142 exports',
    ($pkg->getHeader()['version']??null)===69 && count($exports)===1142);
$check('2026 native x86 ran without CDB AV',preg_match('/^G3T074_NATIVE_AV$/m',$trace)!==1);
$fields=[];$props=[];$current=-1;
foreach(preg_split('/\r\n|\r|\n/',$trace) as $line){
    if(preg_match('/^G3T074_FIELD idx=(\d+) this=([0-9a-f]{8}) linker=([0-9a-f]{8}) super=([0-9a-f]{8}) next=([0-9a-f]{8})$/i',$line,$m)){
        $current=(int)$m[1]; $fields[]=[
            'idx'=>$current,'address'=>$m[2],'linker'=>$m[3],
            'super'=>hexdec($m[4]),'next'=>hexdec($m[5]),'targets'=>[]
        ];continue;
    }
    if(preg_match('/^G3T074_(SUPERTARGET|NEXTTARGET) idx=(\d+) linker=([0-9a-f]{8})$/i',$line,$m)){
        $last=count($fields)-1;$check('target follows real UField', $last>=0);
        if($last>=0){$fields[$last]['targets'][$m[1]]=['idx'=>(int)$m[2],'linker'=>$m[3]];}
        continue;
    }
    if(preg_match('/^G3T074_PROPERTY idx=(\d+) this=([0-9a-f]{8}) linker=([0-9a-f]{8}) dim=(-?\d+) flags=([0-9a-f]{8}) cat=([0-9a-f]{8}) repo=([0-9a-f]{8})$/i',$line,$m)){
        $props[]=['idx'=>(int)$m[1],'address'=>$m[2],'linker'=>$m[3],
            'dim'=>(int)$m[4],'flags'=>hexdec($m[5]),'cat'=>hexdec($m[6]),'repo'=>hexdec($m[7])];
    }
}
$check('1051 original 2026 UField native calls',count($fields)===1051);
$check('753 original 2026 UProperty native calls',count($props)===753);
$cache=[];$load=static function(int $id)use(&$cache,$file,$pkg,$exports):array{
    if(isset($cache[$id]))return $cache[$id];
    $exp=$exports[$id]??null;if(!is_array($exp))return [];
    if((int)($exp['classIndex']??-1)===0){
        $record=Classes::inspect($file,$pkg,$id,false,'unreal-v227k');
    }else{
        $record=Native::inspect($file,$pkg,$id,Tags::inspect($file,$pkg,$id,'unreal-v227k'),'unreal-v227k');
    }
    return $cache[$id]=$record;
};
$linker=null;$seen=[];$fieldMatched=0;$fieldCount=0;$missing=[];$superNonzero=0;$nextNonzero=0;
foreach($fields as $f){
    $id=$f['idx'];$linker??=$f['linker'];
    $check("source linker consistent field $id",$f['linker']===$linker);
    $check("unique real field export $id",!isset($seen[$id]));$seen[$id]=1;
    $check("field exported $id",isset($exports[$id]));if(!isset($exports[$id]))continue;
    $record=$load($id);
    $expected=[];
    foreach(['super'=>'SUPERTARGET','next'=>'NEXTTARGET'] as $label=>$which){
        $ptr=$f[$label];$target=$f['targets'][$which]??null;
        if($ptr===0){
            $check("null native ref $label $id",$target===null);
            $expected[$label]=0;
        }else{
            $check("original genuine source linker of $label $id",$target!==null && $target['linker']===$linker);
            if($target!==null){
                $check("native reference target export exists $label $id",isset($exports[$target['idx']]));
                $expected[$label]=$target['idx']+1;
                if($label==='super')$superNonzero++;else $nextNonzero++;
            }else{$expected[$label]=null;}
        }
    }
    $a=$record['super_field_reference']??null;$b=$record['next_field_reference']??null;
    $check("2026 native vs PHP SuperField $id",is_int($a)&&$a===$expected['super']);
    $check("2026 native vs PHP Next $id",is_int($b)&&$b===$expected['next']);
    if($a===$expected['super']&&$b===$expected['next'])$fieldMatched++;else if(count($missing)<20)$missing[]=['id'=>$id,'super'=>[$a,$expected['super']],'next'=>[$b,$expected['next']],'status'=>$record['status']??null];
    $fieldCount++;
}
$seenProps=[];$propertyMatched=0;$propMissing=[];
foreach($props as $p){
    $id=$p['idx'];$check("property real same linker $id",$p['linker']===$linker);
    $check("property once $id",!isset($seenProps[$id]));$seenProps[$id]=true;
    $check("property genuine exported $id",isset($exports[$id]));if(!isset($exports[$id]))continue;
    $record=$load($id);
    $matches=($record['array_dim']??null)===$p['dim']&&($record['property_flags']??null)===$p['flags'];
    $check("native vs current reader Property dim/flags $id",$matches);
    if($matches)$propertyMatched++;else if(count($propMissing)<20)$propMissing[]=['id'=>$id,'native'=>[$p['dim'],$p['flags']],'reader'=>[$record['array_dim']??null,$record['property_flags']??null],'status'=>$record['status']??null];
}
$check('all 1051 native unique field exports',count($seen)===1051);
$check('all 753 native unique property exports',count($seenProps)===753);
echo json_encode(['frozen_owner'=>'T074','source_profile'=>'unreal-v227k','full_T074_acceptance'=>false,'checks'=>$checks,'error_count'=>count($errors),'first_errors'=>$errors,
'fields'=>$fieldCount,'field_matched'=>$fieldMatched,'properties'=>count($seenProps),'property_matched'=>$propertyMatched,
'nonnull_super'=>$superNonzero,'nonnull_next'=>$nextNonzero,'mismatch_field_sample'=>$missing,'mismatch_property_sample'=>$propMissing,
'header'=>['version'=>$pkg->getHeader()['version'],'licensee'=>$pkg->getHeader()['licensee'],'exportCount'=>count($exports)],'original_linker'=>$linker],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($errors===[]?0:1);
