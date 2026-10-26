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
    public static function inspect(string $path, CatalogUE1PackageReader $pkg, int $index, array $propertyStream, ?string $sourceFamily=null): array
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
        $sourceVersion=(int)($pkg->getHeader()['version']??0);
        $family=CatalogUE1ClassicSourceFamily::selectFromHeader(
            $pkg->getHeader(),$sourceFamily);
        // A game may override only the native method of an otherwise
        // Epic-UE1-serialized object. Resolve the licensee-source delta
        // AFTER the inherited UObject tagged archive is decoded.
        // The source C++ name has an A prefix, but the package's Class
        // import is XBeamEmitter / XParticleEmitter / XRopeDeco in Emitter.
        // A short class name alone is not proof that a different package
        // declares the same game-native class.
        $classSourcePackage=null;
        if($classIndex<0){
            $classImport=$imports[-$classIndex-1]??null;
            $outer=(int)($classImport['outerIndex']??0);
            if($outer<0){
                $packageImport=$imports[-$outer-1]??null;
                if(is_array($packageImport)
                    &&strcasecmp((string)($packageImport['classNameText']??''),'Package')===0
                    &&(int)($packageImport['outerIndex']??1)===0){
                    $classSourcePackage=(string)$packageImport['objectNameText'];
                }
            }
        }elseif($classIndex>0){
            // A locally defined game class can only be tied to its game
            // package when the package's own source identity is known.
            $classSourcePackage=(string)pathinfo($path,PATHINFO_FILENAME);
        }
        $gameOverride=CatalogUE1ClassicGameSourceOverlay::inspectNative(
            $pkg->getHeader(),$className,$nativeLength,$propertyStream,
            $family,$classSourcePackage);
        if($gameOverride!==null){
            return array_replace($record,$gameOverride);
        }
        // v432 skeletal/animation method order now comes from the
        // authentic installed compiled Engine.dll, not declarations.
        // Decode after the common bounded archive callbacks below.
        if($nativeKind==='actor'){
            $actorVersion=(int)($pkg->getHeader()['version']??0);
            if($actorVersion<35||$actorVersion>69){
                return array_replace($record,[
                    'status'=>'native_actor_source_version_unverified',
                    'extension_bytes'=>$nativeLength,
                ]);
            }
            // Epic Unreal v1.200 Engine/Src/UnActor.cpp:351 and UT99
            // v400 Engine/Src/UnActor.cpp:963: AActor::Serialize archives
            // Super::Serialize only. The v1.200 InitialState/Group
            // assignments are runtime copies of already-read legacy
            // UObject state/group names, NOT extra serialized bytes.
            return array_replace($record,[
                'status'=>$nativeLength===0
                    ?'native_actor_super_only_complete'
                    :'native_actor_unexpected_native_tail',
                'extension_bytes'=>$nativeLength,
                'serialized_native_tail_bytes'=>0,
                'runtime_actor_state_applied'=>false,
            ]);
        }
        if(!in_array($nativeKind,[
            'texture','palette','sound','music','font','package','primitive','model','level','mesh','lodmesh','skeletalmesh','animation','firetexture','wettexture','scriptedtexture','textbuffer','enum','const','bitarray','bitmatrix','vectors','verts','bspsurfs','bspnodes','polys','factory','exporter','levelfactorynew','classfactorynew','texturefactorynew',
            ...CatalogUE1ClassicPropertyNativeReader::KINDS,
            ...CatalogUE1ClassicStructNativeReader::KINDS,
        ],true)){
            // This is a byte-level statement, not an assertion that every
            // runtime Class::Serialize side effect has been implemented.
            // The tagged UObject envelope already consumed this export
            // in full, so there is no unknown native payload to decode.
            if($nativeLength===0){
                return array_replace($record,[
                    'status'=>'tag_envelope_complete_no_native_tail',
                    'extension_bytes'=>0,
                    'class_runtime_serializer_verified'=>false,
                ]);
            }
            return $record;
        }
        // Compiled v432 UAnimation reads from ULinkerLoad's physical archive,
        // not an export-local bounded slice; retain export end for acceptance.
        $exportLimit=$limit;
        if($family===CatalogUE1ClassicSourceFamily::UT99_V432
            &&$nativeKind==='animation'){$limit=(int)filesize($path);}
        // C98 LoadObject and UT99 Preload archive through the file-backed linker,
        // then compare Tell() with SerialSize. SerialSize is not a read barrier.
        $g3Native=in_array($nativeKind,[
            'enum','const',
            ...CatalogUE1ClassicPropertyNativeReader::KINDS,
            ...CatalogUE1ClassicStructNativeReader::KINDS,
        ],true);
        $archiveLimit=$g3Native?(int)filesize($path):$limit;
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open native UE1 payload');}
        try{
            if(fseek($fp,$start)!==0){throw new RuntimeException('Cannot seek native UE1 payload');}
            $pos=$start;
            $read=static function(int $n)use($fp,$limit,$archiveLimit,$g3Native,&$pos,$family,$nativeKind):string{
                if($n<0||$n>$archiveLimit-$pos){
                    if($g3Native){
                        throw new RuntimeException('UE1 G3 native physical archive EOF cursor='.$archiveLimit);
                    }
                    if($family===CatalogUE1ClassicSourceFamily::UT99_V432
                        &&$nativeKind==='animation'){
                        throw new RuntimeException('UT99 v432 UAnimation native physical EOF cursor='.$limit);
                    }
                    throw new RuntimeException('UE1 native data exceeds object SerialSize');
                }
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
            if($family===CatalogUE1ClassicSourceFamily::UT99_V432
                &&$nativeKind==='animation'){
                return array_replace($record,
                    CatalogUE1ClassicV432NativeDeltaReader::inspectAnimation(
                        $pkg,$read,$compact,$int,$pos,$exportLimit,$limit));
            }
            if($family===CatalogUE1ClassicSourceFamily::UT99_V432
                &&$nativeKind==='skeletalmesh'){
                return array_replace($record,
                    CatalogUE1ClassicV432NativeDeltaReader::inspectSkeletalMesh(
                        $pkg,$ver,$read,$compact,$int,$pos,$limit,$seek));
            }
            $completeG3=static function(array $detail)use($record,&$pos,$exportLimit):array{
                if($pos>$exportLimit){
                    // Both original linkers compare Tell() to SerialSize AFTER Serialize.
                    $detail['status']='native_export_serial_size_mismatch';
                    $detail['source_serial_size_check_failed']=true;
                    $detail['archive_cursor']=$pos;
                    $detail['expected_export_end']=$exportLimit;
                    $detail['serial_size_overrun_bytes']=$pos-$exportLimit;
                    $detail['extension_bytes']=0;
                }
                return array_replace($record,$detail);
            };
            if(in_array($nativeKind,CatalogUE1ClassicPropertyNativeReader::KINDS,true)
                ||in_array($nativeKind,CatalogUE1ClassicStructNativeReader::KINDS,true)){
                $detail=in_array($nativeKind,CatalogUE1ClassicPropertyNativeReader::KINDS,true)
                    ?CatalogUE1ClassicPropertyNativeReader::inspect(
                        $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit,$family)
                    :CatalogUE1ClassicStructNativeReader::inspect(
                        $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit,$family);
                return $completeG3($detail);
            }
            if($nativeKind==='primitive'){
                return array_replace($record,CatalogUE1ClassicPrimitiveNativeReader::inspect(
                    $ver,$read,$pos,$limit,$family));
            }
            if($nativeKind==='model'){
                return array_replace($record,CatalogUE1ClassicModelNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit,$seek,$family));
            }
            if($nativeKind==='polys'){
                return array_replace($record,CatalogUE1ClassicPolysNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit,
                    $propertyStream['legacy_database']??null,$family));
            }
            if($nativeKind==='bspnodes'){
                return array_replace($record,CatalogUE1ClassicBspNodesNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit,
                    $propertyStream['legacy_database']??null,$family));
            }
            if($nativeKind==='bspsurfs'){
                return array_replace($record,CatalogUE1ClassicBspSurfsNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit,
                    $propertyStream['legacy_database']??null,$family));
            }
            if($nativeKind==='vectors'||$nativeKind==='verts'){
                return array_replace($record,CatalogUE1ClassicDatabaseNativeReader::inspect(
                    $nativeKind,$ver,$read,$compact,$int,$pos,$limit,
                    $propertyStream['legacy_database']??null,$family));
            }
            if($nativeKind==='level'){
                return array_replace($record,CatalogUE1ClassicLevelNativeReader::inspect(
                    $pkg,$ver,$read,$compact,$int,$pos,$limit,
                    $propertyStream['legacy_database']??null,$family));
            }
            if($nativeKind==='mesh'||$nativeKind==='lodmesh'){
                return array_replace($record,CatalogUE1ClassicMeshNativeReader::inspect(
                    $nativeKind,$pkg,$ver,$read,$compact,$int,$pos,$limit,$seek,$family));
            }
            if($nativeKind==='bitarray'||$nativeKind==='bitmatrix'){
                return array_replace($record,CatalogUE1ClassicBitArrayNativeReader::inspect(
                    $nativeKind,$read,$compact,$pos,$limit));
            }
            if(in_array($nativeKind,['factory','exporter','levelfactorynew','classfactorynew','texturefactorynew'],true)){
                if($ver<60){
                    return array_replace($record,[
                        'status'=>'source_ut99_editor_serializer_version_unverified',
                        'extension_bytes'=>$nativeLength,
                    ]);
                }
                return array_replace($record,CatalogUE1ClassicEditorSerializerReader::inspect(
                    $nativeKind,$pkg,$ver,$read,$compact,$pos,$limit));
            }
            if($nativeKind==='package'){
                // Unreal v1.200 Core/Src/UnObj.cpp::UPackage::Serialize
                // calls UObject::Serialize then SetFlags(RF_Public).
                // UT99 v400 drops the extra SetFlags operation.
                // Neither engine archives extra UPackage-native bytes.
                $serializedFlags=(int)($ex['objectFlags']??0);
                $epicLegacy=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1;
                return array_replace($record,[
                    'status'=>$nativeLength===0?'native_package_record_complete':'native_package_unexpected_tail',
                    'serialized_object_flags'=>$serializedFlags,
                    'loaded_object_flags_after_package_serialize'=>$epicLegacy
                        ?($serializedFlags|0x00000004):$serializedFlags,
                    'source_sets_rf_public'=>$epicLegacy,
                    'selected_source_family'=>$family,
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
                if($numNames<0){
                    throw new RuntimeException('Negative Enum FName count cannot initialize original array');
                }
                // Epic reads each FName from the physical archive; the
                // export byte-count comparison belongs after Serialize.
                $names=$pkg->getNames();$enumNames=[];
                for($i=0;$i<$numNames;$i++){
                    $nameIndex=$compact();
                    if($nameIndex<0||!isset($names[$nameIndex])){
                        throw new RuntimeException('Enum FName index outside package name map');
                    }
                    $enumNames[]=['name_index'=>$nameIndex,
                        'name'=>(string)$names[$nameIndex]['name']];
                }
                return $completeG3([
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
                // UT99 v400 UnMisc.cpp FString<< has NO Ar.Ver() gate on
                // its signed UTF-16 branch, including overlapping v60-63.
                // C98 uses TArray<char>; C227 Core implementation is unknown.
                if($wide&&$ver<64&&$family!==CatalogUE1ClassicSourceFamily::UT99_V432){
                    throw new RuntimeException('UConst wide FString unverified for selected legacy source family');
                }
                $count=abs($signedCount);
                // The file-backed linker reads FString bytes through physical
                // EOF, then checks the final consumed export SerialSize.
                $raw=$read($count*($wide?2:1));
                // Original C98 TArray<char> and UT99 FString::operator<<
                // archive all elements without validating a trailing NUL or
                // UTF-16 scalar sequence. Do not invent an FArchive error.
                $ending=$wide?"\x00\x00":"\x00";
                $terminated=$count===0||str_ends_with($raw,$ending);
                $chars=$count===0?'':($terminated
                    ?substr($raw,0,-strlen($ending)):$raw);
                // On UT99 LOAD FString::operator<< empties single-unit arrays.
                $singleCleared=$family===CatalogUE1ClassicSourceFamily::UT99_V432&&$count===1;
                $value=$singleCleared||$chars===''?'':($wide
                    ?@iconv('UTF-16LE','UTF-8',$chars)
                    :\UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromBytes($chars));
                $textVerified=$singleCleared||($terminated&&$value!==false);
                if(!$textVerified){$value=null;}
                return $completeG3([
                    'status'=>!$textVerified
                        ?'native_const_archive_complete_text_unverified'
                        :($pos===$limit?'native_const_record_complete':'native_const_has_extension'),
                    'super_field_reference'=>$superField,'next_field_reference'=>$nextField,
                    'value'=>$value,'value_encoding'=>$wide?'UTF-16LE':'Epic-FromAnsi-bytewise',
                    'value_count'=>$count,'value_sha256'=>hash('sha256',$raw),
                    'string_terminated'=>$terminated,
                    'source_text_value_verified'=>$textVerified,
                    'ut99_single_unit_cleared_on_load'=>$singleCleared,
                    'unverified_value_bytes_preview_hex'=>!$textVerified?bin2hex(substr($raw,0,64)):null,
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
                // The compiled UT99 v432 linker checks object SerialSize AFTER
                // FString::Serialize has advanced its archive cursor. A
                // malformed count can therefore report "Got 912, Expected
                // 843" even though the declared object ends at byte 843.
                // Reproduce that attempted *logical* cursor and error without
                // reading the following export or allocating the bad string.
                // Other source families retain their existing safe rejection.
                $rejectFStringOverflow=static function(int $size,string $fallback)
                    use($family,$pos,$limit,$path,$ex):void{
                    if($family===CatalogUE1ClassicSourceFamily::UT99_V432
                        &&$size>0 &&$pos<=$limit
                        &&$size<=filesize($path)-$pos){
                        $got=$pos+$size-(int)$ex['serialOffset'];
                        $expected=(int)$ex['serialSize'];
                        throw new RuntimeException(
                            'TextBuffer: Serial size mismatch: Got '.$got.
                            ', Expected '.$expected
                        );
                    }
                    throw new RuntimeException($fallback);
                };
                if($wide){
                    $count=-$length;
                    if($count>intdiv($limit-$pos,2)){
                        $rejectFStringOverflow($count*2,
                            'TextBuffer UTF-16 FString exceeds export bounds');
                    }
                    $size=$count*2;
                }else{
                    if($length>$limit-$pos){
                        $rejectFStringOverflow($length,
                            'TextBuffer ANSI FString exceeds export bounds');
                    }
                    $count=$length;$size=$count;
                }
                $textOffset=$pos;$raw=$read($size);
                // Epic UT99 v400 Core/Src/UnMisc.cpp FString operator<<
                // reads every A.Num() code unit without asserting the final
                // slot is NUL; FString::Len() excludes that last slot.
                // Compiled v432 accepts an original Engine.u copy with its
                // terminal ANSI NUL changed to X. Preserve the raw bytes
                // while decoding just the logical length; retain bounds.
                $terminatorIsNul=$count===0?null:($wide
                    ?substr($raw,-2)==="\x00\x00"
                    :substr($raw,-1)==="\x00");
                $encoded=$count>0?substr($raw,0,-($wide?2:1)):'';
                $decoded=$encoded===''?'':($wide
                    ?@iconv('UTF-16LE','UTF-8',$encoded)
                    :\UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromBytes($encoded));
                if($decoded===false){
                    throw new RuntimeException('Invalid TextBuffer character sequence');
                }
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_textbuffer_record_complete':'native_textbuffer_has_extension',
                    'position'=>$textPos,'top'=>$top,'text'=>$decoded,
                    'text_encoding'=>$wide?'UTF-16LE':'Epic-FromAnsi-bytewise',
                    'text_terminator_is_nul'=>$terminatorIsNul,
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
                // The lazy SeekPos is an archive operation of the
                // DATA TYPE, not a generic package-version field.
                // Epic v1.200 Engine/Inc/UnAudio.h: USound and UMusic
                // both own ordinary TArray<BYTE> for every Ver().
                // UT99 v400 and Unreal v227 Engine/Inc/UnAudio.h:
                // FSoundData : TLazyArray<BYTE> and UMusic Data is a
                // TLazyArray<BYTE>; its Core/Inc/UnTemplate.h stores
                // the absolute SeekPos only for Ver()>61.
                $lazyAudio=$family!==CatalogUE1ClassicSourceFamily::EPIC_UE1;
                $seekEnd=$lazyAudio&&$ver>61?$int():null;
                $dataSize=$compact();
                if($dataSize<0||$dataSize>$limit-$pos){throw new RuntimeException('Audio byte array exceeds export bounds');}
                // Actual UT99 v432 FSoundData::Load invokes GetPeriod();
                // FWaveModInfo::ReadWaveInfo dereferences WavData(0).
                // Native original-DMatch zero-count DEV mutation triggers
                // an Engine.dll access violation. Fail safely rather than
                // claiming that the native Sound was loaded correctly.
                // Music uses no WAV duration reader; compiled v432 loaded
                // the corresponding zero-count UMusic successfully.
                if($dataSize===0&&$nativeKind==='sound'
                   &&$family===CatalogUE1ClassicSourceFamily::UT99_V432){
                    throw new RuntimeException('UT99 Sound empty Data: native FSoundData::GetPeriod dereferences WavData(0) (observed access violation)');
                }
                $dataStart=$pos;$audioHash=hash_init('sha256');
                $audioPreview='';
                $audioRemaining=$dataSize;
                while($audioRemaining>0){
                    $part=$read(min(65536,$audioRemaining));
                    hash_update($audioHash,$part);
                    if(strlen($audioPreview)<64){
                        $audioPreview.=substr($part,0,64-strlen($audioPreview));
                    }
                    $audioRemaining-=strlen($part);
                }
                // Epic Core/Inc/UnTemplate.h::TLazyArray::operator<<
                // does NOT compare its absolute SeekPos to the bytes
                // actually decoded by This.Load(). On loading it seeks
                // to SeekPos after decoding the declared TArray count.
                // A shorter declared array may therefore skip archived
                // unused bytes: confirmed by genuine compiled v432
                // UMusic::Serialize with a DEV-only count-zero mutation.
                $lazyForwardSkip=0;
                if($seekEnd!==null){
                    if($seekEnd<$pos||$seekEnd>$limit){
                        throw new RuntimeException('Audio lazy SeekPos mismatch');
                    }
                    $lazyForwardSkip=$seekEnd-$pos;
                    $pos=$seekEnd;
                }
                return array_replace($record,['status'=>$pos===$limit?('native_'.$nativeKind.'_record_complete'):('native_'.$nativeKind.'_has_extension'),
                    'file_type_name'=>(string)$names[$nameIndex]['name'],
                    'audio_data_offset'=>$dataStart,'audio_data_bytes'=>$dataSize,
                    'audio_data_sha256'=>hash_final($audioHash),
                    'audio_data_preview_hex'=>strtoupper(bin2hex($audioPreview)),
                    'selected_source_family'=>$family,
                    'source_audio_array_type'=>$lazyAudio?'TLazyArray<BYTE>':'TArray<BYTE>',
                    'source_lazy_seek_header_present'=>$seekEnd!==null,
                    'lazy_seek_pos'=>$seekEnd,
                    'lazy_forward_skipped_unloaded_bytes'=>$lazyForwardSkip,
                    'extension_bytes'=>$limit-$pos,
                    'audio_data_decoded'=>true,'audio_data_interpreted'=>false]);
            }
            if($nativeKind==='font'&&$family===CatalogUE1ClassicSourceFamily::UT99_V432){
                // UT99 v400 UFont : UObject; its noninherited native tail
                // contains TArray<FFontPage> Pages and CharactersPerPage.
                // Unreal v1.200 UFont : UTexture is decoded below after
                // its inherited texture mip array, then 256 glyph records.
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
                    // Engine/Inc/UnTex.h::FFontPage::operator<< serializes
                    // a real UTexture* linker reference, not an arbitrary
                    // compact integer. A bad import/export index is fatal
                    // to Epic's FArchive object-pointer resolution.
                    $textureIndex=$compact();
                    if(($textureIndex<0&&-$textureIndex>count($pkg->getImports()))
                        ||($textureIndex>0&&$textureIndex>count($pkg->getExports()))){
                        throw new RuntimeException('FFontPage Texture outside package object map');
                    }
                    $glyphCount=$compact();
                    if($glyphCount<0||$glyphCount>intdiv($limit-$pos,16)){
                        throw new RuntimeException('Invalid font glyph array bounds');
                    }
                    // Engine/Inc/UnTex.h::FFontCharacter::operator<< archives
                    // four signed INTs: StartU, StartV, USize, VSize. Decode
                    // these actual persisted coordinates, keeping a bounded
                    // preview and a hash of EVERY serialized glyph.
                    $glyphOffset=$pos;$remainingGlyphs=$glyphCount;
                    $glyphsPreview=[];$glyphHash=hash_init('sha256');
                    while($remainingGlyphs>0){
                        $batchCount=min(4096,$remainingGlyphs);
                        $batch=$read($batchCount*16);
                        hash_update($glyphHash,$batch);
                        $previewCount=min($batchCount,max(0,16-count($glyphsPreview)));
                        for($g=0;$g<$previewCount;$g++){
                            $quad=array_values(unpack('V4',substr($batch,$g*16,16)));
                            $signed=array_map(static fn(int $n):int=>
                                $n>=0x80000000?$n-0x100000000:$n,$quad);
                            $glyphsPreview[]=[
                                'start_u'=>$signed[0],'start_v'=>$signed[1],
                                'u_size'=>$signed[2],'v_size'=>$signed[3]];
                        }
                        $remainingGlyphs-=$batchCount;
                    }
                    $pages[]=['page'=>$i,'texture_reference'=>$textureIndex,
                        'glyph_count'=>$glyphCount,'glyph_data_offset'=>$glyphOffset,
                        'glyph_data_bytes'=>$glyphCount*16,
                        'glyph_data_sha256'=>hash_final($glyphHash),
                        'glyphs_preview'=>$glyphsPreview,
                        'glyphs_preview_truncated'=>$glyphCount>16];
                }
                // Epic UnFont.cpp: check(!(CharactersPerPage &
                // (CharactersPerPage-1))); compare the original 32-bit
                // archive word with native INT bitwise semantics.
                $rawCharactersPerPage=$int();
                if(($rawCharactersPerPage&
                    (($rawCharactersPerPage-1)&0xffffffff))!==0){
                    throw new RuntimeException('UFont CharactersPerPage is not a power of two');
                }
                $charactersPerPage=$rawCharactersPerPage>=0x80000000
                    ?$rawCharactersPerPage-0x100000000:$rawCharactersPerPage;
                $fontRemap=CatalogUE1ClassicV432NativeDeltaReader::decodeFontRemap(
                    $ver,$read,$compact,$int,$pos,$limit,
                    (int)$ex['serialOffset'],(int)filesize($path));
                return array_replace($record,$fontRemap,[
                    'status'=>$pos===$limit?'native_font_record_complete':'native_font_has_extension',
                    'page_count'=>$pageCount,'pages'=>$pages,
                    'characters_per_page'=>$charactersPerPage,
                    'characters_per_page_raw_dword'=>$rawCharactersPerPage,
                    'runtime_font_pages_loaded'=>false,
                    'extension_bytes'=>$limit-$pos]);
            }
            if($nativeKind==='palette'){
                $count=$compact();
                if($count<0||$count>intdiv($limit-$pos,4)){
                    throw new RuntimeException('Palette color array exceeds native payload bounds');
                }
                $colorOffset=$pos;
                $colors=$read($count*4);
                // Unreal v1.200 Engine/Src/UnTex.cpp::UPalette::Serialize
                // preserves archived alpha. UT99 v400 resets A to 255
                // for files <66, including old files loaded by UT99.
                // Package version <=61 alone cannot identify the loader.
                $ut99AlphaReset=$ver<66;
                $loadedAlphaReset=$family===CatalogUE1ClassicSourceFamily::UT99_V432&&$ut99AlphaReset;
                $preview=[];
                for($i=0;$i<min(16,$count);$i++){
                    $quad=array_values(unpack('C4',substr($colors,$i*4,4)));
                    $preview[]=[
                        'serialized_color_bytes'=>$quad,
                        'serialized_alpha'=>$quad[3],
                        'alpha_on_selected_source_load'=>
                            $loadedAlphaReset?255:$quad[3],
                        'alpha_if_ut99_v400_load'=>$ut99AlphaReset?255:$quad[3],
                    ];
                }
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_palette_record_complete':'native_palette_has_extension',
                    'color_count'=>$count,'color_data_offset'=>$colorOffset,
                    'color_data_bytes'=>strlen($colors),
                    'color_bytes_sha256'=>hash('sha256',$colors),
                    'color_preview'=>$preview,
                    'color_preview_truncated'=>$count>16,
                    'alpha_reset_to_255_at_load'=>$loadedAlphaReset,
                    'alpha_reset_if_ut99_v400_load'=>$ut99AlphaReset,
                    'alpha_loader_semantics_source_dependent'=>$ver<=61,
                    'selected_source_family'=>$family,
                    'extension_bytes'=>$limit-$pos,
                ]);
            }
            // Unreal v1.200 UTexture::Serialize archives Mips only.
            // UT99 v400 UnTex.cpp::UTexture::Serialize additionally archives
            // CompMips iff its source-defined tagged Bool bHasComp is true.
            // Texture.uc defaultproperties leaves bHasComp false.
            $readMipArray=static function(string $label)use(
                $compact,$int,$byte,$read,$ver,$limit,&$pos):array{
                $count=$compact();
                if($count<0||$count>1024){
                    throw new RuntimeException($label.' has invalid mip count');
                }
                $items=[];
                for($mip=0;$mip<$count;$mip++){
                    $lazySeekPos=$ver>61?$int():null;
                    $length=$compact();
                    if($length<0||$length>$limit-$pos){
                        throw new RuntimeException($label.' mip exceeds native bounds');
                    }
                    $offset=$pos;$remaining=$length;
                    $digest=hash_init('sha256');$preview='';
                    while($remaining>0){
                        $part=$read(min(65536,$remaining));
                        hash_update($digest,$part);
                        if(strlen($preview)<32){
                            $preview.=substr($part,0,32-strlen($preview));
                        }
                        $remaining-=strlen($part);
                    }
                    if($lazySeekPos!==null&&$lazySeekPos!==$pos){
                        throw new RuntimeException($label.' lazy SeekPos mismatch');
                    }
                    $width=$int();$height=$int();$bitsU=$byte();$bitsV=$byte();
                    $items[]=['mip_index'=>$mip,'pixel_data_offset'=>$offset,
                        'pixel_data_size'=>$length,'lazy_seek_pos'=>$lazySeekPos,
                        'pixel_data_sha256'=>hash_final($digest),
                        'pixel_data_preview_hex'=>strtoupper(bin2hex($preview)),
                        'width'=>$width,'height'=>$height,
                        'bits_u'=>$bitsU,'bits_v'=>$bitsV];
                }
                return $items;
            };
            $mips=$readMipArray('Mips');
            $count=count($mips);
            $compMips=null;$bHasComp=false;
            if($family===CatalogUE1ClassicSourceFamily::UT99_V432){
                foreach(($propertyStream['tags']??[]) as $tag){
                    if(strcasecmp((string)($tag['name']??''),'bHasComp')===0
                        &&($tag['type']??null)==='BoolProperty'){
                        $bHasComp=(bool)($tag['bool_value']??false);
                    }
                }
                if($bHasComp){
                    $compMips=$readMipArray('CompMips');
                }
            }
            if($nativeKind==='font'&&$family===CatalogUE1ClassicSourceFamily::EPIC_UE1){
                // Unreal v1.200 Engine/Src/UnFont.cpp:
                // UTexture::Serialize(Ar) then Ar << Characters.
                // Each FFontCharacter has four signed INT32 coordinates.
                $glyphCount=$compact();
                if($glyphCount<0||$glyphCount>intdiv($limit-$pos,16)){
                    throw new RuntimeException('UE1 legacy font glyph count exceeds native bounds');
                }
                $glyphOffset=$pos;
                $glyphBytes=$read($glyphCount*16);
                $preview=[];
                for($g=0;$g<min(16,$glyphCount);$g++){
                    $quad=unpack('V4',substr($glyphBytes,$g*16,16));
                    $values=[];
                    foreach($quad as $v){$values[]=$v>=0x80000000?$v-0x100000000:$v;}
                    $preview[]=['start_u'=>$values[0],'start_v'=>$values[1],
                        'u_size'=>$values[2],'v_size'=>$values[3]];
                }
                return array_replace($record,[
                    'status'=>$pos===$limit?'native_font_record_complete':'native_font_has_extension',
                    'archive_family'=>'Unreal-v1200-UTexture-Characters',
                    'mips'=>$mips,'mip_count'=>$count,
                    'character_count'=>$glyphCount,
                    'glyph_data_offset'=>$glyphOffset,
                    'glyph_data_bytes'=>strlen($glyphBytes),
                    'glyph_data_sha256'=>hash('sha256',$glyphBytes),
                    'glyphs_preview'=>$preview,'extension_bytes'=>$limit-$pos,
                ]);
            }
            $sparks=null;
            if($nativeKind==='firetexture'){
                // Both Unreal v1.200 and UT99 v400 Fire/Src/
                // FractalPrivate.h::FSpark::operator<< archive eight BYTES:
                // Type, Heat, X, Y, ByteA, ByteB, ByteC, ByteD.
                $sparkCount=$compact();
                if($sparkCount<0||$sparkCount>intdiv($limit-$pos,8)){
                    throw new RuntimeException('FireTexture spark array exceeds native export bounds');
                }
                $sparkOffset=$pos;
                $preview=[];$digest=hash_init('sha256');
                $remaining=$sparkCount;
                while($remaining>0){
                    $take=min($remaining,4096);
                    $bytes=$read($take*8);
                    hash_update($digest,$bytes);
                    for($j=0;$j<$take&&count($preview)<16;$j++){
                        $v=array_values(unpack('C8',substr($bytes,$j*8,8)));
                        $preview[]=['type'=>$v[0],'heat'=>$v[1],
                            'x'=>$v[2],'y'=>$v[3],'byte_a'=>$v[4],
                            'byte_b'=>$v[5],'byte_c'=>$v[6],'byte_d'=>$v[7]];
                    }
                    $remaining-=$take;
                }
                $sparks=['count'=>$sparkCount,
                    'serialized_offset'=>$sparkOffset,'bytes'=>$sparkCount*8,
                    'source_record_bytes'=>8,
                    'record_fields'=>['Type','Heat','X','Y','ByteA','ByteB','ByteC','ByteD'],
                    'records_preview'=>$preview,'preview_truncated'=>$sparkCount>16,
                    'serialized_sha256'=>hash_final($digest)];
            }
            return array_replace($record,['status'=>$pos===$limit?'native_texture_record_complete':'native_texture_has_extension',
                'mips'=>$mips,'mip_count'=>$count,
                'compressed_mips'=>$compMips,
                'compressed_mip_count'=>$compMips===null?null:count($compMips),
                'source_b_has_comp'=>$bHasComp,'sparks'=>$sparks,
                'extension_bytes'=>$limit-$pos,
                'pixel_payload_decoded'=>true,
                'pixel_format_interpreted'=>false,
                'selected_source_family'=>$family]);
        }finally{fclose($fp);}
    }
}
