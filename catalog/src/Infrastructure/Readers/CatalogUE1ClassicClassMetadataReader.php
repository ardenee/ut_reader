<?php
/**
 * Classic UE1 classIndex=0 UClass archive, source-gated structural reader.
 *
 * Source: Unreal v1.200 Core/Src/UnObj.cpp:433 (UObject::Serialize)
 * and Core/Src/UnClass.cpp:236,476,921,1440,1067;
 * UT99 v400 Core/Src/UnObj.cpp:478 and UnClass.cpp:239,593,712,914,1048.
 *
 * Maps Epic's complete on-disk UClass::Serialize chain, including script
 * expressions and tagged class defaults. Decoding a byte stream is NOT
 * equivalent to executing native InitClassDefaultObject, linker Preload,
 * LoadConfig or runtime property assignments.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicClassMetadataReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $path, CatalogUE1PackageReader $pkg, int $index,
        bool $verifyDefaultDeclarations=true,?string $sourceFamily=null
    ): array
    {
        $exports = $pkg->getExports();
        $ex = $exports[$index] ?? null;
        if (!is_array($ex) || (int)$ex['classIndex'] !== 0) {
            throw new RuntimeException('Not a UE1 UClass export');
        }
        $version = (int)($pkg->getHeader()['version'] ?? 0);
        $family=CatalogUE1ClassicSourceFamily::selectFromHeader(
            $pkg->getHeader(),$sourceFamily);
        $start = (int)$ex['serialOffset'];
        $size = (int)$ex['serialSize'];
        if ($version < 35 || $version > 69) {
            return ['export_index'=>$index, 'status'=>'class_metadata_source_version_unverified'];
        }
        $end = $start + $size;
        if ($size <= 0 || $start < 0 || $end > filesize($path)) {
            throw new RuntimeException('Invalid UE1 class export serial bounds');
        }
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open UE1 class export');}
        // Epic ULinkerLoad FArchive can read beyond an export's declared
        // SerialSize before its outer Preload logic reports a mismatch.
        // Use physical package EOF for reads, retain $end for final archive
        // completion; never conflate export bounds with FArchive EOF.
        $physicalSize=filesize($path);
        try {
            if(fseek($fp,$start)!==0){throw new RuntimeException('Cannot seek class export');}
            $position=$start;
            $read=static function(int $n)use($fp,$physicalSize,&$position):string{
                if($n<0||$n>$physicalSize-$position){throw new RuntimeException('UE1 UClass archive overrun at physical package EOF');}
                $bytes='';
                while(strlen($bytes)<$n){
                    $part=fread($fp,$n-strlen($bytes));
                    if(!is_string($part)||$part===''){throw new RuntimeException('Truncated UClass archive');}
                    $bytes.=$part;
                }
                $position+=$n;return $bytes;
            };
            $u8=static fn():int=>ord($read(1));
            $u16=static fn():int=>(int)unpack('v',$read(2))[1];
            $u32=static fn():int=>(int)unpack('V',$read(4))[1];
            $i32=static function()use($u32):int{$v=$u32();return $v>=0x80000000?$v-0x100000000:$v;};
            $compact=static function()use($u8):int{
                $first=$u8();$minus=($first&0x80)!==0;$n=$first&63;
                $more=($first&0x40)!==0;$shift=6;$len=1;
                while($more){
                    if(++$len>5){throw new RuntimeException('Malformed UClass compact index');}
                    $b=$u8();$n|=($b&127)<<$shift;$more=($b&128)!==0;$shift+=7;
                }
                return $minus?-$n:$n;
            };
            $reference=static function(string $label)use(
                $compact,$pkg,$family,&$position
            ):int{
                $id=$compact();
                if(($id<0&&-$id>count($pkg->getImports()))
                    ||($id>0&&$id>count($pkg->getExports()))){
                    // Actual original compiled retail 17-May-1998 C98
                    // Core.dll on genuine original UCC-produced class:
                    // SuperField ref -2 -> -63 at absolute file275
                    // reports Bad import index 62/7 in
                    // ULinkerLoad::IndexToObject, FArchive::Tell=276.
                    // Do not invent a different v432/227k diagnostic.
                    if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1){
                        if($id<0){
                            throw new RuntimeException(sprintf(
                                'UE1 Bad import index %d/%d at archive cursor %d',
                                -$id-1,count($pkg->getImports()),$position));
                        }
                        // Same ORIGINAL 1998 licensed C98 compiled 761B
                        // class package, mutated SuperField 0x82 -> 0x3F
                        // at original file275. ORIGINAL compiled Core.dll
                        // reports Bad export index 62/6 at Tell276.
                        if($id>0){
                            throw new RuntimeException(sprintf(
                                'UE1 Bad export index %d/%d at archive cursor %d',
                                $id-1,count($pkg->getExports()),$position));
                        }
                    }
                    throw new RuntimeException('Invalid UClass '.$label.' reference');
                }
                return $id;
            };
            $names=$pkg->getNames();
            $name=static function(string $label)use($compact,$names):array{
                $id=$compact();
                if($id<0||!isset($names[$id])){
                    throw new RuntimeException('Invalid UClass '.$label.' name index');
                }
                return ['index'=>$id,'text'=>(string)$names[$id]['name']];
            };
            $record=[
                'export_index'=>$index,'class_name'=>'Class',
                'source_version'=>$version,'class_metadata_serial_offset'=>$start,
                'loaded_object_flags_after_uobject_serialize'=>
                    ((int)$ex['objectFlags']|0x40000000),
                'source_sets_rf_debug_serialize'=>$family!==CatalogUE1ClassicSourceFamily::UNREAL_V227,
                'execution_performed'=>false,'runtime_refs_resolved'=>false,
            ];
            // The header-only v227 branch has no native Serialize body.
            // Its high-bit flag is PendingDelete, not DebugSerialize.
            if($family===CatalogUE1ClassicSourceFamily::UNREAL_V227){
                $record['loaded_object_flags_after_uobject_serialize']=null;
            }
            if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1&&$version<40){
                $record['legacy_database']=['count'=>$i32(),'capacity'=>$i32()];
            }
            // UObject::Serialize puts the execution-frame archive BEFORE
            // UField/UStruct/UClass members. The same Epic source branch
            // applies to flagged class objects and ordinary object exports.
            $stateFrame=CatalogUE1ClassicStateFrameReader::read(
                $pkg,$version,(int)$ex['objectFlags'],
                $read,$compact,$i32,$position,0,$family,$path,$index
            );
            $record['serialized_state_frame']=$stateFrame;
            if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1
                &&$version<47&&$stateFrame!==null){
                // Retain the existing legacy view for archive consumers.
                $record['legacy_main_frame']=[
                    'old_class'=>$stateFrame['legacy_class_reference'],
                    'old_node'=>$stateFrame['legacy_node_number'],
                    'temp_reference'=>$stateFrame['legacy_temp_reference'],
                    'probe_mask_hex'=>$stateFrame['probe_mask_le_hex'],
                ];
            }
            if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1&&$version<57){
                $record['legacy_state_name']=$name('StateName');
                $record['legacy_state_name_after_load']=$record['legacy_state_name'];
                if(strcasecmp($record['legacy_state_name']['text'],'PlaySound')===0){
                    $record['legacy_state_name_after_load']=[
                        'index'=>null,'text'=>'PlaySoundEffect',
                        'source_renamed_from'=>'PlaySound'];
                }
            }
            if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1&&$version<58){
                $record['legacy_group_name']=$name('GroupName');
            }
            // UObject skips SerializeTaggedProperties when Class == UClass.
            $record['super_field_reference']=$reference('UField.SuperField');
            $record['next_field_reference']=$reference('UField.Next');
            $record['script_text_reference']=$reference('UStruct.ScriptText');
            $record['children_reference']=$reference('UStruct.Children');
            $friendly=$name('UStruct.FriendlyName');
            if(strcasecmp($friendly['text'],'None')===0){
                throw new RuntimeException('UStruct FriendlyName cannot be NAME_None');
            }
            $record['friendly_name']=$friendly;
            $record['source_line']=$i32();
            $record['source_text_pos']=$i32();
            $scriptSize=$i32();
            if($scriptSize<0){throw new RuntimeException('Negative UClass script size');}
            $record['script_size']=$scriptSize;
            if($scriptSize>0){
                // Epic iCode advances in memory by sizeof(T) for every
                // XFER(T), whereas the archive uses compact object/name
                // references. Exact supported token traversal is required.
                $script=CatalogUE1ClassicScriptArchiveReader::inspect(
                    $pkg,$version,$scriptSize,$read,$compact,$position,$family);
                $record['script_archive']=$script;
                if($script['status']!=='script_archive_expressions_decoded'){
                    return array_replace($record,[
                        'status'=>'class_script_expr_unimplemented',
                        'unparsed_bytes'=>$end-$position,
                    ]);
                }
            }
            // UState::Serialize
            $record['probe_mask_hex']=bin2hex($read(8));
            $record['ignore_mask_hex']=bin2hex($read(8));
            $record['label_table_offset']=$u16();
            $record['state_flags']=$u32();
            // UClass::Serialize: old ClassRecordSize only when Ver<=61.
            $record['selected_source_family']=$family;
            $record['old_class_record_size']=$version<=61?$i32():null;
            // UT99 v400 Core/Src/UnClass.cpp UClass::Serialize sets
            // RF_Public|RF_Standalone when loading a <=61 archive.
            // This is a source-defined *load effect*, not file bytes.
            $legacyUt99=$family===CatalogUE1ClassicSourceFamily::UT99_V432
                &&$version<=61;
            $record['source_legacy_class_flags_added']=$legacyUt99
                ?0x00080004:0;
            $record['loaded_object_flags_after_uclass_serialize']=
                $record['loaded_object_flags_after_uobject_serialize']===null
                    ?null:($record['loaded_object_flags_after_uobject_serialize']
                        |$record['source_legacy_class_flags_added']);
            $record['class_flags']=$u32();
            $record['class_guid_raw_hex']=strtoupper(bin2hex($read(16)));
            // TArray<FDependency>, FDependency::operator<<:
            // UObject* Class, UBOOL Deep, DWORD ScriptTextCRC.
            $depCount=$compact();
            if($depCount<0||$depCount>intdiv($physicalSize-$position,9)){
                throw new RuntimeException('Invalid UClass dependency count');
            }
            $deps=[];
            for($i=0;$i<$depCount;$i++){
                $deps[]=['class_reference'=>$reference('FDependency.Class'),
                    'deep'=>$i32(),'script_text_crc'=>$u32()];
            }
            $record['dependencies']=$deps;
            $importCount=$compact();
            if($importCount<0||$importCount>$physicalSize-$position){
                throw new RuntimeException('Invalid UClass PackageImports count');
            }
            $imports=[];
            for($i=0;$i<$importCount;$i++){$imports[]=$name('PackageImports');}
            $record['package_imports']=$imports;
            if($version>=62){
                $record['class_within_reference']=$reference('ClassWithin');
                $record['class_config_name']=$name('ClassConfigName');
            }else{
                // UT99 UClass sets ClassWithin=UObject::StaticClass()
                // on <=61 loads. That is not an archived linker ref.
                $record['class_within_reference']=null;
                $record['class_within_after_load']=$legacyUt99
                    ?'UObject::StaticClass':null;
                $record['class_config_name']=['index'=>null,'text'=>'System'];
            }
            $record['source_postload_defaults_effects']=$family===
                CatalogUE1ClassicSourceFamily::EPIC_UE1
                ?['InitProperties','GetDefaultObject::SetClass',
                   'GetDefaultObject::LoadConfig(Config)',
                   'GetDefaultObject::LoadConfig(Localized)']
                :['InitClassDefaultObject',
                   'GetDefaultObject::LoadConfig',
                   'GetDefaultObject::LoadLocalized'];
            $record['runtime_postload_effects_executed']=false;
            $record['defaults_serial_offset']=$position;
            // Epic UClass::Serialize invokes UObject::SerializeTaggedProperties
            // for the class defaults, using precisely the SAME FPropertyTag
            // archive as ordinary UObject properties. Structural envelopes
            // decode here; accurate value semantics still require the class
            // property schema, not merely the encoded tag type.
            // Default-tag VectorProperty (11) is genuine v227
            // UAnyProperty only when *this* class defines a linked
            // UAnyProperty field. The original 2026 compiled UCC
            // G3Any227K defaults and matching real CDO type5/type7
            // require the local Children/Next source identity.
            // Keep this disabled in recursive metadata scans.
            $defaultLocalFields=$verifyDefaultDeclarations
                ?CatalogUE1ClassicClassDefaultSchemaReader::
                    resolveLocalClassDefaultDeclarations(
                        $path,$pkg,(int)($record['children_reference']??0),
                        $family)
                :null;
            $defaults=CatalogUE1ClassicTaggedPropertyReader::inspectClassDefaults(
                $path,$pkg,$position,$end,$version,$family,$defaultLocalFields);
            $position=$defaults['tag_end_offset'];
            // inspectClassDefaults uses its own file handle. Restore this
            // reader's physical cursor before the legacy v<57 trailer:
            // updating the logical offset alone replays the tag bytes as
            // the two trailing FNames on nonempty class defaults.
            if(fseek($fp,$position)!==0){
                throw new RuntimeException('Cannot seek after UClass default tags');
            }
            if($version<57 && $family===CatalogUE1ClassicSourceFamily::EPIC_UE1){
                // Epic Unreal v1.200 Core/Src/UnClass.cpp::UClass::Serialize
                // archives TWO trailing FName values AFTER the tagged
                // defaults when Ar.Ver()<57. UT99 v400 removed this
                // source branch; these are not unparsed native extensions.
                $record['legacy_class_trailer_names']=[
                    $name('UClass legacy trailer X'),
                    $name('UClass legacy trailer Y'),
                ];
            }else{
                $record['legacy_class_trailer_names']=null;
            }
            $record['default_property_tags']=$defaults['tags'];
            $record['defaults_tag_count']=count($defaults['tags']);
            // A NAME_None-terminated FPropertyTag archive and all of
            // UClass's source trailing FArchive fields are consumed.
            // Value application/linker fixups are separate operations.
            $record['source_file_archive_complete']=$position===$end;
            $record['source_file_archive_consumed_bytes']=$position-$start;
            $record['source_file_archive_trailing_bytes']=$end-$position;
            $record['defaults_schema_verified']=false;
            if($record['defaults_tag_count']>0&&$verifyDefaultDeclarations){
                $record['default_property_declaration_validation']=
                    CatalogUE1ClassicClassDefaultSchemaReader::inspect(
                        $path,$pkg,$record,$defaults['tags'],$family);
                $validation=$record['default_property_declaration_validation'];
                $record['defaults_schema_verified']=
                    ($validation['status']??null)==='all_available_declarations_match';
            }
            if($record['defaults_tag_count']>0){
                return array_replace($record,[
                    'status'=>$record['defaults_schema_verified']
                        ?'class_default_tags_schema_checked_values_unverified'
                        :'class_default_tags_structurally_decoded_schema_unverified',
                    'script_has_unparsed_expressions'=>false,
                    'unparsed_bytes'=>$end-$position,
                    'extension_bytes'=>$end-$position,
                    'source_file_serialization_status'=>$position===$end
                        ?'complete_file_archive_runtime_values_unverified'
                        :'class_export_contains_unresolved_extension',
                ]);
            }
            return array_replace($record,[
                'status'=>$position===$end
                    ?($scriptSize>0?'class_metadata_supported_script_no_defaults_decoded'
                        :'class_metadata_no_script_no_defaults_complete')
                    :'class_metadata_has_extension',
                'script_has_unparsed_expressions'=>false,
                'defaults_tag_count'=>0,'extension_bytes'=>$end-$position,
            ]);
        }finally{fclose($fp);}
    }
}
