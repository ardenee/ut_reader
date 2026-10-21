#!/usr/bin/env php
<?php
/** Source-defined original 1997 imported UClass parent/property resolution. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader as Binary;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertiesReader as Properties;
$checks=[];$fail=[];
$check=static function(string $name,bool $okay)use(&$checks,&$fail):void{
    $checks[$name]=$okay;if(!$okay)$fail[]=$name;
};
$names=static function(array $values):string{
    $out='';foreach($values as $value)$out.=$value."\0".pack('V',0);return $out;
};
$class=static function(int $num,int $parent):string{
    return pack('V2',$num,$num).pack('V5',$parent,0,0,0,0)
        .pack('V',0).pack('v',0).pack('V3',0,0,0)
        .pack('V8',28,12,0,0,0,0,0,0);
};
$stack=pack('V2',0,0).str_repeat("\xff",8);
$provider=static function(int $version)use($stack,$names,$class):array{
    $nm=$names(['Class','BaseClass','BaseScore']);
    $head=$stack.$class(1,0);
    $prop=pack('vvV6',3,0,2,0,0,1,4,1);
    $headOff=56+strlen($nm);$dataOff=$headOff+strlen($head);
    $objOff=$dataOff+strlen($prop);
    $obj=pack('vvVV',2,1,0,$headOff)
       .pack('V4',0,$dataOff,strlen($head),strlen($prop))
       .pack('VvVV',0,0,0,0);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,1,3,56,$objOff,0);
    return ['bytes'=>$summary.$nm.$head.$prop.$obj,'header'=>$headOff];
};
$consumer=static function(int $version)use($stack,$names,$class):array{
    $dep="BASE\0";$nm=$names(['Class','BaseClass','DerivedClass','ChildScore','Instance','Properties','PropertySet']);
    $head=$stack.$class(1,1);
    $prop=pack('vvV6',4,0,2,0,0,1,4,1);
    $instance=$stack.pack('V2',111,222);
    $headOff=56+strlen($dep)+strlen($nm);
    $propOff=$headOff+strlen($head);
    $instanceOff=$propOff+strlen($prop);
    $setHeader=$stack.pack('V4',4,4,1,1);
    $setData=pack('V',333);
    $setHeaderOff=$instanceOff+strlen($instance);
    $setDataOff=$setHeaderOff+strlen($setHeader);
    $objOff=$setDataOff+strlen($setData);
    $obj=pack('vvVV',2,1,0,0)
        .pack('vvVV',3,1,0,$headOff)
        .pack('V4',0,$propOff,strlen($head),strlen($prop))
        .pack('VvVV',1,0,0,0)
        .pack('vvVV',5,3,0,$instanceOff)
        .pack('V4',0,0,strlen($instance),0)
        .pack('vvVV',7,6,0,$setHeaderOff)
        .pack('V4',0,$setDataOff,strlen($setHeader),strlen($setData));
    $summary=pack('a32V6',"Unrealfile\x1A",$version,4,7,56+strlen($dep),$objOff,1);
    return ['bytes'=>$summary.$dep.$nm.$head.$prop.$instance.$setHeader.$setData.$obj];
};
$provPath=tempnam(sys_get_temp_dir(),'ue1BaseProvider');
$consumerPath=tempnam(sys_get_temp_dir(),'ue1DerivedConsumer');
if(!is_string($provPath)||!is_string($consumerPath))throw new RuntimeException('No fixture temp path');
try{
    foreach([22,23] as $version){
        $p=$provider($version);$c=$consumer($version);
        file_put_contents($provPath,$p['bytes']);
        file_put_contents($consumerPath,$c['bytes']);
        $without=Binary::read($consumerPath,true);
        $check("v{$version}_unbound_class_never_fabricated",
            ($without['objects'][0]['status']??null)==='parent_UClass_schema_unavailable');
        $resolved=Binary::read($consumerPath,true,['BASE'=>$provPath]);
        $entry=$resolved['objects'][0]??[];
        $props=$entry['property_values']??[];
        $check("v{$version}_external_parent_values_in_source_order",
            ($entry['status']??null)==='native_binary_properties_decoded'
            && count($props)===2
            && ($props[0]['name']??null)==='ChildScore'
            && ($props[0]['values']??null)===[111]
            && ($props[1]['name']??null)==='BaseScore'
            && ($props[1]['values']??null)===[222]);
        $check("v{$version}_resolved_source_not_runtime_class",
            count($entry['external_property_schema_sources']??[])===1
            && ($entry['external_property_schema_sources'][0]['file_sha256']??null)===hash_file('sha256',$provPath)
            && ($entry['runtime_external_classes_loaded']??true)===false);
        $native=Properties::read($consumerPath,true,['BASE'=>$provPath])['properties_objects'][0]??[];
        $check("v{$version}_external_UClass_properties_data",
            ($native['status']??null)==='native_UClass_bin_properties_decoded'
            && ($native['property_values'][0]['name']??null)==='BaseScore'
            && ($native['property_values'][0]['values']??null)===[333]
            && count($native['external_property_schema_sources']??[])===1
            && ($native['runtime_external_classes_loaded']??true)===false);
        $absent=Binary::read($consumerPath,false,['OTHER'=>$provPath])['objects'][0]??[];
        $check("v{$version}_undeclared_dependency_refused",
            ($absent['status']??null)==='parent_UClass_schema_unavailable'
            && ($absent['external_schema_status']??null)==='declared_external_UClass_provider_unavailable');
        $unverified=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $consumerPath,['format'=>'early_unrealfile','version'=>$version]
        );
        $check("v{$version}_production_unbound_marked_unverified",
            ($unverified['native_metadata']['unrealfile_binary_properties']['objects'][0]['status']??null)==='parent_UClass_schema_unavailable'
            && ($unverified['parse_complete']??true)===false);
        // The provider's own parent reference cannot refer to itself.
        file_put_contents($provPath,substr_replace($p['bytes'],pack('V',1),$p['header']+24,4));
        try{
            Binary::read($consumerPath,true,['BASE'=>$provPath]);
            $rejected=false;
        }catch(RuntimeException){$rejected=true;}
        $check("v{$version}_cyclic_external_parent_rejected",$rejected);
    }
}finally{@unlink($provPath);@unlink($consumerPath);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),
    'total'=>count($checks),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
