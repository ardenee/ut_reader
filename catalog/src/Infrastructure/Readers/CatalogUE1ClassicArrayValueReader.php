<?php
/**
 * UT99 v400 UArrayProperty::SerializeItem: AR_INDEX(n), then each
 * Inner->SerializeItem. Source-scoped leaf serializers and strictly
 * resolved local UStruct::SerializeBin field-order decoding only.
 *
 * Does not emulate allocation, object creation or arbitrary nested struct
 * serialization. Source-verified nested FString byte streams retain their
 * original ANSI/Unicode byte identity; runtime character conversion remains
 * unverified. Unsupported inner classes stay explicitly unverified.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicArrayValueReader
{
    /**
     * @param array<string,mixed> $tag
     * @return array<string,mixed>
     */
    public static function inspect(
        string $valuePath,CatalogUE1PackageReader $valuePkg,
        string $declPath,CatalogUE1PackageReader $declPkg,
        array $tag,int $innerRef,?int $fixedCount=null,
        ?string $sourceFamily=null
    ): array {
        if($innerRef<=0){
            return ['status'=>'inner_property_not_local','source_type_verified'=>false];
        }
        $export=$declPkg->getExports()[$innerRef-1]??null;
        if(!is_array($export)){
            return ['status'=>'inner_property_export_missing','source_type_verified'=>false];
        }
        $pref=CatalogUE1ClassicTaggedPropertyReader::inspect(
            $declPath,$declPkg,$innerRef-1,$sourceFamily);
        if(($pref['status']??'')!=='tag_envelope_decoded'){
            return ['status'=>'inner_property_prefix_unverified','source_type_verified'=>false];
        }
        $native=CatalogUE1ClassicNativeObjectReader::inspect(
            $declPath,$declPkg,$innerRef-1,$pref,$sourceFamily);
        if(($native['status']??'')!=='native_property_record_complete'){
            return ['status'=>'inner_property_native_unverified','source_type_verified'=>false];
        }
        $kind=(string)($native['property_kind']??'');
        $width=match($kind){
            'byteproperty','boolproperty'=>1,
            'intproperty','floatproperty'=>4,
            'nameproperty','objectproperty','classproperty'=>-1,
            'strproperty'=>-2,
            'structproperty'=>-3,
            default=>0,
        };
        if($width===0){
            return ['status'=>'inner_serializer_not_yet_implemented',
                'inner_property_kind'=>$kind,'source_type_verified'=>true];
        }
        $structLayout=null;
        if($kind==='structproperty'){
            $structLayout=CatalogUE1ClassicStructArrayValueReader::resolve(
                $declPath,$declPkg,
                (int)($native['subclass_fields']['struct_reference']??0),$sourceFamily);
            if($structLayout['status']!=='struct_local_field_layout_resolved'){
                return ['status'=>'inner_struct_layout_unverified',
                    'reason'=>$structLayout['status'],
                    'source_type_verified'=>true];
            }
        }
        $size=(int)($tag['serialized_size']??-1);
        $start=(int)($tag['value_offset']??-1);
        $len=filesize($valuePath);
        if($size<($fixedCount===null?1:0)||$start<0||$len===false||$start>$len-$size){
            throw new RuntimeException('UE1 array tag outside source archive bounds');
        }
        $fp=fopen($valuePath,'rb');
        if($fp===false){throw new RuntimeException('Cannot read UE1 array value');}
        try {
            if(fseek($fp,$start)!==0){throw new RuntimeException('Cannot seek UE1 array value');}
            $data=fread($fp,$size);
            if($data===false||strlen($data)!==$size){
                throw new RuntimeException('Truncated UE1 array value');
            }
        }finally{fclose($fp);}
        if(is_string($tag['value_sha256']??null)
           &&hash('sha256',$data)!==$tag['value_sha256']){
            throw new RuntimeException('UE1 array data changed since tag parsing');
        }
        $pos=0;
        if($fixedCount!==null){
            // T99 UFixedArrayProperty::SerializeItem loops exactly Count
            // elements, without an AR_INDEX(n) prefix.
            $count=$fixedCount;$bytes=0;
            if($count<1||$count>0x7fffffff){
                return self::unverified($kind,'invalid_declared_fixed_count');
            }
        }else{
            $head=ord($data[$pos++]);
            $negative=($head&128)!==0;$count=$head&63;
            $more=($head&64)!==0;$shift=6;$bytes=1;
            while($more){
                if(++$bytes>5||$pos>=strlen($data)){
                    return self::unverified($kind,'compact_count_invalid');
                }
                $ch=ord($data[$pos++]);
                $count|=($ch&127)<<$shift;
                $more=($ch&128)!==0;$shift+=7;
            }
            if($negative||$count>0x7fffffff){
                return self::unverified($kind,'negative_or_overflow_count');
            }
        }
        // The source loop consumes exactly n inner serialized items.
        // Avoid huge allocations and mismatch between n and Tag.Size.
        if($width>0&&($count>intdiv($size-$pos,$width)
           ||$count*$width!==$size-$pos)){
            return self::unverified($kind,'count_and_inner_serialized_bytes_disagree');
        }
        $values=[];
        for($i=0;$i<$count;$i++){
            if($width===-3){
                $decoded=CatalogUE1ClassicStructArrayValueReader::readAt(
                    $data,$pos,$structLayout,count($values)<32,$valuePkg);
                if($decoded['status']!=='struct_field_values_decoded'){
                    return self::unverified($kind,$decoded['status']);
                }
                $value=$decoded;
            }elseif($width===-2){
                // UT99 UStrProperty::SerializeItem delegates to FString's
                // signed-count archive. Each element has its OWN count
                // and may independently choose ANSI or Unicode.
                $decoded=CatalogUE1ClassicFStringArchiveReader::decodeAt(
                    $data,$pos);
                if($decoded===null){
                    return self::unverified(
                        $kind,'invalid_nested_source_counted_fstring');
                }
                $value=$decoded['value']+
                    ['archive_value_kind'=>$decoded['value_kind']];
            }elseif($width<0){
                // UNameProperty and UObjectProperty use the owning
                // ULinkerLoad archive's compact name/object indices.
                // The original tag file supplies their map bounds;
                // the UProperty declaration may live in another package.
                $index=CatalogUE1ClassicCompactIndexReader::readAt($data,$pos);
                if($index===null){
                    return self::unverified($kind,'invalid_compact_inner_reference');
                }
                if($kind==='nameproperty'){
                    if($index<0||$index>=count($valuePkg->getNames())){
                        throw new RuntimeException('UE1 array inner FName linker index invalid');
                    }
                }elseif($index>count($valuePkg->getExports())
                    ||-$index>count($valuePkg->getImports())){
                    throw new RuntimeException('UE1 array inner UObject linker index invalid');
                }
                $value=$index;
            }else{
                $raw=substr($data,$pos,$width);$pos+=$width;
                $value=match($kind){
                    'byteproperty','boolproperty'=>ord($raw),
                    'intproperty'=>self::signed((int)unpack('V',$raw)[1]),
                    'floatproperty'=>(float)unpack('g',$raw)[1],
                };
                // UBoolProperty::SerializeItem treats any nonzero BYTE as
                // true, not just the canonical value 1.
                if($kind==='boolproperty'){$value=$value!==0;}
                if($kind==='floatproperty'&&!is_finite($value)){
                    $value=['nonfinite_ieee754_le_hex'=>bin2hex($raw)];
                }
            }
            if(count($values)<32){$values[]=$value;}
        }
        if($pos!==$size){
            return self::unverified($kind,'trailing_source_archive_bytes');
        }
        return [
            'status'=>'array_inner_values_decoded',
            'inner_property_kind'=>$kind,
            'source_type_verified'=>true,
            'element_count'=>$count,
            'array_serialization_family'=>$fixedCount===null?
                'dynamic_counted':'fixed_declared_count',
            'element_width_bytes'=>$width>0?$width:null,
            'count_compact_bytes'=>$bytes,
            'serialized_bytes'=>$size,
            'serialized_sha256'=>hash('sha256',$data),
            'preview_first_32'=>$values,
            'all_values_previewed'=>$count<=32,
            'runtime_array_allocation_verified'=>false,
        ];
    }

    /** @return array<string,mixed> */
    private static function unverified(string $kind,string $why):array
    {
        return ['status'=>'array_inner_data_unverified',
            'inner_property_kind'=>$kind,
            'reason'=>$why,'source_type_verified'=>true];
    }

    private static function signed(int $value):int
    {
        return $value>=0x80000000?$value-0x100000000:$value;
    }
}
