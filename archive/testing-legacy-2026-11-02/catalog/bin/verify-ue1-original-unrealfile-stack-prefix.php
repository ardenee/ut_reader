#!/usr/bin/env php
<?php
/**
 * Epic v0.867 Src/Inc/UnScript.h FExecStackMain native serialization.
 * Source-defined binary fixtures, NOT a compiled original 1997 oracle.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackReader as Stack;

$checks=[];$bad=[];
$check=static function(string $name,bool $pass)use(&$checks,&$bad):void{
    $checks[$name]=$pass;if(!$pass){$bad[]=$name;}
};
$fixture=static function(int $version):array{
    $n=['Script','Object','Actor'];$names='';
    foreach($n as $item){$names.=$item."\0".pack('V',0);}
    $nameOffset=56;$headerOffset=$nameOffset+strlen($names);
    $headerEmpty=pack('V2',0,0).str_repeat("\xff",8);
    $headerFull=pack('V3vV2',1,7,1,25,0x12345678,0x9abcdef0);
    if(strlen($headerEmpty)!==16||strlen($headerFull)!==22){
        throw new RuntimeException('Source-defined frame bytes mismatch');
    }
    $objOffset=$headerOffset+strlen($headerEmpty)+strlen($headerFull);
    $objects=pack('vvVV',1,2,0,0)
        .pack('vvVV',2,2,0,$headerOffset).pack('V4',0,0,16,0)
        .pack('vvVV',3,2,0,$headerOffset+16).pack('V4',0,0,22,0);
    $sum=pack('a32V6',"Unrealfile\x1A",$version,3,3,$nameOffset,$objOffset,0);
    return ['bytes'=>$sum.$names.$headerEmpty.$headerFull.$objects,
        'header'=>$headerOffset,'objects'=>$objOffset];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1o06stack');
if(!is_string($tmp)){throw new RuntimeException('No temp fixture');}
try{
    foreach([22,23] as $version){
        $fx=$fixture($version);
        file_put_contents($tmp,$fx['bytes']);
        $out=Stack::read($tmp,true)['stack_prefixes'];
        $check("v{$version}_frame_cardinality",count($out)===2);
        $check("v{$version}_null_stack",
            ($out[0]['status']??null)==='native_stack_prefix_decoded'
            && ($out[0]['link_class_ref']??null)===0
            && ($out[0]['script_ref']??null)===0
            && ($out[0]['serialized_prefix_bytes']??null)===16
            && ($out[0]['probe_mask_le_hex']??null)===str_repeat('FF',8)
            && ($out[0]['class_member_bytes_remaining']??null)===0);
        $check("v{$version}_referenced_script_stack",
            ($out[1]['link_class_ref']??null)===1
            && ($out[1]['link_class_index']??null)===0
            && ($out[1]['link_node_index']??null)===7
            && ($out[1]['script_ref']??null)===1
            && ($out[1]['script_index']??null)===0
            && ($out[1]['code_offset']??null)===25
            && ($out[1]['probe_mask_le_hex']??null)==='78563412F0DEBC9A'
            && ($out[1]['serialized_prefix_bytes']??null)===22
            && ($out[1]['class_member_bytes_remaining']??null)===0);
        $check("v{$version}_no_runtime_object_claim",
            $out[1]['member_properties_decoded']===false
            && $out[1]['runtime_stack_constructed']===false);
        $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unrealfile','version'=>$version]
        );
        $stageStack=$stage['native_metadata']['serialized_stack_prefixes']['stack_prefixes']??[];
        $check("v{$version}_staging_keeps_native_prefix_unverified",
            count($stageStack)===2
            && ($stageStack[1]['status']??null)==='native_stack_prefix_decoded'
            && ($stage['parse_complete']??true)===false
            && ($stage['native_metadata']['payload_decoded']??true)===false);
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
            .' --json --full '.escapeshellarg($tmp);
        $cliLines=[];$exit=0;exec($command,$cliLines,$exit);
        $cli=json_decode(implode("\n",$cliLines),true);
        $check("v{$version}_cli_native_stack_without_false_full_validation",
            $exit===1&&is_array($cli)
            && ($cli['full_validation']??null)==='FAILED'
            && ($cli['original_unrealfile_stack_prefixes']['stack_prefixes'][1]['script_index']??null)===0);

        // A one-based object reference beyond Summary.NumObjects is invalid.
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',4),$fx['header']+16,4));
        try{Stack::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
        $check("v{$version}_invalid_resmap_ref_rejected",$rejected);
        // Valid serialized header still needs >=16 bytes of FExecStackMain.
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',15),$fx['objects']+12+20,4));
        try{Stack::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
        $check("v{$version}_short_stack_rejected_in_strict_mode",$rejected);
        $relaxed=Stack::read($tmp,false)['stack_prefixes'];
        $check("v{$version}_short_stack_marked_incomplete",
            ($relaxed[0]['status']??null)==='invalid_or_incomplete_stack_prefix');
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
