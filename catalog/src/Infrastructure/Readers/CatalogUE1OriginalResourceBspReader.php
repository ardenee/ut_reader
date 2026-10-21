<?php
/**
 * Original 1996 UE1 v9/v10 UBspNodes and UBspSurfs native records.
 * Epic v0.84a Src/Inc/UnRes.h FBspNode, FBspSurf, FZoneProperties
 * and Src/Engine/UnModel.cpp Register. 32-bit /Zp4 native ABI checked
 * by isolated MSVC static_assert probe in compliance/evidence/o06.
 * Never resolve serialized UTexture/UModel/actor pointers as runtime refs.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalResourceBspReader
{
    private const NODE_HEADER_BYTES=1636;  // UResource76 + UDatabase8 + 16 + 64*24
    private const NODE_BYTES=64;
    private const SURFACE_BYTES=48;

    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $result=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original BSP resource');
        try{
            foreach($db as $i=>$entry){
                $type=(int)$entry['resource_type'];
                if($type!==17 && $type!==18)continue;
                $h=$headers[$i];
                $isNodes=$type===17;
                $kind=$isNodes?'UBspNodes':'UBspSurfs';
                $minHeader=$isNodes?self::NODE_HEADER_BYTES:84;
                $stride=$isNodes?self::NODE_BYTES:self::SURFACE_BYTES;
                $row=['export_index'=>(int)$h['export_index'],
                    'native_class'=>$kind,
                    'status'=>'incomplete_original_bsp_header',
                    'runtime_references_resolved'=>false];
                if(($entry['status']??null)!=='native_database_num_max_decoded'
                    ||(int)$h['serialized_length']<$minHeader){
                    if($strict)throw new RuntimeException($kind.' native header is incomplete');
                    $result[]=$row;continue;
                }
                $num=(int)$entry['database_count'];
                $max=(int)$entry['database_capacity'];
                if($num>250000 || $max>intdiv(PHP_INT_MAX,$stride)
                    ||$max*$stride!==(int)$h['file_data_size'])
                    throw new RuntimeException($kind.' native count/stride is invalid');
                $zones=[];$zoneCount=null;$uniquePlanes=null;
                if($isNodes){
                    if(fseek($fp,(int)$h['serialized_offset']+84,SEEK_SET)!==0)
                        throw new RuntimeException('Cannot seek native UBspNodes header');
                    $extension=fread($fp,1552);
                    if(!is_string($extension)||strlen($extension)!==1552)
                        throw new RuntimeException('Truncated original UBspNodes zone header');
                    $zoneCount=(int)unpack('V',substr($extension,0,4))[1];
                    $uniquePlanes=(int)unpack('V',substr($extension,4,4))[1];
                    if($zoneCount>64)throw new RuntimeException('BSP zones exceed Epic MAX_ZONES 64');
                    for($j=0;$j<64;$j++){
                        $base=16+$j*24;$z=substr($extension,$base,24);
                        if($j<$zoneCount){
                            $zones[]=[
                                'index'=>$j,
                                'actor_index'=>self::signed((int)unpack('V',substr($z,0,4))[1]),
                                'reserved_word'=>(int)unpack('V',substr($z,4,4))[1],
                                'connectivity_mask_le_hex'=>bin2hex(substr($z,8,8)),
                                'visibility_mask_le_hex'=>bin2hex(substr($z,16,8)),
                            ];
                        }
                    }
                }
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek native BSP records');
                $records=[];
                for($j=0;$j<$num;$j++){
                    $v=fread($fp,$stride);
                    if(!is_string($v)||strlen($v)!==$stride)
                        throw new RuntimeException('Truncated native BSP record');
                    if($isNodes){
                        $plane=array_values(unpack('g3',substr($v,0,12)));
                        $indices=array_values(unpack('V8',substr($v,24,32)));
                        $count=ord($v[54]);
                        if($count>16)throw new RuntimeException('BSP node exceeds source MAX_NODE_VERTICES');
                        $records[]=[
                            'index'=>$j,'plane_xyz'=>$plane,
                            'plane_w_or_flags_raw_word'=>(int)unpack('V',substr($v,12,4))[1],
                            'zone_mask_le_hex'=>bin2hex(substr($v,16,8)),
                            'node_flags'=>(int)$indices[0],
                            'vertex_pool_index'=>self::signed((int)$indices[1]),
                            'surface_index'=>self::signed((int)$indices[2]),
                            'back_index'=>self::signed((int)$indices[3]),
                            'front_index'=>self::signed((int)$indices[4]),
                            'coplanar_index'=>self::signed((int)$indices[5]),
                            'bound_index'=>self::signed((int)$indices[6]),
                            'zones'=>[ord($v[52]),ord($v[53])],
                            'vertex_count'=>$count,'pad_byte'=>ord($v[55]),
                            'dynamic_indices'=>[
                                self::signed((int)unpack('V',substr($v,56,4))[1]),
                                self::signed((int)unpack('V',substr($v,60,4))[1]),
                            ],
                        ];
                    }else{
                        $indices=array_values(unpack('V9',substr($v,0,36)));
                        $records[]=[
                            'index'=>$j,'texture_reference_raw_word'=>(int)$indices[0],
                            'brush_reference_raw_word'=>(int)$indices[1],
                            'poly_flags'=>(int)$indices[2],
                            'base_point_index'=>self::signed((int)$indices[3]),
                            'normal_index'=>self::signed((int)$indices[4]),
                            'texture_u_index'=>self::signed((int)$indices[5]),
                            'texture_v_index'=>self::signed((int)$indices[6]),
                            'light_mesh_index'=>self::signed((int)$indices[7]),
                            'brush_poly_index'=>self::signed((int)$indices[8]),
                            'pan_u'=>ord($v[36]),'pan_v'=>ord($v[37]),
                            'actor_index'=>self::signed((int)unpack('V',substr($v,40,4))[1]),
                            'last_start_y'=>self::signed16((int)unpack('v',substr($v,44,2))[1]),
                            'last_end_y'=>self::signed16((int)unpack('v',substr($v,46,2))[1]),
                        ];
                    }
                }
                $result[]=[
                    'export_index'=>(int)$h['export_index'],'native_class'=>$kind,
                    'status'=>'native_bsp_records_decoded',
                    'record_count'=>$num,'record_capacity'=>$max,
                    'record_stride'=>$stride,'header_bytes'=>$minHeader,
                    'additional_header_bytes'=>(int)$h['serialized_length']-$minHeader,
                    'zone_count'=>$zoneCount,'unique_plane_count'=>$uniquePlanes,
                    'zones'=>$zones,'records'=>$records,
                    'runtime_references_resolved'=>false,
                    'zone_visibility_semantics_evaluated'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','bsp_resources'=>$result,
            'runtime_references_resolved'=>false];
    }
    private static function signed(int $v):int{return $v>=0x80000000?$v-0x100000000:$v;}
    private static function signed16(int $v):int{return $v>=0x8000?$v-0x10000:$v;}
}
