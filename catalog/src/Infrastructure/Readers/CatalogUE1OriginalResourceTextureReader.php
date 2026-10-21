<?php
/**
 * Original Unreal 1996 native UTexture derived header (RES_Texture=5).
 *
 * Source: v0.84a Src/Inc/UnRes.h::UTexture and
 * Src/Engine/UnTex.cpp::UTexture::Register/QuerySize/QueryHeaderReferences.
 * 32-bit MSVC structure, UResource prefix 76, derived UTexture 172
 * bytes, sizeof(UTexture)==248 including 2-byte FName fields.
 *
 * Pointer slots are ONLY on-disk address words, never object identities:
 * Epic QueryHeaderReferences + loader registry perform actual fixup.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceTextureReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalResourceHeaderReader::read($path);
        $results=[];$fp=fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original UTexture resource');
        try{
            foreach($meta['resource_headers'] as $h){
                if((int)$h['resource_type']!==5)continue;
                $off=(int)$h['serialized_offset'];$len=(int)$h['serialized_length'];
                $r=['export_index'=>$h['export_index'],'source_type'=>'RES_Texture',
                    'status'=>'incomplete_native_texture_header','pointers_are_runtime_references'=>false];
                if($len<248){
                    if($strict)throw new RuntimeException('1996 UTexture header shorter than 248-byte source layout');
                    $results[]=$r;continue;
                }
                if(fseek($fp,$off+76)!==0)throw new RuntimeException('Cannot seek original UTexture body');
                $bytes=fread($fp,172);
                if(!is_string($bytes)||strlen($bytes)!==172)throw new RuntimeException('Short native texture header');
                $u32=static fn(int $i):int=>(int)unpack('V',substr($bytes,$i,4))[1];
                $u16=static fn(int $i):int=>(int)unpack('v',substr($bytes,$i,2))[1];
                $f32=static fn(int $i):float=>(float)unpack('g',substr($bytes,$i,4))[1];
                $mips=[];$size=$u32(92); // Derived offset 92 is UTexture::DataSize
                for($i=0;$i<8;$i++){
                    $m=$u32(104+$i*4); // UTexture::MipOfs[] at absolute 180
                    if($m!==0xffffffff && $m>$size){
                        throw new RuntimeException('Native 1996 texture mip offset exceeds declared DataSize');
                    }
                    $mips[]=$m===0xffffffff?null:$m;
                }
                $classRawRefs=[
                    'class_pointer_word'=>$u32(0),
                    'palette_pointer_word'=>$u32(4),
                    'microtexture_pointer_word'=>$u32(8),
                    'fire_params_pointer_word'=>$u32(12),
                    'footstep_sound_pointer_word'=>$u32(36),
                    'hit_sound_pointer_word'=>$u32(40),
                ];
                $declaredMatches=$size===(int)$h['file_data_size'];
                if($strict&&!$declaredMatches)throw new RuntimeException('1996 UTexture QueryMinSize differs from serialized FileDataSize');
                $r=[
                    'export_index'=>$h['export_index'],
                    'source_type'=>'RES_Texture','status'=>'native_texture_header_decoded',
                    'serialized_header_bytes'=>248,'additional_header_bytes'=>$len-248,
                    'name_indices'=>[
                        'family'=>$u16(16),'unused'=>$u16(18),
                    ],
                    'diffuse_coeff'=>$f32(24),'specular_coeff'=>$f32(28),
                    'friction_coeff'=>$f32(32),
                    'polygon_flags'=>$u32(44),'untiled_flags'=>$u32(48),
                    'width'=>$u32(84),'height'=>$u32(88),
                    'data_size'=>$size,'color_bytes'=>$u32(96),
                    'mip_zero_stored_bytes'=>strtoupper(bin2hex(substr($bytes,100,4))),
                    'mip_offsets'=>$mips,
                    'bits_u'=>ord($bytes[136]),'bits_v'=>ord($bytes[137]),
                    'data_size_matches_source'=>$declaredMatches,
                    'native_pointer_words_present'=>$classRawRefs,
                    'pointer_values_are_runtime_object_refs'=>false,
                    'pointers_are_runtime_references'=>false,
                    'actual_resource_fixups_evaluated'=>false,
                ];
                $results[]=$r;
            }
        }finally{fclose($fp);}
        return ['source_format'=>'early_unreal_resource',
            'textures'=>$results,'runtime_references_resolved'=>false];
    }
}
