#!/usr/bin/env php
<?php
/**
 * Unreal v0.867 native UObjectBase base/extra/class-preload serialization.
 * Source: Src/Engine/UnObj.cpp:46-68,1462-1476,
 *         Src/Inc/UnLinker.h:501-545,710-729,854-892.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileObjectBaseReader;

$checks=[];$failed=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void {
    $checks[$label]=$ok;if(!$ok){$failed[]=$label;}
};
$fixture=static function(int $version):array {
    $strings=['ImportedTex','Class','MyClass','MyTexture','Package','Texture'];
    $names='';
    foreach($strings as $s){$names.=$s."\0".pack('V',0x40000);}
    $nameOffset=56;
    $blocks="DATA".'hdr'.'data++'.'HEAD';
    $dataStart=$nameOffset+strlen($names);
    $objectOffset=$dataStart+strlen($blocks);
    $data1=$dataStart;
    $head1=$dataStart+4;
    $data2=$dataStart+7;
    $head2=$dataStart+13;
    $import=pack('vvVV',1,6,0x10,0);
    $class=pack('vvVV',3,2,0x55,$head1)
        .pack('V4',0xA1B2C3D4,$data1,3,4)
        .pack('VvVV',1,5,0xCAFED00D,32);
    $export=pack('vvVV',4,6,0x99,$head2)
        .pack('V4',0x33221100,$data2,4,6);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,3,count($strings),$nameOffset,$objectOffset,0);
    return ['bytes'=>$summary.$names.$blocks.$import.$class.$export,
        'objectOffset'=>$objectOffset,
        'head1'=>$head1,'data1'=>$data1,
        'head2'=>$head2,'data2'=>$data2];
};
$path=tempnam(sys_get_temp_dir(),'ue1_object_base_');
if(!is_string($path)){throw new RuntimeException('Temporary fixture not available');}
try {
    foreach([22,23] as $version) {
        $fx=$fixture($version);
        file_put_contents($path,$fx['bytes']);
        $result=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $bases=$result['object_bases']??[];
        $check("v{$version}_decoded_three_objects",($result['object_bases_decoded']??false)===true
            && ($result['object_payload_decoded']??true)===false
            && count($bases)===3);
        $check("v{$version}_import_12_bytes",($bases[0]['kind']??'')==='import'
            && ($bases[0]['name']??'')==='ImportedTex'
            && ($bases[0]['class_name']??'')==='Texture'
            && ($bases[0]['file_header_offset']??-1)===0
            && !isset($bases[0]['file_crc'])
            && ($bases[1]['serialized_offset']??null)===$fx['objectOffset']+12);
        $check("v{$version}_class_export_28_plus14",($bases[1]['kind']??'')==='export'
            && ($bases[1]['name']??'')==='MyClass'
            && ($bases[1]['class_name']??'')==='Class'
            && ($bases[1]['file_crc']??null)===0xA1B2C3D4
            && ($bases[1]['file_header_offset']??null)===$fx['head1']
            && ($bases[1]['file_data_offset']??null)===$fx['data1']
            && ($bases[2]['serialized_offset']??null)===$fx['objectOffset']+12+28+14);
        $check("v{$version}_native_class_preload",($bases[1]['class_preload']['parent_object_reference']??null)===1
            && ($bases[1]['class_preload']['package_name_index']??null)===5
            && ($bases[1]['class_preload']['package_name']??null)==='Package'
            && ($bases[1]['class_preload']['class_flags']??null)===0xCAFED00D
            && ($bases[1]['class_preload']['resource_this_header_size']??null)===32);
        $check("v{$version}_preload_parent_reference_index",($bases[1]['class_preload']['parent_object_index']??null)===0);
        $parentTarget=$bases[1]['class_preload']['parent_serialized_target']??[];
        $check("v{$version}_parent_resmap_import_identity",
            ($parentTarget['object_index']??null)===0
            && ($parentTarget['kind']??null)==='import'
            && ($parentTarget['name']??null)==='ImportedTex'
            && ($parentTarget['class_name']??null)==='Texture'
            && ($bases[1]['class_preload']['parent_runtime_resolution']??null)==='not_evaluated');
        $stageClass='UnrealDb\\Catalog\\Infrastructure\\Unverified\\CatalogUE1OriginalStagingMetadata';
        $stage=$stageClass::decode($path,['format'=>'early_unrealfile','version'=>$version]);
        $check("v{$version}_stage_resmap_identity",($stage['native_metadata']['object_bases'][1]['class_preload']['parent_serialized_target']['name']??null)==='ImportedTex'
            && ($stage['native_metadata']['object_bases'][1]['class_preload']['parent_runtime_resolution']??null)==='not_evaluated');
        $builderClass='UnrealDb\\Catalog\\Infrastructure\\Unverified\\CatalogUnverifiedMetadataSnapshotBuilder';
        $builder=new $builderClass(new PDO('sqlite::memory:'));
        $snapshot=$builder->fromOriginalNative(2,'EarlyFile',$stage['names'],$stage['native_metadata']);
        $stored=json_decode(json_encode($snapshot,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
        $check("v{$version}_stage_snapshot_resmap_roundtrip",$stored===$snapshot
            && ($snapshot['native_metadata']['object_bases'][1]['class_preload']['parent_serialized_target']['name']??null)==='ImportedTex'
            && ($snapshot['verified']??true)===false);
        $check("v{$version}_ordinary_export",($bases[2]['kind']??'')==='export'
            && ($bases[2]['name_index']??null)===4
            && ($bases[2]['class_index']??null)===6
            && ($bases[2]['file_data_size']??null)===6
            && ($bases[2]['file_header_size']??null)===4
            && !isset($bases[2]['class_preload']));
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
            .' --json --full '.escapeshellarg($path).' 2>&1';
        $output=[];$status=0;
        exec($cmd,$output,$status);
        $cli=json_decode(implode("\n",$output),true);
        $check("v{$version}_cli_exposes_real_objects_but_not_payload",$status===1
            && is_array($cli)
            && ($cli['full_validation']??null)==='FAILED'
            && ($cli['original_unrealfile_metadata_status']??null)==='DECODED'
            && ($cli['original_unrealfile_object_bases_status']??null)==='DECODED'
            && ($cli['original_unrealfile_object_bases'][1]['name']??null)==='MyClass');

        $broken=substr_replace($fx['bytes'],pack('v',7),$fx['objectOffset'],2);
        file_put_contents($path,$broken);
        try{CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$version}_bad_one_based_name_rejected",$rejected);

        $broken=substr_replace($fx['bytes'],pack('V',0x7FFFFFFF),$fx['objectOffset']+12+8,4);
        file_put_contents($path,$broken);
        try{CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$version}_out_of_bounds_export_offset_rejected",$rejected);

        // Epic FArchiveResLoad::operator<<(UObject*) converts positive
        // disk index to ResMap[index-1], validating against NumObjects.
        // The UClass preload parent pointer is a DWORD, not a name index.
        $parentWordOffset=$fx['objectOffset']+12+28;
        file_put_contents($path,substr_replace($fx['bytes'],pack('V',4),$parentWordOffset,4));
        try{CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$version}_invalid_class_parent_object_reference_rejected",$rejected);
        file_put_contents($path,substr_replace($fx['bytes'],pack('V',0),$parentWordOffset,4));
        $zeroParent=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $check("v{$version}_null_class_parent_reference",($zeroParent['object_bases'][1]['class_preload']['parent_object_reference']??-1)===0
            && array_key_exists('parent_object_index',$zeroParent['object_bases'][1]['class_preload'])
            && $zeroParent['object_bases'][1]['class_preload']['parent_object_index']===null
            && array_key_exists('parent_serialized_target',$zeroParent['object_bases'][1]['class_preload'])
            && $zeroParent['object_bases'][1]['class_preload']['parent_serialized_target']===null);

        // ResMap is populated after the full table is loaded: a class may
        // point forward to a serialized object not yet encountered.
        file_put_contents($path,substr_replace($fx['bytes'],pack('V',3),$parentWordOffset,4));
        $forwardParent=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $forward=$forwardParent['object_bases'][1]['class_preload']['parent_serialized_target']??[];
        $check("v{$version}_forward_resmap_export_identity",
            ($forward['object_index']??null)===2
            && ($forward['name']??null)==='MyTexture'
            && ($forward['kind']??null)==='export'
            && ($forwardParent['object_bases'][1]['class_preload']['parent_runtime_resolution']??null)==='not_evaluated');

        file_put_contents($path,substr($fx['bytes'],0,-3));
        try{CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$version}_truncated_object_table_rejected",$rejected);
    }

    $empty=pack('a32V6',"Unrealfile\x1A",23,0,0,0,0,0);
    file_put_contents($path,$empty);
    $parsed=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
    $check('zero_object_file_has_no_fabricated_entries',($parsed['object_bases_decoded']??false)===true
        && ($parsed['object_bases']??null)===[]);
} finally {@unlink($path);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
