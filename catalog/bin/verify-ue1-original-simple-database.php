#!/usr/bin/env php
<?php
/** Native 1996 UBuffer/UArray/UTextBuffer/UEnum record fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSimpleDatabaseReader as R;
$checks=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{$checks[$name]=$ok;if(!$ok)$bad[]=$name;};
$make=static function(int $version,int $type,string $payload,int $n,int $extra=0):array{
    $prefix="Unreal Resource\x1A\0";
    $names=pack('a32V','NAME',0);
    $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
    $exportOff=$dataOff+strlen($payload);
    $headerSize=84+($type===4?4:0);
    $export=pack('a32VVV','One',1,$type,$headerSize);
    $headerOff=$exportOff+strlen($export);
    $native=pack('V2a32V9',0,$type,'One',0,0,1,0xffffffff,0,$dataOff,strlen($payload),0,0)
        .pack('V2',$n,$n).($type===4?pack('V',$extra):'');
    if(strlen($native)!==$headerSize)throw new RuntimeException('Database source fixture invalid');
    $tail=pack('V9',$version,1,0,1,$nameOff,0,$exportOff,$headerOff,$headerSize)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$payload.$export.$native.'[SUMMARY]'.$tail,
        'native'=>$headerOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1dbrecords');
if(!is_string($tmp))throw new RuntimeException('No test temp file');
try{
 foreach([9,10] as $version){
    $fixtures=[
        [1,"A\x00B",3,'byte_stream'],
        [3,pack('V2',1,0),2,'resource_reference_words'],
        [4,"hello\x00",6,'source_text_bytes'],
        [35,pack('v2',2,3),2,'name_index_words'],
    ];
    foreach($fixtures as [$type,$payload,$n,$kind]){
        $f=$make($version,$type,$payload,$n,4);
        file_put_contents($tmp,$f['bytes']);
        $r=R::read($tmp,true)['simple_database_records'][0]??[];
        $check("v{$version}_type{$type}_native_record",
            ($r['status']??null)==='native_database_records_decoded'
            && ($r['record_kind']??null)===$kind
            && ($r['record_count']??null)===$n
            && ($r['payload_bytes']??null)===strlen($payload)
            && ($r['data_sha256']??null)===hash('sha256',$payload)
            && $r['runtime_references_resolved']===false);
        if($type===4){
            $check("v{$version}_textbuffer_cursor",
                ($r['text_editor_cursor']??null)===4);
        } elseif($type===3){
            $check("v{$version}_array_resource_words_not_resolved",
                ($r['serialized_reference_words']??null)===[1,0]
                && $r['reference_words_are_runtime_resolved']===false);
        } elseif($type===35){
            $check("v{$version}_enum_native_name_words_not_resolved",
                ($r['serialized_reference_words']??null)===[2,3]
                && $r['reference_words_are_runtime_resolved']===false);
        }else{
            $check("v{$version}_raw_binary_not_reinterpreted",
                array_key_exists('serialized_reference_words',$r)
                && $r['serialized_reference_words']===null);
        }
        $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unreal_resource','version'=>$version]
        );
        $stagedRecord=$staged['native_metadata']['resource_simple_database_records']['simple_database_records'][0]??[];
        $check("v{$version}_type{$type}_staged_native_unverified",
            ($stagedRecord['record_count']??null)===$n
            && $staged['parse_complete']===false);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',$n+1),$f['native']+76,4));
        try{R::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$version}_type{$type}_bad_record_count_rejected",$reject);
    }
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT),PHP_EOL;
exit($bad===[]?0:1);
