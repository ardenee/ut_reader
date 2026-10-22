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
        if($classIndex===0){
            return array_replace($record,['status'=>'class_metadata_decoded_in_class_reader',
                'metadata_status'=>$propertyStream['status']??'unknown']);
        }
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
            'texture','palette','sound','music','font','package','primitive','model','level','mesh','lodmesh','firetexture','wettexture','scriptedtexture','textbuffer','enum','const',
            ...CatalogUE1ClassicPropertyNativeReader::KINDS,
            ...CatalogUE1ClassicStructNativeReader::KINDS,
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
            if(in_array($nativeKind,CatalogUE1ClassicPropertyNativeReader::KINDS,true)){
                return array_replace($record,CatalogUE1ClassicPropertyNativeReader::inspect(
                    $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit));
            }
            if(in_array($nativeKind,CatalogUE1ClassicStructNativeReader::KINDS,true)){
                return array_replace($record,CatalogUE1ClassicStructNativeReader::inspect(
                    $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit));
            }
            if($nativeKind==='primitive'){
                return array_replace($record,CatalogUE1ClassicPrimitiveNativeReader::inspect(
                    $ver,$read,$pos,$limit));
            }
            if($nativeKind==='model'){
                return array_replace($record,CatalogUE1ClassicModelNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit));
            }
            if($nativeKind==='level'){
                return array_replace($record,CatalogUE1ClassicLevelNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit));
            }
            if($nativeKind==='mesh'||$nativeKind==='lodmesh'){
                return array_replace($record,CatalogUE1ClassicMeshNativeReader::inspect(
                    $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit));
            }
            if($nativeKind==='package'){
                return array_replace($record,[
                    'status'=>$nativeLength===0?'native_package_record_complete':'native_package_unexpected_tail',
                    'extension_bytes'=>$nativeLength,
                ]);
            }
            if($nativeKind==='enum'){
                // Epic v1.200 Core/Src/UnClass.cpp UField::Serialize
                // writes SuperField and Next after UObject::Serialize.
                // Core/Src/UnObj.cpp UEnum::Serialize then writes Names:
                // TArray<FName> compact count followed by compact FName refs.
                // Same source order in UT99 v400.
                $objectRef=static function()use($compact,$pkg):int{
                    $ref=$compact();
                    if(($ref<0&&-$ref>count($pkg->getImports()))
                        ||($ref>0&&$ref>count($pkg->getExports()))){
                        throw new RuntimeException('Enum UField reference outside package object map');
                    }
                    return $ref;
                };
                $superField=$objectRef();$nextField=$objectRef();
                $numNames=$compact();
                if($numNames<0||$numNames>$limit-$pos){
                    throw new RuntimeException('Enum FName array exceeds native export bounds');
                }
                $names=$pkg->getNames();$enumNames=[];
                for($i=0;$i<$numNames;$i++){
                    $nameIndex=$compact();
                    if($nameIndex<0||!isset($names[$nameIndex])){
                        throw new RuntimeException('Enum FName index outside package name map');
                    }
                    $enumNames[]=['name_index'=>$nameIndex,
                        'name'=>(string)$names[$nameIndex]['name']];
                }
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_enum_record_complete':'native_enum_has_extension',
                    'super_field_reference'=>$superField,'next_field_reference'=>$nextField,
                    'name_count'=>$numNames,'names'=>$enumNames,
                    'extension_bytes'=>$limit-$pos,
                ]);
            }
            if($nativeKind==='const'){
                // Epic Core/Src/UnClass.cpp UConst::Serialize:
                // UField::Serialize (SuperField, Next), then FString Value.
                // v1.200 FString=TArray<char>; UT99 v400 signed compact
                // FString permits ANSI and UTF-16LE, as in UTextBuffer.
                $ref=static function()use($compact,$pkg):int{
                    $id=$compact();
                    if($id<0&&-$id>count($pkg->getImports())
                        ||$id>count($pkg->getExports())){
                        throw new RuntimeException('UConst UField reference out of package bounds');
                    }
                    return $id;
                };
                $superField=$ref();$nextField=$ref();
                $signedCount=$compact();$wide=$signedCount<0;
                if($wide&&$ver<64){
                    throw new RuntimeException('UConst wide FString not valid in ANSI-only version');
                }
                $count=abs($signedCount);
                $remaining=$limit-$pos;
                if($count>($wide?intdiv($remaining,2):$remaining)){
                    throw new RuntimeException('UConst value exceeds export SerialSize');
                }
                $raw=$read($count*($wide?2:1));
                if($count>0){
                    $ending=$wide?"\x00\x00":"\x00";
                    if(!str_ends_with($raw,$ending)){
                        throw new RuntimeException('UConst Value FString missing NUL terminator');
                    }
                    $chars=substr($raw,0,-strlen($ending));
                }else{$chars='';}
                $value=$chars===''?'':@iconv($wide?'UTF-16LE':'Windows-1252','UTF-8',$chars);
                if($value===false){throw new RuntimeException('UConst invalid character encoding');}
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_const_record_complete':'native_const_has_extension',
                    'super_field_reference'=>$superField,'next_field_reference'=>$nextField,
                    'value'=>$value,'value_encoding'=>$wide?'UTF-16LE':'Windows-1252',
                    'value_count'=>$count,'value_sha256'=>hash('sha256',$raw),
                    'extension_bytes'=>$limit-$pos,
                ]);
            }
            if($nativeKind==='textbuffer'){
                // Epic Unreal v1.200 Core/Src/UnObj.cpp UTextBuffer::Serialize:
                // UObject::Serialize then Ar << Pos << Top << Text.
                // Unreal v1.200 FString serializes TArray<char> (compact count,
                // ANSI bytes); UT99 v400 Core/Src/UnMisc.cpp supports a signed
                // compact count: positive ANSI, negative UTF-16LE TCHAR.
                $signed32=static function()use($int):int{
                    $n=$int();return $n>=0x80000000?$n-0x100000000:$n;
                };
                $textPos=$signed32();$top=$signed32();
                $length=$compact();$wide=$length<0;
                if($wide&&$ver<64){
                    throw new RuntimeException('Wide FString not defined by v1.200 ANSI archive');
                }
                if($wide){
                    $count=-$length;
                    if($count>intdiv($limit-$pos,2)){
                        throw new RuntimeException('TextBuffer UTF-16 FString exceeds export bounds');
                    }
                    $size=$count*2;
                }else{
                    if($length>$limit-$pos){
                        throw new RuntimeException('TextBuffer ANSI FString exceeds export bounds');
                    }
                    $count=$length;$size=$count;
                }
                $textOffset=$pos;$raw=$read($size);
                if($count>0){
                    if($wide){
                        if(substr($raw,-2)!=="\x00\x00"){
                            throw new RuntimeException('TextBuffer wide FString lacks final NUL');
                        }
                        $encoded=substr($raw,0,-2);
                    }else{
                        if(substr($raw,-1)!=="\x00"){
                            throw new RuntimeException('TextBuffer ANSI FString lacks final NUL');
                        }
                        $encoded=substr($raw,0,-1);
                    }
                }else{$encoded='';}
                $decoded=$encoded===''?'':@iconv($wide?'UTF-16LE':'Windows-1252','UTF-8',$encoded);
                if($decoded===false){
                    throw new RuntimeException('Invalid TextBuffer character sequence');
                }
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_textbuffer_record_complete':'native_textbuffer_has_extension',
                    'position'=>$textPos,'top'=>$top,'text'=>$decoded,
                    'text_encoding'=>$wide?'UTF-16LE':'Windows-1252',
                    'text_character_count'=>$count>0?$count-1:0,
                    'text_raw_bytes'=>$size,'text_raw_offset'=>$textOffset,
                    'text_raw_sha256'=>hash('sha256',$raw),
                    'extension_bytes'=>$limit-$pos,
                ]);
            }
            if($nativeKind==='sound'||$nativeKind==='music'){
                // Epic Engine/Src/UnAudio.cpp USound/UMusic::Serialize:
                // Ar << FileType << Data. Original v1.200 UMusic uses
                // TArray<BYTE>; UT99 v400 UMusic uses TLazyArray<BYTE>,
                // whose version>61 archive writes an absolute SeekPos.
                // Both preserve bytes for O07 interpretation.
                $nameIndex=$compact();$names=$pkg->getNames();
                if($nameIndex<0||!isset($names[$nameIndex])){throw new RuntimeException('Audio FileType FName out of range');}
                $seekEnd=$ver>61?$int():null;
                $dataSize=$compact();
                if($dataSize<0||$dataSize>$limit-$pos){throw new RuntimeException('Audio byte array exceeds export bounds');}
                $dataStart=$pos;$seek($pos+$dataSize);
                if($seekEnd!==null&&$seekEnd!==$pos){throw new RuntimeException('Audio lazy SeekPos mismatch');}
                return array_replace($record,['status'=>$pos===$limit?('native_'.$nativeKind.'_record_complete'):('native_'.$nativeKind.'_has_extension'),
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
