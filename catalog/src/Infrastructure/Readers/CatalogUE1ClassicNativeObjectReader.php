<?php
/**
 * File-bounded UE1 class-native UPalette and UTexture structures.
 *
 * Epic v1.200 Engine/Src/UnTex.cpp, Inc/UnTex.h:
 *   UObject::Serialize then Palette Colors or Texture Mips.
 * UT99 v400 Engine/Src/UnTex.cpp, Inc/UnTex.h,
 * Core/Inc/UnTemplate.h TLazyArray: version<=61 sequential byte arrays,
 * version>61 absolute SeekPos ahead of lazy byte array.
 *
 * This returns structural mip/palette records; pixel decoding, GPU formats,
 * and native extensions are O07. No runtime object identity is inferred.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicNativeObjectReader
{
    /** @param array<string,mixed> $propertyStream @return array<string,mixed> */
    public static function inspect(string $path, CatalogUE1PackageReader $pkg, int $index, array $propertyStream): array
    {
        $exports=$pkg->getExports();$imports=$pkg->getImports();
        $ex=$exports[$index]??null;
        if(!is_array($ex)){throw new RuntimeException('Unknown export');}
        $classIndex=(int)$ex['classIndex'];
        $className=$classIndex<0
            ? (string)($imports[-$classIndex-1]['objectNameText']??'')
            : ($classIndex>0?(string)($exports[$classIndex-1]['objectNameText']??''):'Class');
        $record=['export_index'=>$index,'class_name'=>$className,'status'=>'class_specific_serializer_unimplemented'];
        if(($propertyStream['status']??'')!=='tag_envelope_decoded'){
            return array_replace($record,['status'=>'property_envelope_unavailable']);
        }
        $nativeLength=(int)($propertyStream['native_tail_bytes']??0);
        $start=(int)$ex['serialOffset']+(int)$ex['serialSize']-$nativeLength;
        $limit=$start+$nativeLength;
        if($start<0||$nativeLength<0||$limit>(int)filesize($path)){
            throw new RuntimeException('Native UE1 payload outside file');
        }
        $nativeKind=strtolower($className);
        if(!in_array($nativeKind,[
            'texture','palette','sound','font','package','firetexture','wettexture','scriptedtexture'
        ],true)){return $record;}
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open native UE1 payload');}
        try{
            if(fseek($fp,$start)!==0){throw new RuntimeException('Cannot seek native UE1 payload');}
            $pos=$start;
            $read=static function(int $n)use($fp,$limit,&$pos):string{
                if($n<0||$n>$limit-$pos){throw new RuntimeException('UE1 native data exceeds object SerialSize');}
                $buffer='';
                while(strlen($buffer)<$n){
                    $part=fread($fp,$n-strlen($buffer));
                    if($part===false||$part===''){throw new RuntimeException('Short native UE1 read');}
                    $buffer.=$part;
                }
                $pos+=$n;return $buffer;
            };
            $seek=static function(int $where)use($fp,$start,$limit,&$pos):void{
                if($where<$start||$where>$limit||fseek($fp,$where)!==0){
                    throw new RuntimeException('UE1 lazy mip SeekPos is outside serialized object');
                }
                $pos=$where;
            };
            $int=static fn():int=>(int)unpack('V',$read(4))[1];
            $byte=static fn():int=>ord($read(1));
            $compact=static function()use($byte):int{
                $first=$byte();$negative=($first&128)!==0;$value=$first&63;
                $more=($first&64)!==0;$shift=6;$length=1;
                while($more){
                    if(++$length>5){throw new RuntimeException('Native UE1 compact length overflow');}
                    $b=$byte();$value|=($b&127)<<$shift;$more=($b&128)!==0;$shift+=7;
                }
                return $negative?-$value:$value;
            };
            $ver=(int)($pkg->getHeader()['version']??0);
            if($nativeKind==='package'){
                return array_replace($record,[
                    'status'=>$nativeLength===0?'native_package_record_complete':'native_package_unexpected_tail',
                    'extension_bytes'=>$nativeLength,
                ]);
            }
            if($nativeKind==='sound'){
                // USound::Serialize: FName FileType, TLazyArray<BYTE> Data.
                $nameIndex=$compact();$names=$pkg->getNames();
                if($nameIndex<0||!isset($names[$nameIndex])){throw new RuntimeException('Sound FileType FName out of range');}
                $seekEnd=$ver>61?$int():null;
                $dataSize=$compact();
                if($dataSize<0||$dataSize>$limit-$pos){throw new RuntimeException('Sound lazy audio bytes exceed export bounds');}
                $dataStart=$pos;$seek($pos+$dataSize);
                if($seekEnd!==null&&$seekEnd!==$pos){throw new RuntimeException('Sound lazy SeekPos mismatch');}
                return array_replace($record,['status'=>$pos===$limit?'native_sound_record_complete':'native_sound_has_extension',
                    'file_type_name'=>(string)$names[$nameIndex]['name'],
                    'audio_data_offset'=>$dataStart,'audio_data_bytes'=>$dataSize,
                    'lazy_seek_pos'=>$seekEnd,'extension_bytes'=>$limit-$pos,
                    'audio_data_decoded'=>false]);
            }
            if($nativeKind==='font'){
                // UFont::Serialize: TArray<FFontPage> Pages, INT CharactersPerPage.
                // FFontPage: compact UObject texture index, compact count of
                // FFontCharacter; each glyph is four native 32-bit integers.
                $pageCount=$compact();
                if($pageCount<0||$pageCount>intdiv($limit-$pos,2)){
                    // Each FFontPage needs at least one byte for its object
                    // reference and one for its compact glyph-array count.
                    // UFont::Pages is a dynamic TArray, including localized
                    // fonts; NUM_FONT_PAGES is not a serialized limit.
                    throw new RuntimeException('Invalid native font page count');
                }
                $pages=[];
                for($i=0;$i<$pageCount;$i++){
                    $textureIndex=$compact();$glyphCount=$compact();
                    if($glyphCount<0||$glyphCount>intdiv($limit-$pos,16)){
                        throw new RuntimeException('Invalid font glyph array bounds');
                    }
                    $glyphOffset=$pos;$seek($pos+16*$glyphCount);
                    $pages[]=['page'=>$i,'texture_reference'=>$textureIndex,
                        'glyph_count'=>$glyphCount,'glyph_data_offset'=>$glyphOffset];
                }
                $charactersPerPage=$int();
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_font_record_complete':'native_font_has_extension',
                    'page_count'=>$pageCount,'pages'=>$pages,
                    'characters_per_page'=>$charactersPerPage,
                    'extension_bytes'=>$limit-$pos]);
            }
            if($nativeKind==='palette'){
                $count=$compact();
                if($count<0||$count>intdiv($limit-$pos,4)){
                    throw new RuntimeException('Palette color array exceeds native payload bounds');
                }
                $colorOffset=$pos;
                $colors=$read($count*4);
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_palette_record_complete':'native_palette_has_extension',
                    'color_count'=>$count,'color_data_offset'=>$colorOffset,
                    'color_data_bytes'=>strlen($colors),
                    'color_bytes_sha256'=>hash('sha256',$colors),
                    'alpha_reset_to_255_at_load'=>$ver<66,
                    'extension_bytes'=>$limit-$pos,
                ]);
            }
            $count=$compact();
            if($count<0||$count>1024){throw new RuntimeException('Invalid texture mip count');}
            $mips=[];
            for($mip=0;$mip<$count;$mip++){
                $lazySeekPos=$ver>61?$int():null;
                $length=$compact();
                if($length<0||$length>$limit-$pos){throw new RuntimeException('Texture mip byte count outside export bounds');}
                $dataOffset=$pos;
                $seek($pos+$length);
                if($lazySeekPos!==null){
                    if($lazySeekPos!==$pos){throw new RuntimeException('Lazy mip SeekPos and serialized data length disagree');}
                }
                $width=$int();$height=$int();$bitsU=$byte();$bitsV=$byte();
                $mips[]=['mip_index'=>$mip,'pixel_data_offset'=>$dataOffset,
                    'pixel_data_size'=>$length,'lazy_seek_pos'=>$lazySeekPos,
                    'width'=>$width,'height'=>$height,'bits_u'=>$bitsU,'bits_v'=>$bitsV];
            }
            $sparks=null;
            if($nativeKind==='firetexture'){
                // Fire/Src/UnFractal.cpp::UFireTexture::Serialize:
                // Super::Serialize then TArray<FSpark>, 8 bytes each.
                $sparkCount=$compact();
                if($sparkCount<0||$sparkCount>intdiv($limit-$pos,8)){
                    throw new RuntimeException('FireTexture spark array exceeds native export bounds');
                }
                $sparks=['count'=>$sparkCount,'serialized_offset'=>$pos,'bytes'=>$sparkCount*8];
                $seek($pos+$sparkCount*8);
            }
            return array_replace($record,['status'=>$pos===$limit?'native_texture_record_complete':'native_texture_has_extension',
                'mips'=>$mips,'mip_count'=>$count,'sparks'=>$sparks,
                'extension_bytes'=>$limit-$pos,
                'pixel_payload_decoded'=>false]);
        }finally{fclose($fp);}
    }
}
