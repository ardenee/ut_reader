<?php
/**
 * Source-scoped UClass default-tag validation against serialized UProperty
 * declarations in the same genuine package, not a global name guess.
 *
 * UE1 UnClass.cpp: UStruct.Children / UField.Next form an ordered field
 * chain. UProperty::Serialize stores ArrayDim and its subclass determines
 * the FPropertyTag type. SuperField can point to a local UClass or an
 * external import; never infer imported definitions without that package.
 *
 * This proves matching names/type and ArrayDim for locally resolvable
 * fields. It does NOT prove imported superclasses, native CDO values,
 * opaque struct payload semantics or runtime property offsets.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicClassDefaultSchemaReader
{
    /** @var array<string,array<string,mixed>> */
    private static array $fieldCache=[];
    /** @var array<string,CatalogUE1PackageReader> */
    private static array $packageCache=[];

    /**
     * @param array<string,mixed> $classRecord
     * @param list<array<string,mixed>> $tags
     * @return array<string,mixed>
     */
    public static function inspect(
        string $path,CatalogUE1PackageReader $pkg,array $classRecord,array $tags,
        ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::selectFromHeader(
            $pkg->getHeader(),$sourceFamily);
        $valueArchivePackage=$pkg;
        $valuePath=$path;
        $classRef=(int)($classRecord['export_index']??-1)+1;
        $current=$classRecord;
        $visited=[];$declarations=[];$inherited=false;$importBlocked=false;
        $ownFields=0;$parentFields=0;$importedParents=0;
        while($classRef>0){
            $visitKey=$path."\0".$classRef;
            if(isset($visited[$visitKey])||count($visited)>=128){
                throw new RuntimeException('UE1 UClass inheritance cycle or depth overflow');
            }
            $visited[$visitKey]=true;
            $fields=self::fieldChain(
                $path,$pkg,(int)($current['children_reference']??0),$family);
            foreach($fields as $name=>$decl){
                // Child definitions shadow declarations from superclasses.
                if(!isset($declarations[$name])){
                    $declarations[$name]=$decl+['owner_ref'=>$classRef,
                        'inherited'=>$inherited];
                    if($inherited){$parentFields++;}else{$ownFields++;}
                }
            }
            $next=(int)($current['super_field_reference']??0);
            if($next<0){
                // Resolve only an actual imported UClass whose outer is
                // a genuine root Package import, in a sibling .u file.
                // No cross-package guessing by class name alone.
                $resolved=self::importedParent($path,$pkg,$next);
                if($resolved===null){$importBlocked=true;break;}
                $path=$resolved['path'];$pkg=$resolved['package'];
                $classRef=$resolved['ref'];$importedParents++;
                $current=CatalogUE1ClassicClassMetadataReader::inspect(
                    $path,$pkg,$classRef-1,false,$family);
                $inherited=true;
                continue;
            }
            if($next===0){break;}
            $export=$pkg->getExports()[$next-1]??null;
            if(!is_array($export)||(int)$export['classIndex']!==0){
                throw new RuntimeException('UE1 UClass superclass is not a local class export');
            }
            // Prevent recursive default-schema checking while resolving
            // the local parent; only source-defined UClass header is needed.
            $current=CatalogUE1ClassicClassMetadataReader::inspect(
                $path,$pkg,$next-1,false,$family);
            $classRef=$next;$inherited=true;
        }
        $matched=0;$matchedDirect=0;$matchedInherited=0;$unresolved=0;
        $unknownSkipped=0;$unknownSamples=[];
        $mismatches=[];$mismatchCount=0;$samples=[];
        $stringBoundUnverified=0;$stringBoundSamples=[];
        $referenceBoundsChecked=0;
        $legacyStringUpgrades=0;$legacyStringUnsafe=0;$legacyStringSamples=[];
        $dynamicStringUnsafe=0;$dynamicStringSamples=[];
        $arrayDecoded=0;$arrayUnverified=0;$arraySamples=[];
        $structDecoded=0;$structUnverified=0;$structSamples=[];
        $mapNoArchiveBytes=0;$mapValueUnverified=0;$mapSamples=[];
        $sourceVersion=(int)($valueArchivePackage->getHeader()['version']??0);
        foreach($tags as $tag){
            $key=strtolower((string)($tag['name']??''));
            $decl=$declarations[$key]??null;
            if($decl===null){
                if($importBlocked){
                    $unresolved++;
                    if(count($samples)<8){$samples[]=['name'=>$tag['name'],
                        'reason'=>'imported_superclass_unavailable'];}
                }else{
                    // All class ancestors have been traversed but Epic
                    // cannot find a corresponding property. C98 loader
                    // logs a warning and skips the declared tag bytes.
                    $unknownSkipped++;
                    if(count($unknownSamples)<8){
                        $unknownSamples[]=['name'=>$tag['name'],
                            'reason'=>'property_not_found_warn_and_skip'];
                    }
                }
                continue;
            }
            $tagKind=strtolower((string)($tag['type']??''));
            $declKind=(string)$decl['kind'];
            // Epic UClassProperty derives from UObjectProperty; older
            // FPropertyTag encodes it as an object reference type.
            $legacyStringUpgrade=$family===CatalogUE1ClassicSourceFamily::UT99_V400
                &&$tagKind==='stringproperty'&&$declKind==='strproperty';
            $typeValid=$tagKind===$declKind
                ||($tagKind==='objectproperty'&&$declKind==='classproperty')
                ||$legacyStringUpgrade;
            $arrayValid=(int)($tag['array_index']??-1)>=0
                &&(int)($tag['array_index']??-1)<(int)$decl['array_dim'];
            $stringCapacity=$decl['string_size']??null;
            $structName=$decl['struct_name']??null;
            $tagStructName=(string)($tag['struct_name']['text']??'');
            // C98 v1.200 has oldver Rotation->Rotator and
            // Region->PointRegion aliases before comparing struct names.
            // The UT99 v400 source does NOT have those aliases. Both
            // engines can serialize overlapping archive versions, so
            // this follows the narrowly established source family gate.
            if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1){
                if(strcasecmp($tagStructName,'Rotation')===0){
                    $tagStructName='Rotator';
                }elseif(strcasecmp($tagStructName,'Region')===0){
                    $tagStructName='PointRegion';
                }
            }
            $structValid=$tagKind!=='structproperty'
                ||($structName!==null
                    &&strcasecmp($tagStructName,(string)$structName)===0);
            $serializable=(((int)($decl['property_flags']??0))&0x00003000)===0;
            $stringLengthValid=$declKind!=='stringproperty'
                ||($stringCapacity!==null
                    &&(int)($tag['serialized_size']??-1)>=1
                    &&(int)$tag['serialized_size']<(int)$stringCapacity);
            if($legacyStringUpgrade){
                // UT99 v400 Core/Src/UnClass.cpp: old UStringProperty
                // tag is upgraded to UStrProperty before the normal type,
                // array-bounds and ShouldSerializeValue checks.
                // Never call the generic mismatch/skip branch here.
                $length=(int)($tag['serialized_size']??0);
                $validBytes=($tag['value_kind']??'')==='legacy_ansi_cstring_bytes'
                    &&is_array($tag['value']??null)
                    &&($tag['value']['nul_terminated']??false)===true
                    &&$length>=1&&$length<=254;
                if(!$validBytes||!$arrayValid){
                    // A malformed/unbounded input cannot be described as
                    // a safe "warn and skip": Epic reaches a separate,
                    // unchecked legacy-value conversion branch.
                    $legacyStringUnsafe++;
                    if(count($legacyStringSamples)<12){
                        $legacyStringSamples[]=['name'=>$tag['name'],
                            'reason'=>!$arrayValid?
                                'legacy_upgrade_array_bounds_unchecked_by_source':
                                'legacy_upgrade_ansi_stream_unverified',
                            'serialized_size'=>$length,
                            'array_index'=>$tag['array_index'],
                            'declared_array_dim'=>$decl['array_dim']];
                    }
                    continue;
                }
                $legacyStringUpgrades++;
                $matched++;
                if($decl['inherited']){$matchedInherited++;}else{$matchedDirect++;}
                continue;
            }
            if($family===CatalogUE1ClassicSourceFamily::UT99_V400&&$tagKind==='strproperty'
               &&$declKind==='strproperty'
               &&!in_array($tag['value_kind']??'',[
                 'fstring_ansi_archive','fstring_utf16_archive'],true)){
                // The native UStrProperty::SerializeItem expects the
                // source-counted FString archive (UnMisc.cpp); a bad count,
                // length or terminator is not a successfully loaded value.
                $dynamicStringUnsafe++;
                if(count($dynamicStringSamples)<12){
                    $dynamicStringSamples[]=['name'=>$tag['name'],
                        'reason'=>'invalid_source_counted_fstring_archive',
                        'size'=>$tag['serialized_size']??null];
                }
                continue;
            }
            if(!$typeValid||!$arrayValid){
                // C98 Core/Src/UnClass.cpp UStruct::SerializeTaggedProperties:
                // an incompatible known property emits NAME_Warning; its
                // serialized Tag.Size bytes have already been consumed by
                // the shared tagged-property archive. Do not abort the
                // whole class or incorrectly accept this value.
                $mismatchCount++;
                if(count($mismatches)<16){
                    $mismatches[]=['name'=>$tag['name'],
                        'reason'=>!$typeValid?'type_mismatch':'array_index_out_of_bounds',
                        'actual_type'=>$tagKind,'declared_kind'=>$declKind,
                        'array_index'=>$tag['array_index'],
                        'declared_array_dim'=>$decl['array_dim'],
                        'declared_string_capacity'=>$stringCapacity,
                        'field_export_ref'=>$decl['ref']];
                }
                continue;
            }
            if(!$structValid||!$serializable){
                $mismatchCount++;
                if(count($mismatches)<16){
                    $mismatches[]=['name'=>$tag['name'],
                        'reason'=>!$structValid?'struct_type_mismatch':
                            'transient_or_intrinsic_property_skipped',
                        'actual_struct'=>$tagStructName,
                        'declared_struct'=>$structName,
                        'property_flags'=>$decl['property_flags']??null,
                        'field_export_ref'=>$decl['ref']];
                }
                continue;
            }
            if($family===CatalogUE1ClassicSourceFamily::UT99_V400
               &&in_array($tagKind,['arrayproperty','fixedarrayproperty'],true)
               &&$tagKind===$declKind){
                $decoded=CatalogUE1ClassicArrayValueReader::inspect(
                    $valuePath,$valueArchivePackage,
                    (string)$decl['owner_path'],$decl['owner_package'],
                    $tag,(int)($decl['inner_reference']??0),
                    $tagKind==='fixedarrayproperty'?
                        (int)($decl['fixed_count']??0):null,$family);
                if($decoded['status']==='array_inner_values_decoded'){
                    $arrayDecoded++;
                }else{
                    $arrayUnverified++;
                }
                if(count($arraySamples)<16){
                    $arraySamples[]=['name'=>$tag['name']]+$decoded;
                }
                if($decoded['status']!=='array_inner_values_decoded'){
                    continue;
                }
            }
            if($family===CatalogUE1ClassicSourceFamily::UT99_V400&&$tagKind==='mapproperty'
                &&$declKind==='mapproperty'){
                // UT99 UMapProperty::SerializeItem checks Key/Value but
                // its entire archive loop is commented out. The native
                // UMapProperty::Serialize does persist Key and Value
                // UProperty identities. Confirm both declarations, then
                // accept ONLY zero-byte values as source-representable.
                $keyRef=(int)($decl['key_reference']??0);
                $valueRef=(int)($decl['value_reference']??0);
                $validChildren=$keyRef>0&&$valueRef>0;
                if($validChildren){
                    foreach([$keyRef,$valueRef] as $childRef){
                        $child=self::readField(
                            (string)$decl['owner_path'],$decl['owner_package'],
                            $childRef,$family);
                        if(($child['native']['status']??'')
                            !=='native_property_record_complete'){
                            $validChildren=false;break;
                        }
                    }
                }
                $emptyValue=(int)($tag['serialized_size']??-1)===0
                    &&(int)($tag['on_disk_value_bytes']??-1)===0;
                if(!$validChildren||!$emptyValue){
                    $mapValueUnverified++;
                    if(count($mapSamples)<12){
                        $mapSamples[]=['name'=>$tag['name'],
                            'status'=>'map_source_payload_unverified',
                            'reason'=>!$validChildren?
                                'key_or_value_declaration_unresolved':
                                'unexpected_map_value_bytes',
                            'serialized_size'=>$tag['serialized_size']??null];
                    }
                    continue;
                }
                $mapNoArchiveBytes++;
                if(count($mapSamples)<12){
                    $mapSamples[]=['name'=>$tag['name'],
                        'status'=>'map_source_no_persisted_entries',
                        'serialized_size'=>0,
                        'key_property_ref'=>$keyRef,
                        'value_property_ref'=>$valueRef,
                        'runtime_map_contents_verified'=>false];
                }
            }
            if($tagKind==='objectproperty'||$tagKind==='classproperty'){
                // Epic C98 ULinkerLoad::IndexToObject checks IsValidIndex
                // on signed import/export indices and errors fatally when
                // outside the file maps. Check in the ORIGINAL tag package
                // rather than its potentially external superclass package.
                $index=$tag['value']??null;
                if(($tag['value_kind']??'')!=='package_object_index'
                   ||!is_int($index)
                   ||($index>0&&$index>count($valueArchivePackage->getExports()))
                   ||($index<0&&-$index>count($valueArchivePackage->getImports()))){
                    throw new RuntimeException(
                        'UE1 class default has invalid linker object reference: '.
                        (string)$tag['name']);
                }
                $referenceBoundsChecked++;
            }elseif($tagKind==='nameproperty'){
                // Epic C98 ULinkerLoad<<FName invokes
                // NameMap.IsValidIndex; invalid name indices are fatal.
                $index=$tag['value']??null;
                if(($tag['value_kind']??'')!=='fname_index'
                   ||!is_int($index)||$index<0
                   ||$index>=count($valueArchivePackage->getNames())){
                    throw new RuntimeException(
                        'UE1 class default has invalid linker FName: '.
                        (string)$tag['name']);
                }
                $referenceBoundsChecked++;
            }
            if(!$stringLengthValid){
                // C98 FArchive::String reads at most StringSize-1 bytes;
                // it does NOT take the generic mismatched-tag warning
                // branch. The exact behaviour of a longer tag payload
                // cannot be treated as a safely skipped property.
                $stringBoundUnverified++;
                if(count($stringBoundSamples)<16){
                    $stringBoundSamples[]=['name'=>$tag['name'],
                        'serialized_size'=>$tag['serialized_size'],
                        'declared_string_capacity'=>$stringCapacity,
                        'field_export_ref'=>$decl['ref']];
                }
                continue;
            }
            if($tagKind==='structproperty'&&$declKind==='structproperty'
                &&($tag['value_kind']??'opaque')==='opaque'){
                // Epic UStructProperty::SerializeItem delegates to the
                // property declaration's Struct->SerializeBin. Reuse the
                // existing native field-order decoder used for array
                // elements instead of treating all UClass structs opaque.
                $layout=CatalogUE1ClassicStructArrayValueReader::resolve(
                    (string)$decl['owner_path'],$decl['owner_package'],
                    (int)($decl['struct_reference']??0),$family);
                $decoded=['status'=>$layout['status']];
                if($layout['status']==='struct_local_field_layout_resolved'){
                    $length=(int)($tag['serialized_size']??-1);
                    $offset=(int)($tag['value_offset']??-1);
                    $fileLength=filesize($valuePath);
                    if($length>=0&&$offset>=0&&$fileLength!==false
                        &&$offset<=$fileLength-$length){
                        $fp=fopen($valuePath,'rb');
                        if($fp===false){
                            throw new RuntimeException('Cannot open UE1 struct default');
                        }
                        try{
                            if(fseek($fp,$offset)!==0){
                                throw new RuntimeException('Cannot seek UE1 struct default');
                            }
                            $bytes='';
                            while(strlen($bytes)<$length){
                                $part=fread($fp,$length-strlen($bytes));
                                if(!is_string($part)||$part===''){
                                    throw new RuntimeException('Truncated UE1 struct default');
                                }
                                $bytes.=$part;
                            }
                        }finally{fclose($fp);}
                        if($length>0
                            &&($tag['value_sha256']??null)!==hash('sha256',$bytes)){
                            throw new RuntimeException('UE1 struct default changed after tag parse');
                        }
                        $cursor=0;
                        $decoded=CatalogUE1ClassicStructArrayValueReader::readAt(
                            $bytes,$cursor,$layout,true,$valueArchivePackage);
                        if($decoded['status']==='struct_field_values_decoded'
                            &&$cursor!==$length){
                            $decoded=['status'=>'struct_unconsumed_archive_bytes',
                                'decoded_bytes'=>$cursor,'declared_bytes'=>$length];
                        }
                    }else{
                        $decoded=['status'=>'struct_default_outside_archive'];
                    }
                }
                if($decoded['status']==='struct_field_values_decoded'){
                    $structDecoded++;
                }else{
                    $structUnverified++;
                }
                if(count($structSamples)<16){
                    $structSamples[]=['name'=>$tag['name']]+$decoded;
                }
                if($decoded['status']!=='struct_field_values_decoded'){
                    continue;
                }
            }
            $matched++;
            if($decl['inherited']){$matchedInherited++;}else{$matchedDirect++;}
        }
        return [
            'status'=>$mapValueUnverified>0?'map_property_value_unverified':
                ($structUnverified>0?'struct_property_value_unverified':
                ($arrayUnverified>0?'array_property_value_unverified':
                ($dynamicStringUnsafe>0?'dynamic_fstring_archive_unverified':
                ($legacyStringUnsafe>0?'legacy_fixed_string_upgrade_unverified':
                ($mismatchCount>0?'known_incompatible_tags_skipped':
                ($unknownSkipped>0?'unknown_property_tags_skipped':
                ($stringBoundUnverified>0?'fixed_string_archive_size_unverified':
                ($unresolved===0?'all_available_declarations_match':
                'imported_or_unknown_properties_unverified')))))))),
            'tag_count'=>count($tags),
            'declaration_type_and_array_index_matches'=>$matched,
            'matches_in_this_class'=>$matchedDirect,
            'matches_in_local_superclasses'=>$matchedInherited,
            'unresolved_tag_count'=>$unresolved,
            'known_incompatible_tags_skipped'=>$mismatchCount,
            'known_unknown_property_tags_skipped'=>$unknownSkipped,
            'known_unknown_property_samples'=>$unknownSamples,
            'fixed_string_archive_bounds_unverified'=>$stringBoundUnverified,
            'fixed_string_archive_bound_samples'=>$stringBoundSamples,
            'incompatible_tag_warning_samples'=>$mismatches,
            'load_policy'=>'warn_and_skip_incompatible_tag_as_epic_source',
            'value_linker_reference_bounds_checked'=>$referenceBoundsChecked,
            'legacy_fixed_string_to_dynamic_upgrades'=>$legacyStringUpgrades,
            'legacy_fixed_string_upgrade_unverified'=>$legacyStringUnsafe,
            'legacy_fixed_string_upgrade_samples'=>$legacyStringSamples,
            'legacy_string_codepage_verified'=>false,
            'dynamic_fstring_archive_unverified'=>$dynamicStringUnsafe,
            'dynamic_fstring_unverified_samples'=>$dynamicStringSamples,
            'source_struct_field_values_decoded'=>$structDecoded,
            'source_struct_field_values_unverified'=>$structUnverified,
            'source_struct_field_value_samples'=>$structSamples,
            'primitive_array_values_decoded'=>$arrayDecoded,
            'array_value_unverified'=>$arrayUnverified,
            'array_value_samples'=>$arraySamples,
            'map_property_source_no_archive_bytes'=>$mapNoArchiveBytes,
            'map_property_value_unverified'=>$mapValueUnverified,
            'map_property_samples'=>$mapSamples,
            'unresolved_examples'=>$samples,
            'source_declarations_in_this_class'=>$ownFields,
            'source_declarations_in_local_superclasses'=>$parentFields,
            'has_unavailable_imported_superclass'=>$importBlocked,
            'source_imported_superclasses_resolved'=>$importedParents,
            'value_payload_semantics_verified'=>false,
            'runtime_property_offsets_verified'=>false,
        ];
    }

    /**
     * Resolve a superclass ONLY by its package import chain:
     * Class import (e.g. Pawn) -> outer root Package (Engine) ->
     * existing sibling Engine.u with one matching exported UClass.
     * A missing file, nested outer, duplicate class or bad package is
     * unresolved; no global cross-package name search or hardcoded paths.
     *
     * @return array{path:string,package:CatalogUE1PackageReader,ref:int}|null
     */
    private static function importedParent(
        string $path,CatalogUE1PackageReader $pkg,int $reference
    ):?array {
        $imports=$pkg->getImports();
        $class=$imports[-$reference-1]??null;
        if(!is_array($class)
            ||strcasecmp((string)($class['classNameText']??''),'Class')!==0){
            return null;
        }
        $outer=(int)($class['outerIndex']??0);
        if($outer>=0){return null;}
        $packageImport=$imports[-$outer-1]??null;
        if(!is_array($packageImport)
            ||strcasecmp((string)($packageImport['classNameText']??''),'Package')!==0
            ||(int)($packageImport['outerIndex']??-1)!==0){
            return null;
        }
        $packageName=(string)($packageImport['objectNameText']??'');
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D',$packageName)){
            return null;
        }
        $candidate=dirname($path).DIRECTORY_SEPARATOR.$packageName.'.u';
        if(!is_file($candidate)){return null;}
        $candidate=realpath($candidate);
        if($candidate===false){return null;}
        if(!isset(self::$packageCache[$candidate])){
            try {
                $linked=new CatalogUE1PackageReader($candidate);
                if($linked->validatePackage()!==[]){return null;}
                self::$packageCache[$candidate]=$linked;
            }catch(RuntimeException){return null;}
        }
        $linked=self::$packageCache[$candidate];
        $className=(string)($class['objectNameText']??'');
        $match=0;
        foreach($linked->getExports() as $export){
            if((int)($export['classIndex']??-1)===0
               &&strcasecmp((string)$export['objectNameText'],$className)===0){
                if($match!==0){return null;}
                $match=(int)$export['index']+1;
            }
        }
        return $match===0?null:[
            'path'=>$candidate,'package'=>$linked,'ref'=>$match];
    }

    /** @return array<string,array{ref:int,kind:string,array_dim:int}> */
    private static function fieldChain(
        string $path,CatalogUE1PackageReader $pkg,int $reference,
        ?string $sourceFamily=null
    ):array {
        $seen=[];$fields=[];
        while($reference>0){
            if(isset($seen[$reference])||count($seen)>=16000){
                throw new RuntimeException('UE1 UField.Next cycle or count exceeded');
            }
            $seen[$reference]=true;
            $field=self::readField($path,$pkg,$reference,$sourceFamily);
            $data=$field['native'];
            if(($data['status']??'')==='native_property_record_complete'){
                $key=strtolower((string)$field['name']);
                $structRef=(int)($data['subclass_fields']['struct_reference']??0);
                $structRow=$structRef>0
                    ?($pkg->getExports()[$structRef-1]??null)
                    :($structRef<0?($pkg->getImports()[-$structRef-1]??null):null);
                $fields[$key]=['ref'=>$reference,
                    'kind'=>(string)$data['property_kind'],
                    'owner_path'=>$path,'owner_package'=>$pkg,
                    'inner_reference'=>$data['subclass_fields']['inner_reference']??0,
                    'fixed_count'=>$data['subclass_fields']['fixed_count']??null,
                    'key_reference'=>$data['subclass_fields']['key_reference']??0,
                    'value_reference'=>$data['subclass_fields']['value_reference']??0,
                    'array_dim'=>(int)$data['array_dim'],
                    'property_flags'=>(int)($data['effective_property_flags']??0),
                    'struct_name'=>$structRow['objectNameText']??null,
                    'struct_reference'=>$structRef,
                    'string_size'=>$data['subclass_fields']['string_size']??null];
            }
            $reference=(int)($data['next_field_reference']??0);
        }
        return $fields;
    }

    /** @return array<string,mixed> */
    private static function readField(
        string $path,CatalogUE1PackageReader $pkg,int $ref,
        ?string $sourceFamily=null
    ):array {
        $key=$path."\0".$ref."\0".($sourceFamily??'engine-default');
        if(isset(self::$fieldCache[$key])){return self::$fieldCache[$key];}
        $ex=$pkg->getExports()[$ref-1]??null;
        if(!is_array($ex)){throw new RuntimeException('Invalid UE1 class field export ref');}
        $tag=CatalogUE1ClassicTaggedPropertyReader::inspect(
            $path,$pkg,$ref-1,$sourceFamily);
        if(($tag['status']??'')!=='tag_envelope_decoded'){
            throw new RuntimeException('Invalid UE1 UField property prefix');
        }
        $native=CatalogUE1ClassicNativeObjectReader::inspect(
            $path,$pkg,$ref-1,$tag,$sourceFamily);
        return self::$fieldCache[$key]=[
            'name'=>$ex['objectNameText'],'native'=>$native];
    }
}
