<?php
/**
 * UE1 1996 (v9/v10) UVectors, UVertPool and UTerrain record reader.
 *
 * Source: v0.84a Src/Inc/UnRes.h UVectors(FVector), UTerrain(FTerrainIndex),
 * Src/Engine/UnModel.cpp Register/QueryHeaderReferences.
 * UVectors: 3 FLOAT32 = 12-byte FVector per DB record.
 * UVertPool: 2 INDEX (signed 32-bit) = 8-byte FVertPool per record;
 * UDatabase 84 bytes followed by NumSharedSides INT = 88-byte header.
 * UTerrain: 4 INT32 = 16-byte FTerrainIndex per DB record;
 * UDatabase base 84 bytes plus 8 LayerFlags DWORD, 8 HeightMaps pointers,
 * 8 TileMaps pointers, 128 TileRefs pointers = 608-byte extension (692 total).
 * Header resource words are NOT runtime resolved resources.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceGeometryReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict = false): array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $records=[];$fp=fopen($path,'rb');
        if(!$fp) throw new RuntimeException('Cannot open original UE1 geometry resource');
        try {
            foreach($db as $i=>$entry){
                $type=(int)$entry['resource_type'];
                if(!in_array($type,[16,29,34],true)) continue;
                $h=$headers[$i];
                $name=match($type){16=>'UVectors',29=>'UVertPool',34=>'UTerrain'};
                $row=['export_index'=>(int)$h['export_index'],'native_class'=>$name,
                    'status'=>'incomplete_source_geometry_header',
                    'runtime_resource_fixups_evaluated'=>false];
                $minHeader=match($type){16=>84,29=>88,34=>692};
                if(($entry['status']??'')!=='native_database_num_max_decoded'
                    ||(int)$h['serialized_length']<$minHeader){
                    if($strict) throw new RuntimeException($name.' header is shorter than the native source layout');
                    $records[]=$row;continue;
                }
                $count=(int)$entry['database_count'];
                $bytesPer=match($type){16=>12,29=>8,34=>16};
                $length=(int)$h['file_data_size'];
                if($count>intdiv(PHP_INT_MAX,$bytesPer)||$length!==$count*$bytesPer)
                    throw new RuntimeException($name.' QueryMinSize does not match file data bytes');
                $headerExtra=null;
                if($type===29){
                    if(fseek($fp,(int)$h['serialized_offset']+84,SEEK_SET)!==0)
                        throw new RuntimeException('Cannot seek UVertPool NumSharedSides');
                    $bytes=fread($fp,4);
                    if(!is_string($bytes)||strlen($bytes)!==4)
                        throw new RuntimeException('Truncated UVertPool native header');
                    $v=(int)unpack('V',$bytes)[1];
                    $headerExtra=['num_shared_sides'=>$v>=0x80000000?$v-0x100000000:$v];
                }elseif($type===34){
                    if(fseek($fp,(int)$h['serialized_offset']+84,SEEK_SET)!==0)
                        throw new RuntimeException('Cannot seek UTerrain derived header');
                    $data=fread($fp,608);
                    if(!is_string($data)||strlen($data)!==608)
                        throw new RuntimeException('Truncated UTerrain native extension');
                    $wordArray=static function(int $start,int $n)use($data):array{
                        return array_values(unpack('V'.$n,substr($data,$start,$n*4)));
                    };
                    $headerExtra=[
                        'layer_flags_raw_words'=>$wordArray(0,8),
                        'height_map_raw_words'=>$wordArray(32,8),
                        'tile_map_raw_words'=>$wordArray(64,8),
                        'tile_reference_raw_words'=>$wordArray(96,128),
                        'runtime_texture_references_resolved'=>false,
                    ];
                }
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek native geometry data');
                $items=[];
                for($j=0;$j<$count;$j++){
                    $bytes=fread($fp,$bytesPer);
                    if(!is_string($bytes)||strlen($bytes)!==$bytesPer)
                        throw new RuntimeException('Truncated geometry record');
                    if($type===16){
                        $nums=array_values(unpack('g3',$bytes));
                        $items[]=['index'=>$j,'x'=>(float)$nums[0],
                            'y'=>(float)$nums[1],'z'=>(float)$nums[2]];
                    }elseif($type===29){
                        $nums=array_values(unpack('V2',$bytes));
                        $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                        $items[]=['index'=>$j,'point_vertex'=>$signed((int)$nums[0]),
                            'shared_side'=>$signed((int)$nums[1])];
                    }else{
                        $numbers=array_values(unpack('V4',$bytes));
                        $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                        $items[]=['index'=>$j,'grid_x0'=>$signed((int)$numbers[0]),
                            'grid_x1'=>$signed((int)$numbers[1]),
                            'grid_y0'=>$signed((int)$numbers[2]),
                            'grid_y1'=>$signed((int)$numbers[3])];
                    }
                }
                $records[]=[
                    'export_index'=>(int)$h['export_index'],
                    'native_class'=>$name,'status'=>'native_geometry_records_decoded',
                    'record_count'=>$count,'record_capacity'=>(int)$entry['database_capacity'],
                    'record_size_bytes'=>$bytesPer,
                    'serialized_header_bytes'=>$minHeader,
                    'additional_header_bytes'=>(int)$h['serialized_length']-$minHeader,
                    'record_values'=>$items,
                    'terrain_header'=>$headerExtra,
                    'runtime_resource_fixups_evaluated'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','geometry_records'=>$records,
            'runtime_references_resolved'=>false];
    }
}
