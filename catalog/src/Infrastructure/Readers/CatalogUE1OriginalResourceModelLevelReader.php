<?php
/**
 * Original Unreal 1996 v9/v10 UModel and ULevel native headers.
 * Epic v0.84a Src/Inc/UnRes.h::UModel and UnLevel.h::ULevel.
 * Compiler-transcribed 32-bit MSVC /Zp4 ABI proofs: UModel 392B,
 * ULevel 172B, FScale 24B, FBoundingVolume 48B, PBeginPlay 8B.
 * Both have zero resource data bytes (UModel::QuerySize=0;
 * ULevel uses UResource::QuerySize). No live UResource fixups.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceModelLevelReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open 1996 model/level resource');
        try {
            foreach($headers as $h){
                $type=(int)$h['resource_type'];
                if($type!==21&&$type!==22)continue;
                $model=$type===21;$required=$model?392:172;
                $typeName=$model?'UModel':'ULevel';
                $row=['export_index'=>(int)$h['export_index'],'native_class'=>$typeName,
                    'status'=>'incomplete_source_model_level_header',
                    'runtime_resource_references_resolved'=>false];
                if((int)$h['serialized_length']<$required){
                    if($strict)throw new RuntimeException('Original '.$typeName.' header smaller than source ABI');
                    $out[]=$row;continue;
                }
                if((int)$h['file_data_size']!==0){
                    throw new RuntimeException($typeName.' source QuerySize is zero; nonempty native data payload');
                }
                if(fseek($fp,(int)$h['serialized_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek original '.$typeName.' header');
                $buf=fread($fp,$required);
                if(!is_string($buf)||strlen($buf)!==$required)
                    throw new RuntimeException('Truncated '.$typeName.' native header');
                if($model){
                    $refs=array_values(unpack('V9',substr($buf,76,36)));
                    $keys=['vectors','points','bsp_nodes','bsp_surfs','vertex_pool',
                        'polys','light_mesh','bounds','terrain'];
                    $resourceReferences=array_combine($keys,$refs);
                    $out[]=[
                        'export_index'=>(int)$h['export_index'],'native_class'=>'UModel',
                        'status'=>'native_model_header_decoded',
                        'header_bytes'=>392,'serialized_data_bytes'=>0,
                        'resource_reference_raw_words'=>$resourceReferences,
                        'location'=>self::vector($buf,112),
                        'rotation_angles_signed'=>self::signed3($buf,128),
                        'pre_pivot'=>self::vector($buf,140),
                        'scale'=>self::scale($buf,156),
                        'csg_operation'=>self::signed((int)unpack('V',substr($buf,180,4))[1]),
                        'lock_type'=>(int)unpack('V',substr($buf,184,4))[1],
                        'color'=>(int)unpack('V',substr($buf,188,4))[1],
                        'poly_flags'=>(int)unpack('V',substr($buf,192,4))[1],
                        'model_flags'=>(int)unpack('V',substr($buf,196,4))[1],
                        'post_pivot'=>self::vector($buf,200),
                        'post_scale'=>self::scale($buf,216),
                        'temporary_scale'=>self::scale($buf,240),
                        'world_bounds'=>[
                            self::bound($buf,264),self::bound($buf,312)
                        ],
                        'reserved_32_bytes_hex'=>bin2hex(substr($buf,360,32)),
                        'extra_header_bytes'=>(int)$h['serialized_length']-392,
                        'runtime_resource_references_resolved'=>false
                    ];
                }else{
                    $refs=array_values(unpack('V5',substr($buf,88,20)));
                    $names=['model','actor_list','player_list','brush_array','misc'];
                    $refs=array_combine($names,$refs);
                    $textRefs=array_values(unpack('V16',substr($buf,108,64)));
                    $state=self::signed((int)unpack('V',substr($buf,76,4))[1]);
                    $flags=(int)unpack('V',substr($buf,80,4))[1];
                    $out[]=[
                        'export_index'=>(int)$h['export_index'],'native_class'=>'ULevel',
                        'status'=>'native_level_header_decoded',
                        'header_bytes'=>172,'serialized_data_bytes'=>0,
                        'level_state'=>$state,
                        'begin_play_flags_raw_word'=>$flags,
                        'begin_play_net_flags'=>[
                            'cooperative'=>($flags&1)!==0,'deathmatch'=>($flags&2)!==0,
                            'persistent'=>($flags&4)!==0,'no_monsters'=>($flags&8)!==0,
                        ],
                        'difficulty'=>ord($buf[84]),
                        'begin_play_padding_hex'=>bin2hex(substr($buf,85,3)),
                        'resource_reference_raw_words'=>$refs,
                        'text_block_reference_raw_words'=>$textRefs,
                        'extra_header_bytes'=>(int)$h['serialized_length']-172,
                        'runtime_resource_references_resolved'=>false,
                    ];
                }
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'model_level_headers'=>$out,'runtime_references_resolved'=>false];
    }
    /** @return array<string,mixed> */
    private static function vector(string $buffer,int $offset):array
    {
        return ['xyz'=>array_values(unpack('g3',substr($buffer,$offset,12))),
            'w_or_flags_raw_word'=>(int)unpack('V',substr($buffer,$offset+12,4))[1]];
    }
    /** @return array<int,int> */
    private static function signed3(string $buf,int $off):array
    {
        return array_map(self::signed(...),array_values(unpack('V3',substr($buf,$off,12))));
    }
    private static function signed(int $value):int
    {return $value>=0x80000000?$value-0x100000000:$value;}
    /** @return array<string,mixed> */
    private static function scale(string $buf,int $off):array
    {
        return ['vector'=>self::vector($buf,$off),
            'sheer_rate'=>(float)unpack('g',substr($buf,$off+16,4))[1],
            'sheer_axis'=>self::signed((int)unpack('V',substr($buf,$off+20,4))[1])];
    }
    /** @return array<string,mixed> */
    private static function bound(string $buf,int $off):array
    {
        return ['min'=>self::vector($buf,$off),
            'max'=>self::vector($buf,$off+16),
            'sphere'=>self::vector($buf,$off+32)];
    }
}
