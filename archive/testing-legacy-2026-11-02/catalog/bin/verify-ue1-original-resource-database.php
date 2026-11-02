#!/usr/bin/env php
<?php
/** Source-defined 1996 UDatabase and UPalette fixed payload fixture. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceDatabaseReader as DB;
$checks=[];$failed=[];
$check=static function(string $name,bool $yes)use(&$checks,&$failed):void{
 $checks[$name]=$yes;if(!$yes){$failed[]=$name;}
};
$make=static function(int $version):array{
    $prefix="Unreal Resource\x1A\0";
    $names=pack('a32V','PaletteName',0x800);
    $nameOff=strlen($prefix);
    $dataOff=$nameOff+strlen($names);
    $pixels=str_repeat("\x11\x22\x33\xff",256);
    $expOff=$dataOff+strlen($pixels);
    $export=pack('a32VVV','PaletteOne',1,7,84);
    $hOff=$expOff+strlen($export);
    $native=pack('V2a32V9',0,7,'PaletteOne',0,0,1,0xFFFFFFFF,0,$dataOff,1024,0,0)
        .pack('V2',256,256);
    if(strlen($native)!==84){throw new RuntimeException('Bad database fixture');}
    $tail=pack('V9',$version,1,0,1,$nameOff,0,$expOff,$hOff,84)
       .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$pixels.$export.$native.'[SUMMARY]'.$tail,
        'header'=>$hOff,'export'=>$expOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1o06db');
if(!is_string($tmp)){throw new RuntimeException('Temp fixture unavailable');}
try{
 foreach([9,10] as $version){
    $f=$make($version);file_put_contents($tmp,$f['bytes']);
    $reader=DB::read($tmp,true);
    $row=$reader['database_headers'][0]??[];
    $check("v{$version}_palette_num_max_native",
        ($row['status']??null)==='native_database_num_max_decoded'
        && ($row['native_class']??null)==='UPalette'
        && $row['database_count']===256
        && $row['database_capacity']===256
        && $row['serialized_field_offset']===$f['header']+76
        && $row['remaining_class_header_bytes']===0);
    $check("v{$version}_palette_fixed_data_size",
        ($row['palette_fixed_data_bytes']??null)===1024
        && ($row['palette_data_size_matches_source']??false)===true);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unreal_resource','version'=>$version]
    );
    $stageDB=$stage['native_metadata']['resource_database_headers']['database_headers'][0]??[];
    $check("v{$version}_source_fields_preserved_unverified",
        ($stageDB['database_count']??null)===256
        && ($stage['parse_complete']??true)===false
        && ($stage['native_metadata']['payload_decoded']??true)===false);
    // Native Num may not exceed Max.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',257),$f['header']+76,4));
    try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_invalid_num_max_rejected",$reject);
    // An incomplete source-derived header is an explicit status in
    // metadata-only mode and strict-mode failure for real validation.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',80),$f['export']+40,4));
    $relaxed=DB::read($tmp,false)['database_headers'][0];
    try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_short_class_header_not_falsely_decoded",
        $relaxed['status']==='incomplete_native_database_header'&&$reject);
    // Wrong fixed palette pixel length is never promoted to source match.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',512),$f['header']+64,4));
    try{DB::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_wrong_palette_size_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
