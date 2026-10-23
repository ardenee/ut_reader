<?php
/**
 * UT99 v400 Core/Src/UnProp.cpp::UStructProperty::SerializeItem delegates
 * to UStruct::SerializeBin. The latter walks TFieldIterator<UProperty>,
 * applying ShouldSerializeValue and each property's ArrayDim.
 *
 * Only fully resolved local UStruct and local ancestor definitions are
 * decoded; native UObject memory offsets and nested structs are not guessed.
 * Supported leaf serializers use the owning value archive's linker maps.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicStructArrayValueReader
{
    /** @return array<string,mixed> */
    public static function resolve(
        string $path,CatalogUE1PackageReader $pkg,int $structReference,
        ?string $sourceFamily=null,array $activeStructs=[]
    ):array {
        if($structReference===0){
            return ['status'=>'struct_reference_none'];
        }
        // UStructProperty::SerializeItem recurses through
        // Struct->SerializeBin. Resolve nested declarations only from
        // their exact linker references; never create a guessed layout.
        $activeKey=$path."\0".$structReference;
        if(isset($activeStructs[$activeKey])||count($activeStructs)>=64){
            return ['status'=>'recursive_struct_layout_unverified'];
        }
        $activeStructs[$activeKey]=true;
        $fields=[];$visited=[];$importHops=0;$nonPropertySkips=0;
        $packageChain=[$path];
        $current=$structReference;
        // UT99 TFieldIterator visits Children, then GetInheritanceSuper.
        // An imported parent must resolve by its full ImportMap outer
        // identity and real package; never guess from short object name.
        while($current!==0){
            if($current<0){
                $resolved=CatalogUE1ClassicStructImportResolver::resolve(
                    $path,$pkg,$current);
                if($resolved===null){
                    return ['status'=>'struct_inherited_import_unverified'];
                }
                $path=$resolved['path'];$pkg=$resolved['package'];
                $current=$resolved['ref'];$packageChain[]=$path;
                if(++$importHops>32){
                    return ['status'=>'struct_import_depth_exceeded'];
                }
            }
            $visitKey=$path."\0".$current;
            if(isset($visited[$visitKey])){
                return ['status'=>'struct_superclass_cycle'];
            }
            $visited[$visitKey]=true;
            $currentEx=$pkg->getExports()[$current-1]??null;
            if(!is_array($currentEx)){
                return ['status'=>'struct_superclass_export_missing'];
            }
            $meta=CatalogUE1ClassicTaggedPropertyReader::inspect(
                $path,$pkg,$current-1,$sourceFamily);
            $native=CatalogUE1ClassicNativeObjectReader::inspect(
                $path,$pkg,$current-1,$meta,$sourceFamily);
            if(strcasecmp((string)($native['class_name']??''),'Struct')!==0
                ||($native['status']??'')!=='native_struct_record_complete'){
                return ['status'=>'struct_native_layout_unverified'];
            }
            $ref=(int)($native['children_reference']??0);
            $seen=[];
            while($ref!==0){
                if($ref<0||isset($seen[$ref])||count($seen)>=count($pkg->getExports())){
                    return ['status'=>'struct_property_chain_invalid'];
                }
                $seen[$ref]=true;
                $fieldEx=$pkg->getExports()[$ref-1]??null;
                if(!is_array($fieldEx)){
                    return ['status'=>'struct_property_reference_invalid'];
                }
                // This property's UField.Outer must be exactly the
                // UStruct whose Children chain we are traversing.
                if((int)($fieldEx['outerIndex']??0)!==$current){
                    return ['status'=>'struct_child_owner_unverified'];
                }
                $tag=CatalogUE1ClassicTaggedPropertyReader::inspect(
                    $path,$pkg,$ref-1,$sourceFamily);
                $field=CatalogUE1ClassicNativeObjectReader::inspect(
                    $path,$pkg,$ref-1,$tag,$sourceFamily);
                if(($field['status']??'')!=='native_property_record_complete'){
                    // UT99 TFieldIterator<UProperty>::IterateToNext()
                    // walks UField.Next until Field->IsA(UProperty).
                    // Non-property native UFields are skipped, never
                    // mistaken for on-disk value fields. Their complete
                    // native UField::Serialize prefix proves Next.
                    $nonPropertyStatuses=[
                        'const'=>'native_const_record_complete',
                        'enum'=>'native_enum_record_complete',
                        'field'=>'native_field_record_complete',
                        'struct'=>'native_struct_record_complete',
                        'state'=>'native_state_record_complete',
                        'function'=>'native_function_record_complete',
                    ];
                    $fieldClass=strtolower((string)($field['class_name']??''));
                    if(($nonPropertyStatuses[$fieldClass]??null)
                        !==($field['status']??null)){
                        return ['status'=>'struct_child_is_not_supported_property'];
                    }
                    $nonPropertySkips++;
                    $ref=(int)($field['next_field_reference']??0);
                    continue;
                }
                $kind=(string)($field['property_kind']??'');
                if(!in_array($kind,[
                    'byteproperty','boolproperty','intproperty',
                    'floatproperty','strproperty','stringproperty',
                    'nameproperty','objectproperty','classproperty',
                    'structproperty','arrayproperty','fixedarrayproperty'
                ],true)){
                    return ['status'=>'struct_member_serializer_unverified',
                        'member_kind'=>$kind];
                }
                $dim=(int)($field['array_dim']??0);
                if($dim<1){
                    return ['status'=>'struct_member_arraydim_invalid'];
                }
                $flags=(int)($field['effective_property_flags']??0);
                $skip=($flags&0x3000)!==0;
                $stringSize=null;
                if($kind==='stringproperty'){
                    // Unreal v1.200 Core/Inc/UnType.h::UStringProperty
                    // archives each fixed CHAR[] via FArchive::String,
                    // not by archiving the full in-memory allocation.
                    $stringSize=(int)($field['subclass_fields']['string_size']??0);
                    if($stringSize<1){
                        return ['status'=>'struct_fixed_string_size_invalid'];
                    }
                }
                $nestedLayout=null;
                $arrayInner=null;
                $arrayCount=null;
                if(in_array($kind,['arrayproperty','fixedarrayproperty'],true)&&!$skip){
                    // UT99 UArrayProperty / UFixedArrayProperty serialize
                    // each element through their own Inner declaration.
                    // Inner must be a real source-linked local property.
                    $innerRef=(int)($field['subclass_fields']['inner_reference']??0);
                    if($innerRef<1){
                        return ['status'=>'struct_array_inner_reference_unavailable'];
                    }
                    $innerEx=$pkg->getExports()[$innerRef-1]??null;
                    if(!is_array($innerEx)
                        ||(int)($innerEx['outerIndex']??0)!==$ref){
                        return ['status'=>'struct_array_inner_owner_unverified'];
                    }
                    $innerTag=CatalogUE1ClassicTaggedPropertyReader::inspect(
                        $path,$pkg,$innerRef-1,$sourceFamily);
                    $inner=CatalogUE1ClassicNativeObjectReader::inspect(
                        $path,$pkg,$innerRef-1,$innerTag,$sourceFamily);
                    if(($inner['status']??null)!=='native_property_record_complete'){
                        return ['status'=>'struct_array_inner_property_unresolved'];
                    }
                    $innerKind=(string)($inner['property_kind']??'');
                    if(!in_array($innerKind,[
                        'byteproperty','boolproperty','intproperty',
                        'floatproperty','nameproperty','objectproperty',
                        'classproperty','strproperty','structproperty'
                    ],true)){
                        return ['status'=>'struct_array_inner_serializer_unverified',
                            'inner_kind'=>$innerKind];
                    }
                    $innerStruct=null;
                    if($innerKind==='structproperty'){
                        $innerStruct=self::resolve(
                            $path,$pkg,
                            (int)($inner['subclass_fields']['struct_reference']??0),
                            $sourceFamily,$activeStructs);
                        if(($innerStruct['status']??null)
                            !=='struct_local_field_layout_resolved'){
                            return ['status'=>'struct_array_inner_struct_unresolved',
                                'reason'=>$innerStruct['status']??'unresolved'];
                        }
                    }
                    $arrayInner=[
                        'name'=>(string)($innerEx['objectNameText']??'Inner'),
                        'kind'=>$innerKind,
                        'skip_persistent_archive'=>false,
                        'nested_layout'=>$innerStruct,
                        'fixed_string_size'=>null,
                        'array_inner_layout'=>null,
                        'fixed_array_count'=>null,
                    ];
                    if($kind==='fixedarrayproperty'){
                        $arrayCount=(int)($field['subclass_fields']['fixed_count']??0);
                        if($arrayCount<1){
                            return ['status'=>'struct_fixed_array_count_invalid'];
                        }
                    }
                }
                if($kind==='structproperty'&&!$skip){
                    $nestedLayout=self::resolve(
                        $path,$pkg,
                        (int)($field['subclass_fields']['struct_reference']??0),
                        $sourceFamily,$activeStructs);
                    if(($nestedLayout['status']??null)
                        !=='struct_local_field_layout_resolved'){
                        return ['status'=>'nested_struct_member_layout_unverified',
                            'member_name'=>(string)($fieldEx['objectNameText']??''),
                            'reason'=>$nestedLayout['status']??'not_resolved'];
                    }
                }
                $fields[]=[
                    'name'=>(string)($fieldEx['objectNameText']??''),
                    'kind'=>$kind,'array_dim'=>$dim,
                    'skip_persistent_archive'=>$skip,
                    'fixed_string_size'=>$stringSize,
                    'nested_layout'=>$nestedLayout,
                    'array_inner_layout'=>$arrayInner,
                    'fixed_array_count'=>$arrayCount,
                ];
                $ref=(int)($field['next_field_reference']??0);
            }
            $current=(int)($native['super_field_reference']??0);
        }
        // A struct whose fields all fail ShouldSerializeValue has a
        // valid zero-byte archive; this is not an unknown file format.
        return [
            'status'=>'struct_local_field_layout_resolved',
            'struct_reference'=>$structReference,
            'fields'=>$fields,
            'source_field_order_verified'=>true,
            'source_imported_struct_hops'=>$importHops,
            'source_non_property_field_skips'=>$nonPropertySkips,
            'source_definition_package_paths'=>$packageChain,
            'runtime_offsets_verified'=>false,
        ];
    }

    /**
     * @param array<string,mixed> $layout
     * @return array<string,mixed>
     */
    public static function readAt(
        string $bytes,int &$position,array $layout,bool $preview,
        CatalogUE1PackageReader $valuePackage,int $depth=0
    ):array {
        if($depth>64){
            return ['status'=>'nested_struct_depth_exceeded'];
        }
        $members=[];$start=$position;
        foreach($layout['fields'] as $field){
            if($field['skip_persistent_archive']){continue;}
            $values=[];
            for($i=0;$i<$field['array_dim'];$i++){
                $kind=$field['kind'];
                if(in_array($kind,['nameproperty','objectproperty','classproperty'],true)){
                    $ref=CatalogUE1ClassicCompactIndexReader::readAt(
                        $bytes,$position);
                    if($ref===null){
                        return ['status'=>'nested_struct_compact_reference_truncated'];
                    }
                    if($kind==='nameproperty'){
                        if($ref<0||$ref>=count($valuePackage->getNames())){
                            throw new RuntimeException(
                                'UE1 struct field FName linker index invalid');
                        }
                    }elseif($ref>count($valuePackage->getExports())
                        ||-$ref>count($valuePackage->getImports())){
                        throw new RuntimeException(
                            'UE1 struct field UObject linker index invalid');
                    }
                    $v=$ref;
                }elseif($kind==='arrayproperty'||$kind==='fixedarrayproperty'){
                    $innerLayout=$field['array_inner_layout']??null;
                    if(!is_array($innerLayout)){
                        return ['status'=>'nested_struct_array_inner_unverified'];
                    }
                    if($kind==='arrayproperty'){
                        // Epic UT99 UArrayProperty::SerializeItem archives
                        // AR_INDEX(n), followed by n Inner->SerializeItem.
                        $elementCount=CatalogUE1ClassicCompactIndexReader::readAt(
                            $bytes,$position);
                    }else{
                        // UFixedArrayProperty::SerializeItem stores exactly
                        // Count elements with NO count prefix.
                        $elementCount=(int)($field['fixed_array_count']??0);
                    }
                    if($elementCount===null||$elementCount<0
                        ||$elementCount>0x7fffffff){
                        return ['status'=>'nested_struct_array_count_invalid'];
                    }
                    // At least one archive byte per supported primitive
                    // element; zero-size struct elements are handled only
                    // when the declared field count is tractable.
                    if($innerLayout['kind']!=='structproperty'
                        &&$elementCount>strlen($bytes)-$position){
                        return ['status'=>'nested_struct_array_truncated'];
                    }
                    if($elementCount>1000000){
                        return ['status'=>'nested_struct_array_count_unverified'];
                    }
                    $innerLayout['array_dim']=$elementCount;
                    $child=self::readAt(
                        $bytes,$position,
                        ['struct_reference'=>null,'fields'=>[$innerLayout]],
                        $preview,$valuePackage,$depth+1);
                    if(($child['status']??null)!=='struct_field_values_decoded'){
                        return ['status'=>'nested_struct_array_values_unverified',
                            'reason'=>$child['status']??'unknown'];
                    }
                    $v=['source_element_count'=>$elementCount,
                        'source_archive_count_prefix'=>$kind==='arrayproperty',
                        'inner_kind'=>$innerLayout['kind'],
                        'items'=>$child['members']??[]];
                }elseif($kind==='structproperty'){
                    $nestedLayout=$field['nested_layout']??null;
                    if(!is_array($nestedLayout)
                        ||($nestedLayout['status']??null)
                            !=='struct_local_field_layout_resolved'){
                        return ['status'=>'nested_struct_member_layout_unverified'];
                    }
                    $nested=self::readAt(
                        $bytes,$position,$nestedLayout,$preview,
                        $valuePackage,$depth+1);
                    if(($nested['status']??null)!=='struct_field_values_decoded'){
                        return ['status'=>'nested_struct_member_value_unverified',
                            'reason'=>$nested['status']??'unknown'];
                    }
                    $v=$nested;
                }elseif($kind==='stringproperty'){
                    // Epic v1.200 Core/Src/UnFile.cpp FArchive::String:
                    // for Count<MaxLength-1 archive exactly one BYTE;
                    // stop at first NUL, otherwise synthesize final NUL
                    // in memory without consuming a terminator byte.
                    $maxLength=(int)($field['fixed_string_size']??0);
                    if($maxLength<1){
                        return ['status'=>'struct_fixed_string_size_invalid'];
                    }
                    $raw='';
                    $terminated=false;
                    for($count=0;$count<$maxLength-1;$count++){
                        if($position>=strlen($bytes)){
                            return ['status'=>'struct_fixed_string_archive_truncated'];
                        }
                        $b=$bytes[$position++];
                        if($b==="\0"){
                            $terminated=true;
                            break;
                        }
                        $raw.=$b;
                    }
                    $v=['raw_ansi_bytes_hex'=>bin2hex($raw),
                        'serialized_text_bytes'=>strlen($raw)+($terminated?1:0),
                        'declared_capacity'=>$maxLength,
                        'nul_archived'=>$terminated,
                        'last_nul_inserted_in_memory'=>!$terminated,
                        'ascii_text'=>preg_match(
                            '/^[\x09\x0A\x0D\x20-\x7E]*$/D',$raw
                        )===1?$raw:null];
                }elseif($kind==='strproperty'){
                    $decoded=CatalogUE1ClassicFStringArchiveReader::decodeAt(
                        $bytes,$position);
                    if($decoded===null){
                        return ['status'=>'nested_struct_fstring_unverified'];
                    }
                    $v=$decoded['value']+[
                        'archive_value_kind'=>$decoded['value_kind']];
                }else{
                    $width=in_array($kind,['byteproperty','boolproperty'],true)?1:4;
                    if($width>strlen($bytes)-$position){
                        return ['status'=>'nested_struct_member_truncated'];
                    }
                    $raw=substr($bytes,$position,$width);
                    $position+=$width;
                    $v=match($kind){
                        'byteproperty'=>ord($raw),
                        'boolproperty'=>ord($raw)!==0,
                        'intproperty'=>self::signed((int)unpack('V',$raw)[1]),
                        'floatproperty'=>(float)unpack('g',$raw)[1],
                    };
                    if($kind==='floatproperty'&&!is_finite($v)){
                        $v=['nonfinite_ieee754_le_hex'=>bin2hex($raw)];
                    }
                }
                if($preview&&count($values)<32){$values[]=$v;}
            }
            if($preview){
                $members[]=['name'=>$field['name'],'kind'=>$field['kind'],
                    'array_dim'=>$field['array_dim'],'values'=>$values,
                    'all_values_previewed'=>$field['array_dim']<=32];
            }
        }
        return [
            'status'=>'struct_field_values_decoded',
            'source_struct_reference'=>$layout['struct_reference'],
            'serialized_bytes'=>$position-$start,
            'members'=>$members,
            'native_struct_memory_verified'=>false,
        ];
    }

    private static function signed(int $raw):int
    {
        return $raw>=0x80000000?$raw-0x100000000:$raw;
    }
}
