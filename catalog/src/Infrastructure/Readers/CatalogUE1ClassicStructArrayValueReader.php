<?php
/**
 * UT99 v400 Core/Src/UnProp.cpp::UStructProperty::SerializeItem delegates
 * to UStruct::SerializeBin. The latter walks TFieldIterator<UProperty>,
 * applying ShouldSerializeValue and each property's ArrayDim.
 *
 * Only fully resolved LOCAL, non-inherited UStruct definitions are decoded;
 * native UObject memory offsets and inherited or nested structs are not
 * guessed. Supported leaf serializers match actual UProperty types.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicStructArrayValueReader
{
    /** @return array<string,mixed> */
    public static function resolve(
        string $path,CatalogUE1PackageReader $pkg,int $structReference
    ):array {
        if($structReference<=0){
            return ['status'=>'struct_reference_not_local'];
        }
        $ex=$pkg->getExports()[$structReference-1]??null;
        if(!is_array($ex)){
            return ['status'=>'struct_reference_not_exported'];
        }
        $fields=[];$visited=[];
        $current=$structReference;
        // TFieldIterator<UProperty> iterates this struct's Children,
        // then GetInheritanceSuper()->Children, and so on.
        while($current!==0){
            if($current<0){
                return ['status'=>'struct_inherited_import_unverified'];
            }
            if(isset($visited[$current])){
                return ['status'=>'struct_superclass_cycle'];
            }
            $visited[$current]=true;
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
                    return ['status'=>'struct_child_is_not_supported_property'];
                }
                $kind=(string)($field['property_kind']??'');
                if(!in_array($kind,[
                    'byteproperty','boolproperty','intproperty',
                    'floatproperty','strproperty'
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
            'runtime_offsets_verified'=>false,
        ];
    }

    /**
     * @param array<string,mixed> $layout
     * @return array<string,mixed>
     */
    public static function readAt(
        string $bytes,int &$position,array $layout,bool $preview
    ):array {
        $members=[];$start=$position;
        foreach($layout['fields'] as $field){
            if($field['skip_persistent_archive']){continue;}
            $values=[];
            for($i=0;$i<$field['array_dim'];$i++){
                $kind=$field['kind'];
                if($kind==='strproperty'){
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
