<?php
/**
 * Original v0.867 UTexture::SerializeHeader v22/v23.
 *
 * Epic Src/Inc/UnObj.h: UDatabase Num/Max 8 bytes, 5 object references,
 * 4 FLOATs, 2 sound references, 2 DWORD flags, UBits/VBits BYTE,
 * 3 INT dimensions, CameraCaps DWORD, 3 FColor x4 channels, 12 mip
 * offsets DWORD. Total original native header members = 138 bytes.
 * All mip pixel bytes live in the UDatabase::SerializeData byte stream
 * and their interpretation belongs to O07. No PostLoad mip fixup here.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileTextureHeaderReader
{
    private const NATIVE_BYTES=138;
    private const OBJECT_FIELDS=['BumpMap','DetailTexture','MacroTexture','AnimNext','Palette',
        'FootstepSound','HitSound'];

    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $count=(int)$meta['summary']['object_count'];
        $stack=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $by=[];foreach($stack as $s)$by[(int)$s['object_index']]=$s;
        $out=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Unable to open original UTexture archive');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Texture')!==0)continue;
                $s=$by[(int)$o['index']]??[];
                $partial=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UTexture','status'=>'incomplete_original_texture_header',
                    'texture_pixels_decoded'=>false,'runtime_mipmaps_fixed'=>false];
                if(($s['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($s['class_member_bytes_remaining']??0)<self::NATIVE_BYTES){
                    if($strict)throw new RuntimeException('Original UTexture header shorter than 138 source bytes');
                    $out[]=$partial;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('Cannot seek UTexture native archive');
                $bytes=fread($fp,self::NATIVE_BYTES);
                if(!is_string($bytes)||strlen($bytes)!==self::NATIVE_BYTES)
                    throw new RuntimeException('Truncated native UTexture archive');
                $cursor=0;
                $read=static function(int $n)use($bytes,&$cursor):string{
                    if($n<0||$n>strlen($bytes)-$cursor)
                        throw new RuntimeException('UTexture source member beyond declared header');
                    $val=substr($bytes,$cursor,$n);$cursor+=$n;return $val;
                };
                $u32=static fn():int=>(int)unpack('V',$read(4))[1];
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $num=$u32();$max=$u32();
                if($num>$max||$max>0x7fffffff)
                    throw new RuntimeException('Original UTexture invalid Num/Max');
                if($num!==(int)$o['file_data_size'])
                    throw new RuntimeException('UTexture UDatabase SerializeData Num differs from file byte count');
                $refs=[];
                foreach(array_slice(self::OBJECT_FIELDS,0,5) as $field)$refs[$field]=$u32();
                $floats=array_values(unpack('g4',$read(16)));
                foreach(array_slice(self::OBJECT_FIELDS,5) as $field)$refs[$field]=$u32();
                foreach($refs as $ref){
                    if($ref>$count)throw new RuntimeException('UTexture native object ref exceeds ResMap');
                }
                $flags=[$u32(),$u32()];
                $bits=array_values(unpack('C2',$read(2)));
                $dims=array_map($signed,array_values(unpack('V3',$read(12))));
                $caps=$u32();
                $colors=[];
                foreach(['mip_zero','min_color','max_color'] as $name)
                    $colors[$name]=array_values(unpack('C4',$read(4)));
                $mipOffsets=array_values(unpack('V12',$read(48)));
                foreach($mipOffsets as $mip){
                    if($mip!==0xffffffff&&$mip>=$num)
                        throw new RuntimeException('Original UTexture mip offset outside UDatabase Num');
                }
                if($cursor!==self::NATIVE_BYTES)
                    throw new RuntimeException('UTexture source header byte accounting mismatch');
                $extra=(int)$s['class_member_bytes_remaining']-self::NATIVE_BYTES;
                if($strict&&$extra!==0)
                    throw new RuntimeException('Unexpected additional original UTexture members');
                $typed=[];
                foreach($refs as $name=>$ref)$typed[$name]=[
                    'raw_one_based_ref'=>$ref,'object_index'=>$ref===0?null:$ref-1];
                $out[]=[
                    'object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UTexture',
                    'status'=>$extra===0?'native_texture_header_decoded':'native_texture_header_extra_bytes',
                    'active_pixel_bytes'=>$num,'pixel_capacity_bytes'=>$max,
                    'object_references'=>$typed,
                    'diffuse_coefficient'=>$floats[0],'specular_coefficient'=>$floats[1],
                    'reflectivity_coefficient'=>$floats[2],'friction_coefficient'=>$floats[3],
                    'polygon_flags'=>$flags[0],'texture_flags'=>$flags[1],
                    'u_bits'=>$bits[0],'v_bits'=>$bits[1],
                    'u_size'=>$dims[0],'v_size'=>$dims[1],
                    'color_bytes'=>$dims[2],'camera_caps'=>$caps,
                    'colors_rgba_source_bytes'=>$colors,'mip_offsets'=>$mipOffsets,
                    'native_header_bytes'=>self::NATIVE_BYTES,
                    'unconsumed_header_bytes'=>$extra,
                    'texture_pixels_decoded'=>false,
                    'runtime_mipmaps_fixed'=>false,'runtime_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','texture_headers'=>$out,
            'runtime_mipmaps_fixed'=>false,'pixel_payload_decoded'=>false];
    }
}
