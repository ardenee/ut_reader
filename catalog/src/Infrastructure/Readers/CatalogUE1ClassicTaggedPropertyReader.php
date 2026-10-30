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
        int $version, int $offset, int &$position, int $archiveLimit,
        ?string $sourceFamily=null,?array $sourceDeclarations=null,
        ?array $linkerLimits=null,
        ?CatalogUE1PackageReader $valuePkg=null,
        ?array &$streamState=null
    ): array {
            $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
            // In ULinkerLoad, an archive object/name reference is an AR_INDEX,
            // checked by IndexToObject or NameMap.IsValidIndex immediately
            // after its last byte, before the remaining struct fields.
            $readLinkerIndex=static function()use($read):array{
                $raw=$read(1);$first=ord($raw);$v=$first&63;
                $more=($first&64)!==0;$shift=6;
                while($more){
                    if(strlen($raw)>=5){
                        throw new RuntimeException('UE1 v432 linker compact index overflow');
                    }
                    $byte=$read(1);$raw.=$byte;$b=ord($byte);
                    $v|=($b&127)<<$shift;$more=($b&128)!==0;$shift+=7;
                }
                return [(($first&128)!==0?-$v:$v),$raw];
            };
            $validateLinkerIndex=static function(int $index,string $kind)
                use($linkerLimits,$offset,&$position,$family):void{
                if($linkerLimits===null)return;
                $bad=$kind==='name'
                    ?($index<0||$index >= (int)$linkerLimits['names'])
                    :($index>0 && $index>(int)$linkerLimits['exports'])
                        ||($index<0 && -$index>(int)$linkerLimits['imports']);
                if($bad){
                    $origin=$kind==='name'?'ULinkerLoad<<FName':'IndexToObject';
                    throw new RuntimeException(
                        'UE1 '.($family===CatalogUE1ClassicSourceFamily::UNREAL_V227
                            ?'v227':'v432').' '.$origin.' invalid '.$kind.' index '.$index
                        .' at archive offset '.($offset+$position));
                }
            };
            $tags=[];$end=false;
            // Epic's FPropertyTag reader reads until NAME_None; it has no
            // fixed 20,000-tag limit. Bound traversal by the actual bytes
            // of this file's export/default archive, not a guessed count.
            while($position<$archiveLimit
                ||(($streamState['allow_overread']??false)
                    &&$position<($streamState['physical_limit']??$archiveLimit))){
                $start=$position;
                $property=$name();
                if(strcasecmp($property['text'],'None')===0){$end=true;break;}
                $info=$u8();$type=$info&15;$kind=self::TYPE_NAMES[$type]??'UnknownProperty';
                // Epic reads arbitrary FPropertyTag.Type values and only
                // later checks against the resolved actual UProperty ID.
                // Invalid type zero is a nonfatal skip+warning, not an
                // archive corruption exception, in the compiled v432 engine.
                if($type===0
                    &&$family!==CatalogUE1ClassicSourceFamily::UT99_V432){
                    throw new RuntimeException('UE1 property tag has invalid type zero');
                }
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
                $declaration=$sourceDeclarations!==null
                    ?($sourceDeclarations[strtolower($property['text'])]??null)
                    :null;
                $loadPolicy='archive_bytes_only_schema_not_linked';
                $loadSize=$tagSize;
                // Only the EXACT resolved Unreal 227 UAnyProperty
                // declaration can reinterpret NAME_VectorProperty (tag11).
                // A real FVector tag with the same type number is never Any.
                // Actual 227k UAnyProperty::SerializeItem consumes dynamic
                // subtype wire bytes (0=1, 1/2=5, 3=2), not Tag.Size.
                if($family===CatalogUE1ClassicSourceFamily::UNREAL_V227
                    &&$type===11
                    &&is_array($declaration)
                    &&strtolower((string)($declaration['kind']??''))==='anyproperty'
                    &&$arrayIndex<(int)($declaration['array_dim']??0)){
                    $loadPolicy='native_227k_resolved_AnyProperty_SerializeItem';
                }
                if($sourceDeclarations!==null
                    &&$family===CatalogUE1ClassicSourceFamily::UNREAL_V227
                    &&$type===13
                    &&is_array($declaration)
                    &&strtolower((string)($declaration['kind']??''))==='strproperty'
                    &&$arrayIndex>=0
                    &&$arrayIndex<(int)($declaration['array_dim']??0)
                    &&(((int)($declaration['property_flags']??0))&0x00003000)===0){
                    // Genuine original 2012 C227i UCC script class and
                    // distinct compiled 2026 C227k SavePackage and LOAD:
                    // positive FString AR_INDEX(count including ANSI NUL).
                    // The source-linked UStrProperty is verified; zero
                    // defaults are omitted, not inserted as disk tags.
                    $loadPolicy='native_source_property_SerializeItem';
                }
                if($sourceDeclarations!==null
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432){
                    $expectedType=strtolower((string)($declaration['kind']??''));
                    $actualType=strtolower($kind);
                    // Epic UT99 UStruct::SerializeTaggedProperties handles
                    // historical NAME_StringProperty -> UStrProperty
                    // BEFORE ordinary type, array-index and persistence
                    // checks. It must not be treated as a warning/skip.
                    $legacyFixedToDynamic=$declaration!==null
                        &&$type===7&&$expectedType==='strproperty';
                    $matching=$declaration!==null
                        &&($actualType===$expectedType
                            ||($actualType==='objectproperty'
                                &&$expectedType==='classproperty'))
                        &&$arrayIndex>=0
                        &&$arrayIndex<(int)($declaration['array_dim']??0)
                        &&($type!==10
                            ||strcasecmp(
                                (string)($struct['text']??''),
                                (string)($declaration['struct_name']??'')
                            )===0)
                        &&(((int)($declaration['property_flags']??0))&0x00003000)===0;
                    if($legacyFixedToDynamic){
                        $loadPolicy='native_source_fixed_StringProperty_to_StrProperty_upgrade';
                    }elseif(!$matching){
                        // UStruct::SerializeTaggedProperties: unknown name,
                        // type mismatch, invalid array index or incompatible
                        // struct -> warn then skip exactly Tag.Size bytes.
                        $loadPolicy='native_warning_skip_declared_tag_bytes';
                    }else{
                        $loadPolicy='native_source_property_SerializeItem';
                        // UFloatProperty::SerializeItem always performs
                        // Ar<<FLOAT (four bytes). Tag.Size is used only in
                        // the warning+skip path, not valid property loading.
                        if($type===1&&$expectedType==='byteproperty'){
                            $loadSize=1;
                        }elseif(($type===2&&$expectedType==='intproperty')
                            ||($type===4&&$expectedType==='floatproperty')){
                            $loadSize=4;
                        }
                    }
                }elseif($type===0
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432){
                    $loadPolicy='native_warning_skip_invalid_tag_type';
                }
                $legacyRef=null;$arrayDecoded=null;
                $anyDecoded=null;
                $legacyFixedStringRisk=null;
                // Genuine UE1 v35 package evidence (DProj*, Palette tag):
                // the original tag declares a native 4-byte UObject slot,
                // but the payload is a compact serialized package reference.
                // This compatibility gate is observed corpus behaviour,
                // NOT claimed to be documented by the later v1.200 source.
                // Original genuine 2012 v227i UCC-compiled class, loaded by original
                // 2026 v227k Core.dll: UArrayProperty::SerializeItem reads a signed
                // compact element count then each actual Inner::SerializeItem.
                // A second ORIGINAL compiled v227k process reloaded its own
                // SavePackage of int/name/UObject arrays, including self + None.
                // Only decode an exact resolved UClass member and linked Inner.
                // Other v227 array subtypes stay archive-only, never guessed.
                if($family===CatalogUE1ClassicSourceFamily::UNREAL_V227
                    &&$type===9&&is_array($declaration)
                    &&strtolower((string)($declaration['kind']??''))==='arrayproperty'
                    &&$arrayIndex>=0
                    &&$arrayIndex<(int)($declaration['array_dim']??0)
                    &&(((int)($declaration['property_flags']??0))&0x00003000)===0
                    &&($declaration['owner_package']??null)
                        instanceof CatalogUE1PackageReader
                    &&is_string($declaration['owner_path']??null)
                    &&(int)($declaration['inner_reference']??0)>0
                    &&$valuePkg!==null){
                    $ownerPkg=$declaration['owner_package'];
                    $ownerPath=(string)$declaration['owner_path'];
                    $innerIndex=(int)$declaration['inner_reference']-1;
                    $innerHeader=self::inspect(
                        $ownerPath,$ownerPkg,$innerIndex,$family);
                    $inner=CatalogUE1ClassicNativeObjectReader::inspect(
                        $ownerPath,$ownerPkg,$innerIndex,$innerHeader,$family);
                    $innerKind=($inner['status']??'')==='native_property_record_complete'
                        ?(string)($inner['property_kind']??''):'';
                    $width=match($innerKind){
                        'byteproperty'=>1,'intproperty','floatproperty'=>4,
                        'nameproperty','objectproperty','classproperty'=>-1,
                        // Original C227i UCC compiled two source-legal
                        // array<string> and array<struct> values, genuine
                        // C227k native saved + separate native process loaded
                        // each via UStrProperty and UStruct::SerializeBin.
                        'strproperty'=>-2,'structproperty'=>-3,
                        default=>0
                    };
                    $structLayout=null;
                    if($width===-3){
                        $structLayout=CatalogUE1ClassicStructArrayValueReader::resolve(
                            $ownerPath,$ownerPkg,
                            (int)($inner['subclass_fields']['struct_reference']??0),
                            $family);
                        if(($structLayout['status']??null)!=='struct_local_field_layout_resolved'){
                            $width=0;
                        }else{
                            // Decode only this source-proven field serializer
                            // set; no recursive or inferred native memory.
                            foreach($structLayout['fields'] as $field){
                                if(!$field['skip_persistent_archive']
                                    &&!in_array($field['kind'],[
                                        'byteproperty','boolproperty','intproperty',
                                        'floatproperty','nameproperty',
                                        'objectproperty','classproperty'
                                    ],true)){
                                    $width=0;break;
                                }
                            }
                        }
                    }
                    if($width!==0){
                        $loadPolicy='native_source_property_SerializeItem';
                        [$count,$raw]=$readLinkerIndex();
                        // A negative native FArray count is an error/UB contract
                        // for T048 and not a verified positive-value array.
                        if($count<0){
                            $arrayDecoded=[
                                'element_count'=>$count,
                                'inner_property_kind'=>$innerKind,
                                'native_loaded_value_parity'=>'NOT_VERIFIED',
                                'source_runtime_risk'=>'v227 negative FArray element count'
                            ];
                        }else{
                            $minBytes=$width>0?$width:1;
                            $available=$streamState['physical_limit']??$archiveLimit;
                            if($count>intdiv(max(0,$available-$position),$minBytes)){
                                throw new RuntimeException(
                                    'UE1 v227 array element span exceeds physical FArchive at '
                                    .($offset+$position));
                            }
                            $elements=[];$linked=[];
                            for($a=0;$a<$count;$a++){
                                if($width===-1){
                                    [$ref,$itemRaw]=$readLinkerIndex();
                                    $refKind=$innerKind==='nameproperty'?'name':'object';
                                    $validateLinkerIndex($ref,$refKind);
                                    $v=$ref;
                                    if($refKind==='name'){
                                        $row=$valuePkg->getNames()[$ref]??null;
                                        $linked[]=$row===null?null:
                                            (string)($row['name']??$row['text']??'');
                                    }elseif($ref===0){
                                        $linked[]=null;
                                    }elseif($ref>0){
                                        $row=$valuePkg->getExports()[$ref-1]??null;
                                        $linked[]=$row===null?null:
                                            (string)($row['objectNameText']??'');
                                    }else{
                                        $row=$valuePkg->getImports()[-$ref-1]??null;
                                        $linked[]=$row===null?null:
                                            (string)($row['objectNameText']??'');
                                    }
                                }elseif($width===-2){
                                    // Real v227k UStrProperty::SerializeItem
                                    // archives signed FString count + units,
                                    // ignoring enclosing advisory Tag.Size.
                                    [$chars,$prefix]=$readLinkerIndex();
                                    $unit=(ord($prefix[0])&128)!==0?2:1;
                                    $available=max(0,(int)($streamState['physical_limit']
                                        ??$archiveLimit)-$position);
                                    if(abs($chars)>intdiv($available,$unit)){
                                        throw new RuntimeException(
                                            'UE1 v227 FString array physical FArchive EOF at '
                                            .($offset+(int)($streamState['physical_limit']
                                                ??$archiveLimit)));
                                    }
                                    $itemRaw=$prefix.$read(abs($chars)*$unit);
                                    $cursor=0;
                                    $result=CatalogUE1ClassicFStringArchiveReader::decodeAt(
                                        $itemRaw,$cursor);
                                    if($result===null||$cursor!==strlen($itemRaw)){
                                        throw new RuntimeException(
                                            'UE1 v227 original nested FString archive unverified');
                                    }
                                    $v=$result['value']+
                                        ['archive_value_kind'=>$result['value_kind']];
                                }elseif($width===-3){
                                    // Exact original UStruct::SerializeBin local
                                    // TFieldIterator order. FName is an
                                    // AR_INDEX resolved in the VALUE package.
                                    $itemRaw='';
                                    foreach($structLayout['fields'] as $field){
                                        if($field['skip_persistent_archive'])continue;
                                        for($j=0;$j<$field['array_dim'];$j++){
                                            if(in_array($field['kind'],[
                                                'nameproperty','objectproperty',
                                                'classproperty'],true)){
                                                [$ref,$part]=$readLinkerIndex();
                                                $validateLinkerIndex($ref,
                                                    $field['kind']==='nameproperty'
                                                        ?'name':'object');
                                            }else{
                                                $size=in_array($field['kind'],[
                                                    'byteproperty','boolproperty'
                                                ],true)?1:4;
                                                $part=$read($size);
                                            }
                                            $itemRaw.=$part;
                                        }
                                    }
                                    $cursor=0;
                                    $v=CatalogUE1ClassicStructArrayValueReader::readAt(
                                        $itemRaw,$cursor,$structLayout,$a<32,$valuePkg);
                                    if(($v['status']??null)!=='struct_field_values_decoded'
                                        ||$cursor!==strlen($itemRaw)){
                                        throw new RuntimeException(
                                            'UE1 v227 original array UStruct::SerializeBin values unverified');
                                    }
                                }else{
                                    $itemRaw=$read($width);
                                    if($innerKind==='byteproperty'){
                                        $v=ord($itemRaw);
                                    }elseif($innerKind==='intproperty'){
                                        $i=(int)unpack('V',$itemRaw)[1];
                                        $v=$i>=0x80000000?$i-0x100000000:$i;
                                    }else{
                                        $v=unpack('g',$itemRaw)[1];
                                    }
                                }
                                $raw.=$itemRaw;
                                if($a<32)$elements[]=$v;
                            }
                            $arrayDecoded=[
                                'element_count'=>$count,
                                'inner_property_kind'=>$innerKind,
                                'serialized_element_bytes'=>strlen($raw),
                                'preview_first_32'=>$elements,
                                'all_values_previewed'=>$count<=32,
                                'linked_names_or_objects_first_32'=>array_slice($linked,0,32),
                                'native_oracle'=>'genuine original compiled v227k SavePackage and independent LOAD'
                            ];
                        }
                        if($width===-3&&$count===0&&$tagSize>strlen($raw)){
                            // Actual original C227k native read 0 array items
                            // (one AR_INDEX byte), reported mismatch with
                            // old Tag.Size 19, then sought to that tag end.
                            // Preserve skipped unconsumed bytes, not fabricated
                            // array members; this is NOT the v432 branch.
                            $skipped=$read($tagSize-strlen($raw));
                            $raw.=$skipped;
                            $arrayDecoded['native_227k_tag_size_recovery']=[
                                'source_reported_actual_value_bytes'=>1,
                                'original_declared_tag_bytes'=>$tagSize,
                                'recovered_archive_byte_count'=>strlen($skipped),
                                'loaded_struct_count'=>0
                            ];
                        }
                    }else{
                        $raw=$read($loadSize);
                    }
                }elseif($loadPolicy==='native_227k_resolved_AnyProperty_SerializeItem'){
                    // Exact original compiled 227k FAnyProperty methods
                    // independently executed for NULL/INT/FLOAT/BOOL/STRING on
                    // both LOAD and SAVE. Unsupported subtype5..7 throws
                    // an explicit decoder capability error, NOT an invented
                    // Epic FArchive error or a guessed 12-byte FVector.
                    $anyDecoded=CatalogUE1ClassicAnyPropertyValueReader::
                        readFromArchive($read,$position,$family,$valuePkg);
                    $raw=hex2bin($anyDecoded['raw_archive_bytes_hex']);
                }elseif($loadPolicy==='native_source_fixed_StringProperty_to_StrProperty_upgrade'){
                    // Original compiled UT99 v432 UnClass.cpp:
                    // TCHAR Temp[255]; MaxCount=Tag.Size ? Tag.Size+1 : 255;
                    // read ANSI bytes one by one, stopping at NUL. A short
                    // value does NOT skip the unconsumed Tag.Size padding.
                    // The original loader reads on ULinkerLoad's physical
                    // archive and can cross the export SerialSize.
                    if($streamState!==null)$streamState['allow_overread']=true;
                    $countLimit=$tagSize!==0?$tagSize:254;
                    $raw='';
                    for($i=0;$i<min(255,$countLimit);$i++){
                        $ch=$read(1);$raw.=$ch;
                        if($ch==="\0")break;
                    }
                    if($countLimit>=255&&substr($raw,-1)!=="\0"){
                        // Epic writes Temp[Count]=0 after the loop,
                        // even when Count reaches 255, past Temp[254].
                        // This malformed native stack write is undefined,
                        // not a deterministic loaded FString value.
                        $legacyFixedStringRisk=
                            'v432 native fixed StringProperty Temp[255] out-of-bounds write';
                    }
                }elseif($version===35 && ($type===5||$type===8) && $tagSize===4){
                    $legacyRef=$compact();
                    $raw='';
                }elseif($loadPolicy==='native_source_property_SerializeItem'
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                    &&$linkerLimits!==null
                    &&in_array($type,[5,6,8],true)){
                    // Valid v432 Object/Class/Name SerializeItem uses AR_INDEX;
                    // Tag.Size is NOT the number of bytes it consumes.
                    [$reference,$raw]=$readLinkerIndex();
                    $validateLinkerIndex(
                        $reference,$type===6?'name':'object');
                }elseif($loadPolicy==='native_source_property_SerializeItem'
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                    &&$linkerLimits!==null&&$type===10
                    &&strcasecmp((string)($struct['text']??''),'PointRegion')===0){
                    // Real FPointRegion::SerializeBin reads UObject* Zone
                    // FIRST, then INT iLeaf and BYTE ZoneNumber. If Zone
                    // fails IndexToObject, Epic stops at that byte, not at
                    // Tag.Size or the end of the six-byte struct.
                    [$reference,$raw]=$readLinkerIndex();
                    $validateLinkerIndex($reference,'object');
                    $raw.=$read(5);
                }elseif($loadPolicy==='native_source_property_SerializeItem'
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                    &&$linkerLimits!==null&&$type===9){
                    // Epic UArrayProperty::SerializeItem archives an AR_INDEX
                    // count followed by each source-declared Inner SerializeItem.
                    // Valid properties NEVER use Tag.Size as their read length.
                    $innerRef=(int)($declaration['inner_reference']??0);
                    $ownerPath=$declaration['owner_path']??null;
                    $ownerPkg=$declaration['owner_package']??null;
                    if($innerRef<=0||!is_string($ownerPath)
                        ||!$ownerPkg instanceof CatalogUE1PackageReader){
                        throw new RuntimeException(
                            'UE1 v432 dynamic array Inner declaration unresolved');
                    }
                    $innerEnvelope=self::inspect(
                        $ownerPath,$ownerPkg,$innerRef-1,$family);
                    $native=CatalogUE1ClassicNativeObjectReader::inspect(
                        $ownerPath,$ownerPkg,$innerRef-1,$innerEnvelope,$family);
                    if(($native['status']??null)!=='native_property_record_complete'){
                        throw new RuntimeException(
                            'UE1 v432 dynamic array Inner native serializer unresolved');
                    }
                    $innerKind=(string)($native['property_kind']??'');
                    $width=match($innerKind){
                        'byteproperty','boolproperty'=>1,
                        'intproperty','floatproperty'=>4,
                        'nameproperty','objectproperty','classproperty'=>-1,
                        'strproperty'=>-2,
                        'structproperty'=>-3,
                        default=>0
                    };
                    if($width===0){
                        throw new RuntimeException(
                            'UE1 v432 dynamic array Inner SerializeItem not yet verified: '
                            .$innerKind);
                    }
                    $structLayout=null;
                    if($width===-3){
                        if($valuePkg===null)throw new RuntimeException(
                            'UE1 v432 dynamic struct owning linker unavailable');
                        $structLayout=CatalogUE1ClassicStructArrayValueReader::resolve(
                            $ownerPath,$ownerPkg,
                            (int)($native['subclass_fields']['struct_reference']??0),
                            $family);
                        if(($structLayout['status']??'')!=='struct_local_field_layout_resolved'){
                            throw new RuntimeException(
                                'UE1 v432 dynamic Inner UStruct SerializeBin layout unresolved');
                        }
                        // Read only source-proven UStruct::SerializeBin member
                        // types; retain explicit unsupported disposition.
                        foreach($structLayout['fields'] as $field){
                            if(!$field['skip_persistent_archive']
                                &&!in_array($field['kind'],[
                                    'byteproperty','boolproperty','intproperty',
                                    'floatproperty','nameproperty',
                                    'objectproperty','classproperty',
                                    'strproperty'],true)){
                                throw new RuntimeException(
                                    'UE1 v432 dynamic Inner struct member unsupported: '
                                    .$field['kind']);
                            }
                        }
                    }
                    // Epic ULinkerLoad's FArchive reads from the physical
                    // file, NOT from the export's declared SerialSize.
                    // A malformed array count may read into the next export
                    // before the native linker reports its true failure.
                    if($streamState!==null)$streamState['allow_overread']=true;
                    [$count,$raw]=$readLinkerIndex();
                    // Original compiled v432 UArrayProperty::SerializeItem
                    // reads signed AR_INDEX(n) then calls FArray::Add(n,c)
                    // and loops only while i<n. It does NOT throw here for
                    // negative n. A genuine count 3 -> -1 one-byte mutation
                    // continues until ULinkerLoad's later serial-size check:
                    // native Got 8, Expected 29, FArchive::Tell 259 (not 255).
                    // Keep the signed value as an archive observation, never
                    // claim a negative-sized TArray successfully loaded.
                    $negativeArrayCount=$count<0;
                    // Epic UArrayProperty::SerializeItem does not bound
                    // the element count by the export's SerialSize.
                    // ULinkerLoad reads the physical file stream and reports
                    // a failure at the actual file cursor. On a compiled
                    // v432 array<int> count=31 mutation the native linker
                    // reaches absolute byte 308 (physical EOF), whereas an
                    // early export-size guard incorrectly rejected the
                    // count before consuming any elements. For fixed-width
                    // inners we can establish the same unavoidable physical
                    // EOF without attempting arbitrary-sized allocations.
                    // Variable-width inners retain the per-read physical
                    // bounds from the source-selected archive callback.
                    if($width>0&&$streamState!==null
                        &&$count>intdiv(
                            max(0,(int)$streamState['physical_limit']-$position),
                            $width)){
                        throw new RuntimeException(
                            'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                            .($offset+(int)$streamState['physical_limit']));
                    }
                    $elements=[];
                    for($item=0;$item<$count;$item++){
                        if($width===-1){
                            [$ref,$segment]=$readLinkerIndex();
                            $validateLinkerIndex(
                                $ref,$innerKind==='nameproperty'?'name':'object');
                            $raw.=$segment;
                            $value=$ref;
                        }elseif($width===-2){
                            // Inner UStrProperty::SerializeItem uses source
                            // FString signed count, not array Tag.Size.
                            [$n,$segment]=$readLinkerIndex();
                            $unit=(ord($segment[0])&128)!==0?2:1;
                            // Epic UStrProperty::SerializeItem calls the
                            // FString archive operator; its linker reads the
                            // *physical* package stream, even if Tag.Size or
                            // the current export's SerialSize is exhausted.
                            // A genuine compiled v432 overlong nested FString
                            // reaches ULinkerLoad::Serialize at physical EOF,
                            // not this previous preemptive export-size error.
                            $physicalRemaining=max(0,
                                (int)($streamState['physical_limit']??$archiveLimit)
                                -$position);
                            if(abs($n)>intdiv($physicalRemaining,$unit)){
                                throw new RuntimeException(
                                    'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                                    .($offset+(int)($streamState['physical_limit']??$archiveLimit))
                                    .' during FString<<');
                            }
                            $segment.=$read(abs($n)*$unit);
                            $p=0;
                            $decoded=CatalogUE1ClassicFStringArchiveReader::decodeAt(
                                $segment,$p);
                            if($decoded===null||$p!==strlen($segment)){
                                throw new RuntimeException(
                                    'UE1 v432 dynamic array FString archive malformed');
                            }
                            $raw.=$segment;
                            $value=$decoded['value']+
                                ['archive_value_kind'=>$decoded['value_kind']];
                        }elseif($width===-3){
                            // Source-resolved actual UStruct::SerializeBin field
                            // chain determines each inner member's on-disk
                            // width; reuse shared struct reader for values.
                            $segment='';
                            foreach($structLayout['fields'] as $field){
                                if($field['skip_persistent_archive'])continue;
                                for($n=0;$n<$field['array_dim'];$n++){
                                    $memberKind=$field['kind'];
                                    if(in_array($memberKind,[
                                        'nameproperty','objectproperty',
                                        'classproperty'],true)){
                                        [$ref,$part]=$readLinkerIndex();
                                        $validateLinkerIndex($ref,
                                            $memberKind==='nameproperty'?'name':'object');
                                    }elseif($memberKind==='strproperty'){
                                        [$chars,$part]=$readLinkerIndex();
                                        $unit=(ord($part[0])&128)!==0?2:1;
                                        // UArrayProperty -> inner UStruct
                                        // SerializeBin -> UStrProperty::SerializeItem
                                        // delegates FString<< to the original
                                        // ULinkerLoad. The compiled engine
                                        // does NOT stop at this export SerialSize;
                                        // the physical package EOF is the
                                        // source-defined archive boundary.
                                        $physicalLimit=(int)($streamState['physical_limit']
                                            ??$archiveLimit);
                                        if(abs($chars)>intdiv(
                                            max(0,$physicalLimit-$position),$unit)){
                                            throw new RuntimeException(
                                                'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                                                .($offset+$physicalLimit)
                                                .' during nested struct FString<<');
                                        }
                                        $part.=$read(abs($chars)*$unit);
                                    }else{
                                        $size=in_array($memberKind,[
                                            'byteproperty','boolproperty'],true)?1:4;
                                        $part=$read($size);
                                    }
                                    $segment.=$part;
                                }
                            }
                            $decodedPosition=0;
                            $decoded=CatalogUE1ClassicStructArrayValueReader::readAt(
                                $segment,$decodedPosition,$structLayout,
                                count($elements)<32,$valuePkg);
                            if(($decoded['status']??null)!=='struct_field_values_decoded'
                                ||$decodedPosition!==strlen($segment)){
                                throw new RuntimeException(
                                    'UE1 v432 dynamic Inner UStruct archived members unverified');
                            }
                            $raw.=$segment;
                            $value=$decoded;
                        }else{
                            $segment=$read($width);$raw.=$segment;
                            if($innerKind==='byteproperty'||$innerKind==='boolproperty'){
                                $value=ord($segment);
                                if($innerKind==='boolproperty')$value=$value!==0;
                            }elseif($innerKind==='intproperty'){
                                $v=unpack('V',$segment)[1];
                                $value=$v>=0x80000000?$v-0x100000000:$v;
                            }else{
                                $value=unpack('g',$segment)[1];
                                if(!is_finite($value))$value=[
                                    'nonfinite_ieee754_le_hex'=>bin2hex($segment)];
                            }
                        }
                        if(count($elements)<32)$elements[]=$value;
                    }
                    $arrayDecoded=['element_count'=>$count,
                        'inner_property_kind'=>$innerKind,
                        'serialized_element_bytes'=>strlen($raw),
                        'preview_first_32'=>$elements,
                        'all_values_previewed'=>$count>=0&&$count<=32];
                    if($negativeArrayCount){
                        // The original loader may have an invalid TArray
                        // after FArray::Add(n,c); the archive parser cannot
                        // claim a successfully materialized native value.
                        $arrayDecoded['native_loaded_value_parity']='NOT_VERIFIED';
                        $arrayDecoded['source_runtime_risk']=
                            'Original v432 FArray::Add receives a negative signed count';
                    }
                    $structHasDynamicString=false;
                    if($innerKind==='structproperty'
                        &&is_array($structLayout)){
                        // A struct embedded in UArrayProperty is placed into
                        // uninitialized FArray::Add storage, just like a
                        // directly nested FString. The stock compiled v432
                        // Core/Engine loader crashed on a genuine generated
                        // array<G5CaptionRecord> where the struct contains
                        // FString Label. Do not claim that the archive values
                        // were successfully materialized by stock native.
                        foreach($structLayout['fields'] as $nestedField){
                            if(!$nestedField['skip_persistent_archive']
                                &&$nestedField['kind']==='strproperty'){
                                $structHasDynamicString=true;break;
                            }
                        }
                    }
                    if(($innerKind==='strproperty'||$structHasDynamicString)
                        &&$count>0){
                        // In compiled v432 Core the native UArrayProperty
                        // loader calls FArray::Add(n,c), NOT AddZeroed,
                        // then calls FString::SerializeItem on those raw
                        // unconstructed inner FString memory slots. The
                        // isolated real compiled two-string package oracle
                        // faults during native LoadPackage; do not present
                        // correct archive text as a verified loaded value.
                        // This flags a source-defined runtime hazard rather
                        // than asserting every allocator will always fault.
                        $arrayDecoded['native_loaded_value_parity']='NOT_VERIFIED';
                        $arrayDecoded['source_runtime_risk']=
                            'Epic v432 UArrayProperty::SerializeItem uses FArray::Add for unconstructed FString inner memory';
                    }
                }elseif($loadPolicy==='native_source_property_SerializeItem'
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                    &&$linkerLimits!==null&&$type===13){
                    // UT99 UStrProperty::SerializeItem delegates to the
                    // source FString archive operator: signed compact
                    // character count followed by count ANSI bytes (or
                    // twice that for Unicode). Tag.Size is advisory for
                    // a valid property and MUST NOT control this read.
                    // A matched native UStrProperty delegates directly to
                    // FString::operator<< on ULinkerLoad. The original
                    // compiled v432 loader reads through the physical file,
                    // even when Tag.Size and this export's SerialSize end.
                    // Do not apply the export-bound preview guard here.
                    if($streamState!==null)$streamState['allow_overread']=true;
                    [$count,$raw]=$readLinkerIndex();
                    $units=($count<0||((ord($raw[0])&128)!==0))?2:1;
                    $chars=abs($count);
                    $physicalLimit=(int)($streamState['physical_limit']??$archiveLimit);
                    if($chars>intdiv(max(0,$physicalLimit-$position),$units)){
                        throw new RuntimeException(
                            'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                            .($offset+$physicalLimit).' during direct FString<<');
                    }
                    $raw.=$read($chars*$units);
                }elseif($loadPolicy==='native_source_property_SerializeItem'
                    &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                    &&$type===10&&is_array($declaration)
                    &&($declaration['kind']??null)==='structproperty'
                    &&($declaration['owner_package']??null)
                        instanceof CatalogUE1PackageReader
                    &&(int)($declaration['struct_reference']??0)!==0
                    &&$valuePkg!==null&&$streamState!==null
                    &&isset($streamState['file_path'])){
                    // Epic UStructProperty::SerializeItem calls
                    // Struct->SerializeBin, ignoring advisory Tag.Size.
                    // Probe the actual source-defined struct field chain
                    // read-only, then consume exactly those bytes through
                    // the existing archive callback. This preserves native
                    // physical FArchive EOF across SerialSize and Tag.Size.
                    $layout=CatalogUE1ClassicStructArrayValueReader::resolve(
                        (string)$declaration['owner_path'],
                        $declaration['owner_package'],
                        (int)$declaration['struct_reference'],$family);
                    $raw=null;
                    if(($layout['status']??null)
                        ==='struct_local_field_layout_resolved'){
                        $available=max(0,(int)$streamState['physical_limit']-$position);
                        $probeSize=min($available,max(32,min($tagSize,256)));
                        while($probeSize>0){
                            $probe=file_get_contents(
                                (string)$streamState['file_path'],false,null,
                                $offset+$position,$probeSize);
                            if(!is_string($probe)||strlen($probe)!==$probeSize){
                                throw new RuntimeException(
                                    'UE1 v432 physical struct archive read failed');
                            }
                            $probeOffset=0;
                            $attempt=CatalogUE1ClassicStructArrayValueReader::readAt(
                                $probe,$probeOffset,$layout,true,$valuePkg);
                            if(($attempt['status']??null)
                                ==='struct_field_values_decoded'){
                                $streamState['allow_overread']=true;
                                $raw=$read($probeOffset);break;
                            }
                            if($probeSize===$available){
                                // Actual compiled ULinkerLoad invokes
                                // FString<< within SerializeBin; a length
                                // spanning physical EOF fails at EOF, not
                                // at this export's declared SerialSize.
                                if(($attempt['status']??'')
                                    ==='nested_struct_fstring_unverified'){
                                    throw new RuntimeException(
                                        'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                                        .($offset+(int)$streamState['physical_limit'])
                                        .' during direct struct FString<<');
                                }
                                break;
                            }
                            $probeSize=min($available,max($probeSize+1,2*$probeSize));
                        }
                    }
                    if($raw===null){
                        // Source layout unresolved/unsupported: preserve
                        // the original raw tag rather than fabricate a
                        // native loaded struct value.
                        $raw=$read($loadSize);
                    }
                }else{
                    $raw=$type===3?'':$read($loadSize);
                }
                $value=null;$valueKind='opaque';
                if(str_starts_with($loadPolicy,'native_warning_skip')){
                    $valueKind='epic_skipped_declared_tag_bytes';
                }
                if(str_starts_with($loadPolicy,'native_warning_skip')){
                    // Keep the raw archived tag and its SHA, but never
                    // present skipped bytes as a native loaded value.
                }elseif($anyDecoded!==null){
                    $value=$anyDecoded['value'];
                    $valueKind=$anyDecoded['value_kind'];
                }elseif($arrayDecoded!==null){
                    $value=$arrayDecoded;
                    $valueKind='source_typed_dynamic_array';
                }elseif($legacyRef!==null){$value=$legacyRef;$valueKind='v35_compact_object_reference';}
                elseif($type===3){$value=$arrayPresent;$valueKind='boolean_tag_bit';}
                elseif($type===1&&strlen($raw)===1){$value=ord($raw);$valueKind='byte';}
                elseif($type===2&&strlen($raw)===4){$v=unpack('V',$raw)[1];$value=$v>=0x80000000?$v-0x100000000:$v;$valueKind='int32';}
                elseif($type===4&&strlen($raw)===4){$value=unpack('g',$raw)[1];$valueKind='float32';}
                elseif($type===7){
                    // Unreal v1.200 FArchive::String stops at NUL OR
                    // StringSize-1; in the latter case it writes a NUL
                    // only into memory, with no terminating archive byte.
                    // Validate the capacity against the resolved
                    // UStringProperty declaration in the schema reader.
                    $firstNul=strpos($raw,"\0");
                    if($firstNul===false||
                        $firstNul===strlen($raw)-1){
                        $nulArchived=$firstNul!==false;
                        $textBytes=$nulArchived?substr($raw,0,-1):$raw;
                        $ascii=preg_match('/^[\\x09\\x0A\\x0D\\x20-\\x7E]*$/D',
                            $textBytes)===1?$textBytes:null;
                        $value=['raw_ansi_bytes_hex'=>bin2hex($textBytes),
                            'ascii_text'=>$ascii,'byte_length'=>strlen($textBytes),
                            'nul_terminated'=>$nulArchived,
                            'nul_inserted_in_memory'=>!$nulArchived,
                            'source_capacity_requires_property_declaration'=>true];
                        $valueKind='legacy_ansi_cstring_bytes';
                    }
                }
                elseif($type===13
                    &&in_array($family,[
                        CatalogUE1ClassicSourceFamily::UT99_V432,
                        CatalogUE1ClassicSourceFamily::UNREAL_V227
                    ],true)
                    &&$tagSize>=1){
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
                    }elseif($structName==='plane'&&$tagSize===16
                        &&$family===CatalogUE1ClassicSourceFamily::UNREAL_V227){
                        // Both 2012 227i and 2026 227k original UnMath.h
                        // FPlane operator<< serialize FVector X/Y/Z then W.
                        // Original 2026 227k compiled Core.dll native
                        // UStructProperty::Struct = Core.Object.Plane,
                        // super=Core.Object.Vector: inherited X/Y/Z offsets
                        // 0/4/8 and local W offset12. Actual original
                        // FPlane C++ serializer writes four FLOAT32 fields.
                        // Preserve this distinct inherited layout; never
                        // flatten or guess an unknown UStruct's members.
                        $f=array_values(unpack('g4',$raw));
                        $value=['x'=>$f[0],'y'=>$f[1],
                            'z'=>$f[2],'w'=>$f[3]];
                        $valueKind='fplane_inherited_fvector_xyz_w_float32';
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
                    }elseif($structName==='pointregion'
                        &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                        &&strlen($raw)>=6&&strlen($raw)<=10){
                        // Actual v432 Engine/Inc/UnObj.h FPointRegion:
                        // UObject* Zone (AR_INDEX), INT iLeaf, BYTE ZoneNumber.
                        // Do not assume a one-byte package object index.
                        $p=0;$first=ord($raw[$p++]);$neg=($first&0x80)!==0;
                        $more=($first&0x40)!==0;$ref=$first&0x3f;$shift=6;
                        while($more&&$p<strlen($raw)-5&&$shift<=27){
                            $byte=ord($raw[$p++]);
                            $ref|=($byte&0x7f)<<$shift;
                            $more=($byte&0x80)!==0;$shift+=7;
                        }
                        if(!$more&&$p+5===strlen($raw)){
                            $leaf=(int)unpack('V',substr($raw,$p,4))[1];
                            if($leaf>=0x80000000)$leaf-=0x100000000;
                            $value=['zone_object_reference'=>$neg?-$ref:$ref,
                                'i_leaf'=>$leaf,'zone_number'=>ord($raw[$p+4])];
                            $valueKind='fpointregion_zone_leaf_number';
                        }
                    }elseif($structName==='adrop'&&$tagSize===8){
                        // Epic Fire/Classes/WaterTexture.uc: ADrop 8 bytes.
                        $value=array_combine(
                            ['type','depth','x','y','byte_a','byte_b','byte_c','byte_d'],$bytes
                        );
                        $valueKind='water_texture_adrop';
                    }elseif(
                        $family===CatalogUE1ClassicSourceFamily::UT99_V432
                        &&$loadPolicy==='native_source_property_SerializeItem'
                        &&is_array($declaration)
                        &&($declaration['kind']??null)==='structproperty'
                        &&(int)($declaration['struct_reference']??0)!==0
                        &&($declaration['owner_package']??null)
                            instanceof CatalogUE1PackageReader
                    ){
                        // Actual declared UStructProperty::SerializeItem
                        // invokes Struct->SerializeBin, *not* a generic
                        // opaque 19-byte struct. Reuse the exact same
                        // source-resolved field chain used by a nested
                        // dynamic UArrayProperty<UStruct>.
                        $layout=CatalogUE1ClassicStructArrayValueReader::resolve(
                            (string)$declaration['owner_path'],
                            $declaration['owner_package'],
                            (int)$declaration['struct_reference'],$family);
                        if(($layout['status']??null)
                            ==='struct_local_field_layout_resolved'){
                            $structOffset=0;
                            $structValue=
                                CatalogUE1ClassicStructArrayValueReader::readAt(
                                    $raw,$structOffset,$layout,true,$valuePkg);
                            if(($structValue['status']??null)
                                ==='struct_field_values_decoded'
                                &&$structOffset===strlen($raw)){
                                $value=$structValue;
                                $valueKind='source_typed_struct';
                            }
                        }
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
                if($legacyFixedStringRisk!==null){
                    $value=null;
                    $valueKind='native_undefined_fixed_string_upgrade';
                }
                $tags[]=['name'=>$property['text'],'name_index'=>$property['index'],
                    'type'=>$kind,'type_id'=>$type,'info'=>$info,'array_index'=>$arrayIndex,
                    'bool_value'=>$type===3?$arrayPresent:null,
                    'struct_name'=>$struct,'serialized_size'=>$tagSize,
                    'on_disk_value_bytes'=>$offset+$position-$dataOffset,
                    'tag_offset'=>$offset+$start,'value_offset'=>$dataOffset,
                    'native_load_policy'=>$loadPolicy,
                    'native_227k_source_resolved_Any_decoded'=>$anyDecoded,
                    'legacy_fixed_string_native_runtime_risk'=>$legacyFixedStringRisk,
                    'value_kind'=>$valueKind,
                    'value'=>$value,'value_sha256'=>$raw!==''?hash('sha256',$raw):null];
            }
            if(!$end
                &&$family===CatalogUE1ClassicSourceFamily::UT99_V432
                &&($streamState['allow_overread']??false)
                &&$position>=($streamState['physical_limit']??PHP_INT_MAX)){
                // A source-valid FString may finish exactly at physical EOF.
                // Original compiled v432 still attempts the NEXT FPropertyTag
                // FName compact index, failing FArchiveFileReader::Serialize
                // at that physical Tell, not a preview-only missing-None check.
                throw new RuntimeException(
                    'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
                    .($offset+$position).' during FPropertyTag TagName<<FName');
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
        string $path,CatalogUE1PackageReader $package,int $start,int $end,int $version,
        ?string $sourceFamily=null,?array $sourceDeclarations=null
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
            $tags=self::parseTags(
                $read,$u8,$name,$compact,$version,$start,$position,$size,
                $sourceFamily,$sourceDeclarations,
                $sourceDeclarations!==null
                    ?['names'=>count($package->getNames()),
                      'exports'=>count($package->getExports()),
                      'imports'=>count($package->getImports())]
                    :null,
                $sourceDeclarations!==null?$package:null);
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
        $family=CatalogUE1ClassicSourceFamily::selectFromHeader(
            $package->getHeader(),$sourceFamily);
        $epic=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1;
        // The source loader is authoritative for whether a serialized
        // export is ever instantiated. Epic v1.200 UnLinker.h::CreateExport
        // excludes class Camera when Ver()<57; UT99 inherited v400
        // UnLinker.h explicitly returns NULL for Camera at all versions,
        // corroborated by real v432 LoadPackage on original v61/v68 maps.
        // A separate on-disk archive preview is still useful; it must
        // never be mislabeled as a successfully native-loaded Camera.
        $classIndex=(int)$export['classIndex'];
        $className=$classIndex<0
            ?(string)($package->getImports()[-$classIndex-1]['objectNameText']??'')
            :($classIndex>0
                ?(string)($all[$classIndex-1]['objectNameText']??''):'Class');
        $cameraLoaderExcluded=strcasecmp($className,'Camera')===0
            &&($family===CatalogUE1ClassicSourceFamily::UT99_V432
                ||($epic&&$version<57));
        $names=$package->getNames();
        $len=(int)filesize($path);
        if ($offset<0 || $size>$len-$offset) { throw new RuntimeException('UE1 payload outside physical file'); }
        $fp=fopen($path,'rb');
        if ($fp===false) { throw new RuntimeException('Cannot open UE1 payload'); }
        $position=0;
        try {
            if (fseek($fp,$offset)!==0) {throw new RuntimeException('Cannot seek UE1 payload');}
            $streamState=['allow_overread'=>false,
                'physical_limit'=>$len-$offset,
                'file_path'=>$path];
            $read=static function(int $n)use($fp,$size,&$position,&$streamState):string{
                $limit=$streamState['allow_overread']
                    ?$streamState['physical_limit']:$size;
                if($n<0||$n>$limit-$position){throw new RuntimeException('UE1 property tag exceeds export SerialSize');}
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
            $name=static function()use($compact,$names,$family,$offset,&$position):array{
                $index=$compact();
                if($index<0||!isset($names[$index])){
                    if($family===CatalogUE1ClassicSourceFamily::UT99_V432){
                        throw new RuntimeException(
                            'UE1 v432 ULinkerLoad<<FName invalid name index '.$index
                            .' at archive offset '.($offset+$position));
                    }
                    if($family===CatalogUE1ClassicSourceFamily::UNREAL_V227){
                        // Original compiled 2026 C227k Core.dll malformed
                        // real runtime package: invalid array index causes
                        // later TagName 1497/12 at absolute Tell256; missing
                        // NAME_None causes invalid TagName63/12 at Tell280.
                        // Epic checks last AR_INDEX byte BEFORE loading tag.
                        throw new RuntimeException(
                            'UE1 v227 ULinkerLoad<<FName invalid name index '.$index
                            .' at archive offset '.($offset+$position));
                    }
                    throw new RuntimeException('UE1 property name reference is invalid: '.$index);
                }
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
                $position,$offset,$family,$path,$exportIndex
            );
            $schema=null;
            if($family===CatalogUE1ClassicSourceFamily::UT99_V432
                ||$family===CatalogUE1ClassicSourceFamily::UNREAL_V227){
                // The 227k on-disk GetID alias for UAnyProperty equals
                // NAME_VectorProperty. Only source-resolved UClass member
                // declarations may enable the native Any item codec.
                // Keep v227's OTHER tag modes archive-only until proved;
                // ordinary v432 validation remains strictly unchanged.
                $resolved=CatalogUE1ClassicClassDefaultSchemaReader::
                    resolveObjectPropertyDeclarations(
                        $path,$package,(int)$export['classIndex'],$family);
                if(($resolved['status']??'')==='resolved'){
                    $schema=$resolved['declarations'];
                }
            }
            $tags=self::parseTags(
                $read,$u8,$name,$compact,$version,$offset,$position,$size,
                $family,$schema,
                $schema!==null&&in_array($family,[
                    CatalogUE1ClassicSourceFamily::UT99_V432,
                    CatalogUE1ClassicSourceFamily::UNREAL_V227
                ],true)
                    ?['names'=>count($names),
                        'exports'=>count($package->getExports()),
                        'imports'=>count($package->getImports())]
                    :null,
                $schema!==null?$package:null,$streamState);
            // The original v432 Engine native AActor::Serialize calls
            // Super::Serialize and archives NO trailing actor data.
            // This includes every fully resolved Engine.Actor subclass
            // whose native class does not override Serialize. Epic's
            // ULinkerLoad::Preload therefore fails if NAME_None appears
            // before the declared end of such an export. Keep other
            // UObject/native-source families in their own dispatch path.
            if(($resolved['actor_super_only_archive']??false)
                &&$position!==$size){
                $classRef=(int)$export['classIndex'];
                $classRow=$classRef<0
                    ?($package->getImports()[-$classRef-1]??null)
                    :($classRef>0?($package->getExports()[$classRef-1]??null):null);
                $className=is_array($classRow)
                    ?(string)$classRow['objectNameText']:'Object';
                throw new RuntimeException(
                    $className.' '.pathinfo($path,PATHINFO_FILENAME)
                    .'.'.(string)$export['objectNameText']
                    .': Serial size mismatch: Got '.$position
                    .', Expected '.$size);
            }
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
                    $cameraLoaderExcluded||$family===CatalogUE1ClassicSourceFamily::UNREAL_V227
                        ?null:((int)$export['objectFlags']|0x40000000),
                'source_sets_rf_debug_serialize'=>
                    !$cameraLoaderExcluded&&$family!==CatalogUE1ClassicSourceFamily::UNREAL_V227,
                // Not a heuristic class-name rejection: these are the
                // exact native ULinkerLoad::CreateExport source branches.
                // For other classes this field does NOT assert they were
                // loaded: context flags, imports and caller still matter.
                'native_loader_class_excluded_by_epic_source'=>$cameraLoaderExcluded,
                'native_loader_UObject_serialize_invoked'=>$cameraLoaderExcluded?false:null,
                'native_loader_source_branch'=>$cameraLoaderExcluded
                    ?($epic?'v1200_Camera_skipped_before_version57'
                            :'UT99_v400_inherited_Camera_CreateExport_returns_NULL')
                    :'not_determined_from_class_only',
                'native_tail_bytes'=>$size-$position,
                'selected_source_family'=>$family,
                'schema_verified'=>false]);
        }finally{fclose($fp);}
    }
}
