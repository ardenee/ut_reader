#!/usr/bin/env php
<?php
/** v9/v10 source-defined UVectors + UTerrain original native fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceGeometryReader as Geo;

$results=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$results,&$bad):void{
    $results[$name]=$ok;if(!$ok)$bad[]=$name;
};
$make=static function(int $version,int $type):array{
    $nameOff=strlen("Unreal Resource\x1A\0");
    $names=pack('a32V','LOCATION',0);
    $dataOff=$nameOff+strlen($names);
    $data=match($type){
        16=>pack('g6',1.25,-2.5,3.75,7.0,0.5,-1.0),
        29=>pack('V4',1,2,12,0xffffffff),
        34=>pack('V8',0,256,4,128,32,64,72,88),
    };
    $exportOff=$dataOff+strlen($data);
    $headSize=match($type){16=>84,29=>88,34=>692};
    $ex=pack('a32VVV','GeoOne',1,$type,$headSize);
    $headerOff=$exportOff+strlen($ex);
    $extension=$type===29?pack('V',4):'';
    if($type===34){
        $extension=pack('V8',1,0,0,0,0,0,0,0)
            .pack('V8',2,0,0,0,0,0,0,0)
            .pack('V8',3,0,0,0,0,0,0,0)
            .pack('V128',4,...array_fill(0,127,0));
        if(strlen($extension)!==608)throw new RuntimeException('Wrong source UTerrain extension fixture size');
    }
    $native=pack('V2a32V9',0,$type,'GeoOne',0,0,1,0xffffffff,0,$dataOff,strlen($data),0,0)
        .pack('V2',2,2).$extension;
    if(strlen($native)!==$headSize)throw new RuntimeException('Wrong native geometry header fixture size');
    $trailer=pack('V9',$version,1,0,1,$nameOff,0,$exportOff,$headerOff,$headSize)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>"Unreal Resource\x1A\0".$names.$data.$ex.$native.'[SUMMARY]'.$trailer,
        'native'=>$headerOff,'export'=>$exportOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1geometry');
if(!is_string($tmp))throw new RuntimeException('Unable to create fixture');
try {
    foreach([9,10] as $ver)foreach([16,29,34] as $type){
        $fx=$make($ver,$type);file_put_contents($tmp,$fx['bytes']);
        $r=Geo::read($tmp,true)['geometry_records'][0]??[];
        $id="v{$ver}_type{$type}";
        $check("{$id}_native_record_dimensions",
            ($r['status']??null)==='native_geometry_records_decoded'
            && $r['record_count']===2
            && $r['record_size_bytes']===match($type){16=>12,29=>8,34=>16}
            && $r['serialized_header_bytes']===match($type){16=>84,29=>88,34=>692}
            && $r['additional_header_bytes']===0);
        if($type===16){
            $check("{$id}_float_values",
                ($r['record_values'][0]['x']??null)===1.25
                && ($r['record_values'][0]['y']??null)===-2.5
                && ($r['record_values'][1]['z']??null)===-1.0
                && $r['terrain_header']===null);
        }elseif($type===29){
            $check("{$id}_vertex_pool_signed_indices",
                ($r['record_values'][0]['point_vertex']??null)===1
                && ($r['record_values'][0]['shared_side']??null)===2
                && ($r['record_values'][1]['point_vertex']??null)===12
                && ($r['record_values'][1]['shared_side']??null)===-1
                && ($r['terrain_header']['num_shared_sides']??null)===4);
        }else{
            $check("{$id}_terrain_grid_and_raw_header",
                ($r['record_values'][0]['grid_x1']??null)===256
                && ($r['record_values'][1]['grid_y1']??null)===88
                && ($r['terrain_header']['layer_flags_raw_words'][0]??null)===1
                && ($r['terrain_header']['height_map_raw_words'][0]??null)===2
                && ($r['terrain_header']['tile_map_raw_words'][0]??null)===3
                && ($r['terrain_header']['tile_reference_raw_words'][0]??null)===4
                && ($r['terrain_header']['runtime_texture_references_resolved']??true)===false);
        }
        $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unreal_resource','version'=>$ver]
        );
        $staged=$stage['native_metadata']['resource_geometry_records']['geometry_records'][0]??[];
        $check("{$id}_staging_does_not_claim_runtime",
            ($staged['record_count']??null)===2
            && ($staged['runtime_resource_fixups_evaluated']??true)===false
            && ($stage['parse_complete']??true)===false);
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',3),$fx['native']+76,4));
        try{Geo::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
        $check("{$id}_bad_count_rejected",$rejected);
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',match($type){16=>80,29=>84,34=>688}),$fx['export']+40,4));
        $relaxed=Geo::read($tmp,false)['geometry_records'][0]??[];
        try{Geo::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
        $check("{$id}_short_header_unverified",
            ($relaxed['status']??null)==='incomplete_source_geometry_header'&&$rejected);
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($results)-count($bad),
    'total'=>count($results),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
