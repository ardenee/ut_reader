<?php
/**
 * Epic UE1 FPropertyTag envelope, Unreal v1.200/UT99 v400 UnClass.cpp.
 *
 * This is a source-strict disk tag and scalar decoder, NOT a replacement
 * for UClass::SerializeTaggedProperties, native Serialize or class-defaults.
 * UClass exports dispatch to the source-defined UClass metadata reader;
 * ordinary UObject exports decode optional RF_HasStack (and mandatory
 * pre-v47) frame bytes before their tagged-property envelopes. Bytes
 * following NAME_None are class-native payload, not guessed tag values.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicTaggedPropertyReader
{
    private const TYPE_NAMES = [
        1=>'ByteProperty',2=>'IntProperty',3=>'BoolProperty',4=>'FloatProperty',
        5=>'ObjectProperty',6=>'NameProperty',7=>'StringProperty',
        8=>'ClassProperty',9=>'ArrayProperty',10=>'StructProperty',
        11=>'VectorProperty',12=>'RotatorProperty',13=>'StrProperty',
        14=>'MapProperty',15=>'FixedArrayProperty'
    ];

    /**
     * One source-defined FPropertyTag codec shared by UObject tagged
     * properties and UClass::SerializeTaggedProperties default records.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parseTags(
        callable $read, callable $u8, callable $name, callable $compact,
        int $version, int $offset, int &$position, int $archiveLimit
    ): array {
            $tags=[];$end=false;
            // Epic's FPropertyTag reader reads until NAME_None; it has no
            // fixed 20,000-tag limit. Bound traversal by the actual bytes
            // of this file's export/default archive, not a guessed count.
            while($position<$archiveLimit){
                $start=$position;
                $property=$name();
                if(strcasecmp($property['text'],'None')===0){$end=true;break;}
                $info=$u8();$type=$info&15;$kind=self::TYPE_NAMES[$type]??'UnknownProperty';
                if($type===0){throw new RuntimeException('UE1 property tag has invalid type zero');}
                $struct=$type===10?$name():null;
                $mode=$info&0x70;
                $tagSize=match($mode){
                    0x00=>1,0x10=>2,0x20=>4,0x30=>12,0x40=>16,
                    0x50=>$u8(),
                    0x60=>(int)unpack('v',$read(2))[1],
                    0x70=>(int)unpack('V',$read(4))[1],
                };
                $arrayIndex=0;$arrayPresent=($info&128)!==0;
                if($arrayPresent&&$type!==3){
                    $b=$u8();
                    if(($b&128)===0){$arrayIndex=$b;}
                    elseif(($b&192)===128){$arrayIndex=(($b&127)<<8)|$u8();}
                    else {
                        $c=$u8();$dd=$u8();$e=$u8();
                        $arrayIndex=(($b&63)<<24)|($c<<16)|($dd<<8)|$e;
                    }
                }
                $dataOffset=$offset+$position;
                $legacyRef=null;
                // Genuine UE1 v35 package evidence (DProj*, Palette tag):
                // the original tag declares a native 4-byte UObject slot,
                // but the payload is a compact serialized package reference.
                // This compatibility gate is observed corpus behaviour,
                // NOT claimed to be documented by the later v1.200 source.
                if($version===35 && ($type===5||$type===8) && $tagSize===4){
                    $legacyRef=$compact();
                    $raw='';
                } else {
                    $raw=$type===3?'':$read($tagSize);
                }
                $value=null;$valueKind='opaque';
                if($legacyRef!==null){$value=$legacyRef;$valueKind='v35_compact_object_reference';}
                elseif($type===3){$value=$arrayPresent;$valueKind='boolean_tag_bit';}
                elseif($type===1&&$tagSize===1){$value=ord($raw);$valueKind='byte';}
                elseif($type===2&&$tagSize===4){$v=unpack('V',$raw)[1];$value=$v>=0x80000000?$v-0x100000000:$v;$valueKind='int32';}
                elseif($type===4&&$tagSize===4){$value=unpack('g',$raw)[1];$valueKind='float32';}
                elseif($type===7&&$version>=40&&$tagSize>=1
                    &&substr($raw,-1)==="\0"
                    &&strpos($raw,"\0")===strlen($raw)-1){
                    // C98 Core/Inc/UnType.h UStringProperty::SerializeItem
                    // calls Ar.String(char*,StringSize); Core/Src/UnFile.cpp
                    // archives bytes to first NUL, bounded by StringSize.
                    // UT99 v400 additionally upgrades these old fixed-string
                    // tags to UStrProperty on load, ahead of generic tag
                    // mismatch checks (Core/Src/UnClass.cpp). Preserve ANSI
                    // byte identity; do not guess the FromAnsi codepage.
                    $textBytes=substr($raw,0,-1);
                    $ascii=preg_match('/^[\\x09\\x0A\\x0D\\x20-\\x7E]*$/D',
                        $textBytes)===1?$textBytes:null;
                    $value=['raw_ansi_bytes_hex'=>bin2hex($textBytes),
                        'ascii_text'=>$ascii,'byte_length'=>strlen($textBytes),
                        'nul_terminated'=>true];
                    $valueKind='legacy_ansi_cstring_bytes';
                }
                elseif($type===13&&$version>=62&&$tagSize>=1){
                    // Use the same bounded source codec as nested UT99
                    // UArrayProperty/UStrProperty value serialization.
                    $cursor=0;
                    $decoded=CatalogUE1ClassicFStringArchiveReader::decodeAt(
                        $raw,$cursor);
                    if($decoded!==null&&$cursor===strlen($raw)){
                        $value=$decoded['value'];
                        $valueKind=$decoded['value_kind'];
                    }
                }
                elseif($type===10&&is_array($struct)){
                    $bytes=array_values(unpack('C*',$raw)?:[]);
                    $structName=strtolower($struct['text']);
                    if($structName==='color'&&$tagSize===4){
                        // FColor has four stored color channels. Preserve
                        // raw disk channel order without inferred sRGB.
                        $value=['serialized_channel_bytes'=>$bytes];
                        $valueKind='fcolor_serialized_bytes';
                    }elseif($structName==='vector'&&$tagSize===12){
                        // C98 Engine/Inc/UnMath.h FVector::operator<<:
                        // exactly three little-endian FLOATs, no C++
                        // struct-memory padding in the archived value.
                        $f=array_values(unpack('g3',$raw));
                        $value=['x'=>$f[0],'y'=>$f[1],'z'=>$f[2]];
                        $valueKind='fvector_float32_xyz';
                    }elseif(($structName==='rotator'||
                        ($version<=61&&$structName==='rotation'))&&$tagSize===12){
                        // FRotator::operator<< archives three INT32 angles.
                        $v=array_values(unpack('V3',$raw));
                        $signed=static fn(int $x):int=>$x>=0x80000000
                            ?$x-0x100000000:$x;
                        $value=['pitch'=>$signed($v[0]),
                            'yaw'=>$signed($v[1]),'roll'=>$signed($v[2])];
                        $valueKind='frotator_int32_pitch_yaw_roll';
                    }elseif($structName==='scale'&&$tagSize===17){
                        // FScale::operator<< archives FVector Scale,
                        // FLOAT SheerRate and BYTE SheerAxis (12+4+1).
                        $f=array_values(unpack('g3',substr($raw,0,12)));
                        $value=['scale'=>['x'=>$f[0],'y'=>$f[1],'z'=>$f[2]],
                            'sheer_rate'=>(float)unpack('g',substr($raw,12,4))[1],
                            'sheer_axis'=>ord($raw[16])];
                        $valueKind='fscale_vector_rate_axis';
                    }elseif($structName==='adrop'&&$tagSize===8){
                        // Epic Fire/Classes/WaterTexture.uc: ADrop 8 bytes.
                        $value=array_combine(
                            ['type','depth','x','y','byte_a','byte_b','byte_c','byte_d'],$bytes
                        );
                        $valueKind='water_texture_adrop';
                    }
                }
                elseif(($type===5||$type===8||$type===6)&&$tagSize>=1&&$tagSize<=5){
                    $rawPos=0;$byte=ord($raw[$rawPos++]);$negative=($byte&128)!==0;
                    $more=($byte&64)!==0;$ref=$byte&63;$shift=6;
                    while($more&&$rawPos<strlen($raw)){
                        $byte=ord($raw[$rawPos++]);$ref|=($byte&127)<<$shift;$more=($byte&128)!==0;$shift+=7;
                    }
                    if(!$more&&$rawPos===strlen($raw)){
                        $value=$negative?-$ref:$ref;$valueKind=$type===6?'fname_index':'package_object_index';
                    }
                }
                $tags[]=['name'=>$property['text'],'name_index'=>$property['index'],
                    'type'=>$kind,'type_id'=>$type,'info'=>$info,'array_index'=>$arrayIndex,
                    'bool_value'=>$type===3?$arrayPresent:null,
                    'struct_name'=>$struct,'serialized_size'=>$tagSize,
                    'on_disk_value_bytes'=>$offset+$position-$dataOffset,
                    'tag_offset'=>$offset+$start,'value_offset'=>$dataOffset,'value_kind'=>$valueKind,
                    'value'=>$value,'value_sha256'=>$raw!==''?hash('sha256',$raw):null];
            }
            if(!$end){throw new RuntimeException('UE1 property tags have no NAME_None terminator within archive bounds');}
        return $tags;
    }

    /**
     * UClass defaults use the SAME FPropertyTag envelope as UObject, but
     * start at a separate offset after UClass's class-specific native data.
     * No runtime field-property schema linking is inferred here.
     *
     * @return array<string,mixed>
     */
    public static function inspectClassDefaults(
        string $path,CatalogUE1PackageReader $package,int $start,int $end,int $version
    ): array {
        $length=filesize($path);
        if($start<0||$end<=$start||$end>$length){
            throw new RuntimeException('UE1 class defaults stream exceeds export bounds');
        }
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Unable to open UE1 class default records');}
        try{
            if(fseek($fp,$start)!==0){throw new RuntimeException('Unable to seek UE1 defaults');}
            $size=$end-$start;$position=0;
            $read=static function(int $n)use($fp,$size,&$position):string{
                if($n<0||$n>$size-$position){
                    throw new RuntimeException('UE1 class default tag exceeds export SerialSize');
                }
                $raw='';
                while(strlen($raw)<$n){
                    $part=fread($fp,$n-strlen($raw));
                    if(!is_string($part)||$part===''){
                        throw new RuntimeException('Truncated UE1 class default property');
                    }
                    $raw.=$part;
                }
                $position+=$n;return $raw;
            };
            $u8=static fn():int=>ord($read(1));
            $compact=static function()use($u8):int{
                $first=$u8();$v=$first&63;$more=($first&64)!==0;
                $shift=6;$n=1;
                while($more){
                    if(++$n>5){throw new RuntimeException('UE1 class default compact overflow');}
                    $b=$u8();$v|=($b&127)<<$shift;
                    $more=($b&128)!==0;$shift+=7;
                }
                return ($first&128)!==0?-$v:$v;
            };
            $names=$package->getNames();
            $name=static function()use($compact,$names):array{
                $id=$compact();
                if($id<0||!isset($names[$id])){
                    throw new RuntimeException('Invalid UE1 class default property name');
                }
                return ['index'=>$id,'text'=>(string)$names[$id]['name']];
            };
            $tags=self::parseTags($read,$u8,$name,$compact,$version,$start,$position,$size);
            return ['tags'=>$tags,'tag_end_offset'=>$start+$position,
                'schema_verified'=>false,'source_archive_mode'=>'UClass-defaults'];
        }finally{fclose($fp);}
    }

    /** @return array<string,mixed> */
    public static function inspect(string $path, CatalogUE1PackageReader $package, int $exportIndex, ?string $sourceFamily=null): array
    {
        $all=$package->getExports();
        if (!isset($all[$exportIndex])) { throw new RuntimeException('Unknown UE1 export index'); }
        $export=$all[$exportIndex];
        $size=(int)$export['serialSize'];
        $offset=(int)$export['serialOffset'];
        $base=['export_index'=>$exportIndex,'object_name'=>(string)$export['objectNameText'],
            'serial_offset'=>$offset,'serial_size'=>$size,'tags'=>[],'native_tail_bytes'=>null,
            'schema_verified'=>false];
        if ($size <= 0) { return $base+['status'=>'no_serialized_object']; }
        if ((int)$export['classIndex']===0) {
            return array_replace($base,CatalogUE1ClassicClassMetadataReader::inspect(
                $path,$package,$exportIndex,true,$sourceFamily));
        }
        // Unreal v1.200 UObject::Serialize always serializes MainFrame
        // in file versions <47, independent of RF_HasStack. In later
        // versions a flagged StateFrame still precedes tagged properties.
        $version=(int)($package->getHeader()['version']??0);
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        $epic=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1;
        $names=$package->getNames();
        $len=(int)filesize($path);
        if ($offset<0 || $size>$len-$offset) { throw new RuntimeException('UE1 payload outside physical file'); }
        $fp=fopen($path,'rb');
        if ($fp===false) { throw new RuntimeException('Cannot open UE1 payload'); }
        $position=0;
        try {
            if (fseek($fp,$offset)!==0) {throw new RuntimeException('Cannot seek UE1 payload');}
            $read=static function(int $n)use($fp,$size,&$position):string{
                if($n<0||$n>$size-$position){throw new RuntimeException('UE1 property tag exceeds export SerialSize');}
                $bytes='';
                while(strlen($bytes)<$n){
                    $next=fread($fp,$n-strlen($bytes));
                    if($next===false||$next===''){throw new RuntimeException('Short UE1 property read');}
                    $bytes.=$next;
                }
                $position+=$n;
                return $bytes;
            };
            $u8=static fn():int=>ord($read(1));
            $compact=static function()use($u8):int{
                $first=$u8();$v=$first&63;$more=($first&64)!==0;$shift=6;$n=1;
                while($more){
                    if(++$n>5){throw new RuntimeException('UE1 property compact index overflow');}
                    $b=$u8();$v|=($b&127)<<$shift;$more=($b&128)!==0;$shift+=7;
                }
                return ($first&128)!==0 ? -$v : $v;
            };
            $name=static function()use($compact,$names):array{
                $index=$compact();
                if($index<0||!isset($names[$index])){throw new RuntimeException('UE1 property name reference is invalid: '.$index);}
                $entry=$names[$index];
                return ['index'=>$index,'text'=>(string)($entry['name']??$entry['text']??'')];
            };
            $legacyDatabase=null;
            if ($epic&&$version<40) {
                // Unreal v1.200 UObject::Serialize oldver: Ar << GObj.TempNum
                // << GObj.TempMax before the MainFrame bytes.
                $database=unpack('Vcount/Vcapacity',$read(8));
                $legacyDatabase=['serialized_start'=>$offset,
                    'count'=>(int)$database['count'],
                    'capacity'=>(int)$database['capacity']];
            }
            // UObject::Serialize uses the same version-gated frame archive
            // for class and ordinary object exports. Keep one decoder.
            $stateFrame=CatalogUE1ClassicStateFrameReader::read(
                $package,$version,(int)$export['objectFlags'],
                $read,$compact,
                static function()use($read):int{
                    $v=(int)unpack('V',$read(4))[1];
                    return $v>=0x80000000?$v-0x100000000:$v;
                },
                $position,$offset,$family
            );
            $tags=self::parseTags($read,$u8,$name,$compact,$version,$offset,$position,$size);
            $tagEnd=$offset+$position;
            $legacyState=null;$legacyGroup=null;
            if($epic&&$version<57){$legacyState=$name();}
            if($epic&&$version<58){$legacyGroup=$name();}
            // Unreal v1.200 maps old PlaySound to PlaySoundEffect after
            // FName deserialization. Preserve both the archived name and
            // Epic's post-load effective name without rewriting raw bytes.
            $loadedState=$legacyState;
            if($epic&&$legacyState!==null
                &&strcasecmp($legacyState['text'],'PlaySound')===0){
                $loadedState=['index'=>null,'text'=>'PlaySoundEffect',
                    'source_renamed_from'=>'PlaySound'];
            }
            return array_replace($base,['status'=>'tag_envelope_decoded','tags'=>$tags,
                'serialized_state_frame'=>$stateFrame,
                'legacy_database'=>$legacyDatabase,
                'tag_end_offset'=>$tagEnd,
                'legacy_state_name'=>$legacyState,
                'legacy_state_name_after_load'=>$loadedState,
                'legacy_group_name'=>$legacyGroup,
                'loaded_object_flags_after_uobject_serialize'=>
                    ((int)$export['objectFlags']|0x40000000),
                'source_sets_rf_debug_serialize'=>true,
                'native_tail_bytes'=>$size-$position,
                'selected_source_family'=>$family,
                'schema_verified'=>false]);
        }finally{fclose($fp);}
    }
}
