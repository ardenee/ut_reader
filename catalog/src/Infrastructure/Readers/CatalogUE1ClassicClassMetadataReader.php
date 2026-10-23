<?php
/**
 * Classic UE1 classIndex=0 UClass archive, source-gated structural reader.
 *
 * Source: Unreal v1.200 Core/Src/UnObj.cpp:433 (UObject::Serialize)
 * and Core/Src/UnClass.cpp:236,476,921,1440,1067;
 * UT99 v400 Core/Src/UnObj.cpp:478 and UnClass.cpp:239,593,712,914,1048.
 *
 * This decodes only the source-defined *complete no-script, empty-default*
 * branch. Non-empty SerializeExpr code or tagged defaults are explicitly
 * incomplete, never silently skipped or called accepted.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicClassMetadataReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $path, CatalogUE1PackageReader $pkg, int $index,
        bool $verifyDefaultDeclarations=true
    ): array
    {
        $exports = $pkg->getExports();
        $ex = $exports[$index] ?? null;
        if (!is_array($ex) || (int)$ex['classIndex'] !== 0) {
            throw new RuntimeException('Not a UE1 UClass export');
        }
        $version = (int)($pkg->getHeader()['version'] ?? 0);
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
        try {
            if(fseek($fp,$start)!==0){throw new RuntimeException('Cannot seek class export');}
            $position=$start;
            $read=static function(int $n)use($fp,$end,&$position):string{
                if($n<0||$n>$end-$position){throw new RuntimeException('UE1 UClass archive overrun');}
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
            $reference=static function(string $label)use($compact,$pkg):int{
                $id=$compact();
                if(($id<0&&-$id>count($pkg->getImports()))
                    ||($id>0&&$id>count($pkg->getExports()))){
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
                'execution_performed'=>false,'runtime_refs_resolved'=>false,
            ];
            if($version<40){
                $record['legacy_database']=['count'=>$i32(),'capacity'=>$i32()];
            }
            // UObject::Serialize puts the execution-frame archive BEFORE
            // UField/UStruct/UClass members. The same Epic source branch
            // applies to flagged class objects and ordinary object exports.
            $stateFrame=CatalogUE1ClassicStateFrameReader::read(
                $pkg,$version,(int)$ex['objectFlags'],
                $read,$compact,$i32,$position
            );
            $record['serialized_state_frame']=$stateFrame;
            if($version<47&&$stateFrame!==null){
                // Retain the existing legacy view for archive consumers.
                $record['legacy_main_frame']=[
                    'old_class'=>$stateFrame['legacy_class_reference'],
                    'old_node'=>$stateFrame['legacy_node_number'],
                    'temp_reference'=>$stateFrame['legacy_temp_reference'],
                    'probe_mask_hex'=>$stateFrame['probe_mask_le_hex'],
                ];
            }
            if($version<57){$record['legacy_state_name']=$name('StateName');}
            if($version<58){$record['legacy_group_name']=$name('GroupName');}
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
                    $pkg,$version,$scriptSize,$read,$compact,$position);
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
            $record['old_class_record_size']=$version<=61?$i32():null;
            $record['class_flags']=$u32();
            $record['class_guid_raw_hex']=strtoupper(bin2hex($read(16)));
            // TArray<FDependency>, FDependency::operator<<:
            // UObject* Class, UBOOL Deep, DWORD ScriptTextCRC.
            $depCount=$compact();
            if($depCount<0||$depCount>intdiv($end-$position,9)){
                throw new RuntimeException('Invalid UClass dependency count');
            }
            $deps=[];
            for($i=0;$i<$depCount;$i++){
                $deps[]=['class_reference'=>$reference('FDependency.Class'),
                    'deep'=>$i32(),'script_text_crc'=>$u32()];
            }
            $record['dependencies']=$deps;
            $importCount=$compact();
            if($importCount<0||$importCount>$end-$position){
                throw new RuntimeException('Invalid UClass PackageImports count');
            }
            $imports=[];
            for($i=0;$i<$importCount;$i++){$imports[]=$name('PackageImports');}
            $record['package_imports']=$imports;
            if($version>=62){
                $record['class_within_reference']=$reference('ClassWithin');
                $record['class_config_name']=$name('ClassConfigName');
            }else{
                $record['class_within_reference']=null;
                $record['class_config_name']=['index'=>null,'text'=>'System'];
            }
            $record['defaults_serial_offset']=$position;
            // Epic UClass::Serialize invokes UObject::SerializeTaggedProperties
            // for the class defaults, using precisely the SAME FPropertyTag
            // archive as ordinary UObject properties. Structural envelopes
            // decode here; accurate value semantics still require the class
            // property schema, not merely the encoded tag type.
            $defaults=CatalogUE1ClassicTaggedPropertyReader::inspectClassDefaults(
                $path,$pkg,$position,$end,$version);
            $position=$defaults['tag_end_offset'];
            if($version<57){
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
            $record['defaults_schema_verified']=false;
            if($record['defaults_tag_count']>0&&$verifyDefaultDeclarations){
                $record['default_property_declaration_validation']=
                    CatalogUE1ClassicClassDefaultSchemaReader::inspect(
                        $path,$pkg,$record,$defaults['tags']);
            }
            if($record['defaults_tag_count']>0){
                return array_replace($record,[
                    'status'=>'class_default_tags_structurally_decoded_schema_unverified',
                    'script_has_unparsed_expressions'=>false,
                    'unparsed_bytes'=>$end-$position,
                    'extension_bytes'=>$end-$position,
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
