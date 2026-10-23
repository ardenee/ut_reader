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
        string $path,CatalogUE1PackageReader $pkg,int $structReference
    ):array {
        if($structReference===0){
            return ['status'=>'struct_reference_none'];
        }
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
                $path,$pkg,$current-1);
            $native=CatalogUE1ClassicNativeObjectReader::inspect(
                $path,$pkg,$current-1,$meta);
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
                    $path,$pkg,$ref-1);
                $field=CatalogUE1ClassicNativeObjectReader::inspect(
                    $path,$pkg,$ref-1,$tag);
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
                    'floatproperty','strproperty',
                    'nameproperty','objectproperty','classproperty'
                ],true)){
                    return ['status'=>'struct_member_serializer_unverified',
                        'member_kind'=>$kind];
                }
                $dim=(int)($field['array_dim']??0);
                if($dim<1){
                    return ['status'=>'struct_member_arraydim_invalid'];
                }
                $flags=(int)($field['effective_property_flags']??0);
                $fields[]=[
                    'name'=>(string)($fieldEx['objectNameText']??''),
                    'kind'=>$kind,'array_dim'=>$dim,
                    'skip_persistent_archive'=>($flags&0x3000)!==0,
                ];
                $ref=(int)($field['next_field_reference']??0);
            }
            $current=(int)($native['super_field_reference']??0);
        }
        if(!array_filter($fields,
            static fn(array $f):bool=>!$f['skip_persistent_archive'])){
            return ['status'=>'struct_no_serialized_fields_unverified'];
        }
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
        CatalogUE1PackageReader $valuePackage
    ):array {
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
