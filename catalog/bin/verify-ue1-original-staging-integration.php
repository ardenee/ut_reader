#!/usr/bin/env php
<?php
/**
 * Production unverified staging must retain Epic's original 1996/1997 native
 * tables, and must NEVER manufacture modern object paths, OuterIndex or GUIDs.
 * Tests actual CatalogUnverifiedStagingIndex::parse and snapshot builder.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
require_once dirname(__DIR__) . '/lib/GameProfiles.php';
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataSnapshotBuilder;

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$staging=new CatalogUnverifiedStagingIndex($db,[]);
$builder=new CatalogUnverifiedMetadataSnapshotBuilder($db);
$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
    $checks[$name]=$ok;if(!$ok){$failed[]=$name;}
};
$resource=static function(int $version):string {
    $tag="Unreal Resource\x1A";
    $begin=$tag."\0";
    $nameOffset=strlen($begin);
    $names=pack('a32V','ExampleName',0x801);
    $importOffset=$nameOffset+strlen($names);
    $imports=pack('a32VVV','ImportedTex',7,3,76);
    $exportOffset=$importOffset+strlen($imports);
    $exports=pack('a32VVV','ExportedTex',8,3,76);
    $payloadOffset=$exportOffset+strlen($exports);
    $headerOffset=$payloadOffset+4;
    $hdr=pack('V2a32V9',0xDEADBEEF,3,'ExportedTex',0,0,8,0,0,$payloadOffset,4,0xCAFEF00D,0);
    $trailer=pack('V9',$version,1,1,1,$nameOffset,$importOffset,$exportOffset,$headerOffset,strlen($hdr))
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',$tag);
    return $begin.$names.$imports.$exports.'DATA'.$hdr.'[SUMMARY]'.$trailer;
};
$unrealfile=static function(int $version):string {
    $strings=['Texture','Thing'];
    $names='';
    foreach($strings as $s){$names.=$s."\0".pack('V',5);}
    $namesOffset=56;
    $bytes=$namesOffset+strlen($names);
    $headerData='DATAXXXX';
    $objectsOffset=$bytes+strlen($headerData);
    $import=pack('vvVV',2,1,0,0);
    $export=pack('vvVV',2,1,0x040000,$bytes+4).pack('V4',0x12345678,$bytes,4,4);
    return pack('a32V6',"Unrealfile\x1A",$version,2,count($strings),$namesOffset,$objectsOffset,0)
        .$names.$headerData.$import.$export;
};
$p=tempnam(sys_get_temp_dir(),'ue1_staging_native_');
if(!is_string($p)){throw new RuntimeException('Temporary fixture unavailable');}
try {
    foreach([9,10] as $version) {
        file_put_contents($p,$resource($version));
        $parsed=$staging->parse($p,'original.res',0,'');
        $check("resource{$version}_native_counts",($parsed['header']['native_counts']??null)===[
            'names'=>1,'imports'=>1,'exports'=>1] && ($parsed['header']['version']??null)===$version);
        $lookup=$parsed['native_metadata']['resource_import_lookup'][0]??[];
        $check("resource{$version}_global_registry_dependency_key",
            ($lookup['resource_name']??null)==='ImportedTex'
            && ($lookup['resource_type']??null)===3
            && ($lookup['status']??null)==='runtime_registry_not_available'
            && array_key_exists('runtime_object_loaded',$lookup)
            && $lookup['runtime_object_loaded']===null
            && array_key_exists('loaded_resource_slot',$lookup)
            && $lookup['loaded_resource_slot']===null);
        $check("resource{$version}_native_records",($parsed['native_metadata']['source_record_family']??null)==='FResNameFileEntry'
            && ($parsed['native_metadata']['resource_imports'][0]['name']??null)==='ImportedTex'
            && ($parsed['native_metadata']['resource_exports'][0]['name']??null)==='ExportedTex'
            && ($parsed['native_metadata']['resource_headers'][0]['file_data_size']??null)===4);
        $check("resource{$version}_no_modern_fakes",$parsed['imports']===[] && $parsed['exports']===[]
            && ($parsed['header']['full_package_supported']??true)===false
            && ($parsed['parse_complete']??true)===false);
        $snapshot=$builder->fromOriginalNative(11,'ResourceOne',$parsed['names'],$parsed['native_metadata']);
        $check("resource{$version}_snapshot_source_identity",($snapshot['source_format']??null)==='original-ue1-native-partial'
            && ($snapshot['native_metadata']['serialized_version']??null)===$version
            && ($snapshot['native_metadata']['resource_imports'][0]['resource_type']??null)===3
            && ($snapshot['names'][0]['name_text']??null)==='ExampleName'
            && ($snapshot['verified']??true)===false);
        $check("resource{$version}_snapshot_no_fake_paths",$snapshot['imports']===[] && $snapshot['exports']===[]
            && $snapshot['paths']['imports']===[] && $snapshot['paths']['exports']===[]);
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $roundtrip=json_decode(gzdecode(gzencode($json,6)),true,512,JSON_THROW_ON_ERROR);
        $check("resource{$version}_snapshot_roundtrip",$roundtrip===$snapshot);
    }
    foreach([22,23] as $version) {
        file_put_contents($p,$unrealfile($version));
        $parsed=$staging->parse($p,'original.u',0,'');
        $check("unrealfile{$version}_native_counts",($parsed['header']['native_counts']??null)===[
            'names'=>2,'imports'=>1,'exports'=>1]);
        $check("unrealfile{$version}_typed_object_bases",($parsed['native_metadata']['source_record_family']??null)==='UObjectBase'
            && ($parsed['native_metadata']['object_bases'][0]['kind']??null)==='import'
            && ($parsed['native_metadata']['object_bases'][1]['kind']??null)==='export'
            && ($parsed['native_metadata']['object_bases'][1]['file_crc']??null)===0x12345678);
        $check("unrealfile{$version}_no_modern_fakes",$parsed['imports']===[] && $parsed['exports']===[]
            && ($parsed['header']['full_package_supported']??true)===false
            && ($parsed['native_metadata']['payload_decoded']??true)===false);
        $snapshot=$builder->fromOriginalNative(12,'ObjFile',$parsed['names'],$parsed['native_metadata']);
        $check("unrealfile{$version}_snapshot_native_and_names",($snapshot['names'][1]['name_text']??null)==='Thing'
            && ($snapshot['native_metadata']['serialized_version']??null)===$version
            && ($snapshot['native_metadata']['object_bases'][1]['name']??null)==='Thing');
        $check("unrealfile{$version}_snapshot_no_fabricated_outer",$snapshot['imports']===[] && $snapshot['exports']===[] && $snapshot['paths']['imports']===[]);
        $roundtrip=json_decode(gzdecode(gzencode(json_encode($snapshot,JSON_THROW_ON_ERROR),6)),true,512,JSON_THROW_ON_ERROR);
        $check("unrealfile{$version}_snapshot_roundtrip",$roundtrip===$snapshot);
    }
    file_put_contents($p,$resource(11));
    try{$staging->parse($p,'old.res',0,'');$reject=false;}catch(RuntimeException){$reject=true;}
    $check('unknown_resource_version_not_promoted',$reject);
    file_put_contents($p,$unrealfile(24));
    try{$staging->parse($p,'future.u',0,'');$reject=false;}catch(RuntimeException){$reject=true;}
    $check('unknown_unrealfile_version_not_promoted',$reject);
    $check('builder_rejects_untyped_source', (static function()use($builder):bool{
        try{$builder->fromOriginalNative(1,'Wrong',[],['serialized_format'=>'legacy_package']);return false;}
        catch(RuntimeException){return true;}
    })());
}finally{@unlink($p);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
