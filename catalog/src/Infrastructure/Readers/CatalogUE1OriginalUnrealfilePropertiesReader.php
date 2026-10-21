<?php
/**
 * Native v0.867 original Unrealfile UProperties::SerializeData.
 * UnProp.h UProperties::SerializeHeader writes UDatabase Num/Max then
 * Class(UObject ref) and Bin(EPropertyBin). UnClass.cpp::SerializeBin
 * traverses class->parent descriptors in current-class-first order.
 * FProperty::SerializeProperty uses serialized TYPE values, not raw
 * in-memory property offsets. External parent classes must be resolved
 * by the engine before parity can be asserted; this parser refuses
 * to invent missing class metadata.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfilePropertiesReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stackBy=[];foreach(CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'] as $stack)
            $stackBy[(int)$stack['object_index']]=$stack;
        $byClass=[];foreach(CatalogUE1OriginalUnrealfileClassSchemaReader::read($path,false)['class_schemas'] as $schema)
            if(($schema['status']??null)==='native_UClass_schema_decoded')
                $byClass[(int)$schema['object_index']]=$schema;
        $names=$meta['names'];$objectCount=count($meta['object_bases']);$results=[];
        $fp=@fopen($path,'rb');if(!$fp)throw new RuntimeException('Cannot open native UProperties');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Properties')!==0)continue;
                $id=(int)$o['index'];$stack=$stackBy[$id]??[];
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($stack['class_member_bytes_remaining']??0)<16){
                    if($strict)throw new RuntimeException('UProperties native Class/Bin/Num/Max header truncated');
                    $results[]=['object_index'=>$id,'status'=>'incomplete_native_properties_header'];
                    continue;
                }
                $offset=(int)$o['file_header_offset']+(int)$stack['serialized_prefix_bytes'];
                $headers=self::span($fp,$offset,16);
                [$num,$max,$classRef,$bin]=array_values(unpack('V4',$headers));
                if($num>$max||$num>16000000||$max>16000000)
                    throw new RuntimeException('UProperties native Num/Max invalid');
                if($classRef>$objectCount)
                    throw new RuntimeException('UProperties class ref exceeds native ResMap');
                if($bin>2)throw new RuntimeException('Invalid UProperties EPropertyBin');
                $result=[
                    'object_index'=>$id,'object_name'=>$o['name'],
                    'native_class'=>'UProperties','property_memory_bytes'=>$num,
                    'property_capacity_bytes'=>$max,'class_reference_raw'=>$classRef,
                    'property_bin'=>$bin,'property_values'=>[],
                    'runtime_class_preload_evaluated'=>false,
                    'runtime_class_references_resolved'=>false
                ];
                if($classRef===0||!isset($byClass[$classRef-1])){
                    $result['status']='class_schema_unavailable_or_external';
                    $results[]=$result;continue;
                }
                $properties=[];$visited=[];$source=$byClass[$classRef-1];$missing=false;
                while(true){
                    $sid=(int)$source['object_index'];
                    if(isset($visited[$sid]))throw new RuntimeException('Cycle in UProperties native class ancestry');
                    $visited[$sid]=true;
                    foreach($source['properties'] as $prop)
                        if((int)$prop['bin']===$bin)$properties[]=$prop;
                    $parent=(int)$source['parent_class_ref'];
                    if($parent===0)break;
                    if($parent>$objectCount)
                        throw new RuntimeException('Invalid UProperties parent-class native ResMap ref');
                    if(!isset($byClass[$parent-1])){$missing=true;break;}
                    $source=$byClass[$parent-1];
                }
                if($missing){
                    $result['status']='parent_class_schema_unavailable';
                    $results[]=$result;continue;
                }
                $len=(int)$o['file_data_size'];
                if($len<0||$len>16000000)throw new RuntimeException('Native property data exceeds bounded stream');
                $bytes=self::span($fp,(int)$o['file_data_offset'],$len);
                $pos=0;$read=static function(int $n)use($bytes,&$pos):string{
                    if($n<0||$n>strlen($bytes)-$pos)
                        throw new RuntimeException('Native UProperties value exceeds serialized data bytes');
                    $raw=substr($bytes,$pos,$n);$pos+=$n;return $raw;
                };
                $values=[];
                foreach($properties as $p){
                    if(((int)$p['flags']&0x3000)!==0)continue; // transient/intrinsic
                    $type=(int)$p['property_type'];$dim=(int)$p['array_dim'];
                    if($dim<0||$dim>1000000)throw new RuntimeException('Invalid property ArrayDim');
                    $items=[];$start=$pos;
                    for($j=0;$j<$dim;$j++){
                        $value=null;
                        switch($type){
                            case 1:$value=ord($read(1));break;
                            case 2:$value=self::signed((int)unpack('V',$read(4))[1]);break;
                            case 3:
                                $mask=(int)$p['bool_bitmask'];
                                $value=$mask===1?((int)unpack('V',$read(4))[1]!==0)
                                    :['bitmask_not_serialized'=>$mask];break;
                            case 4:$value=(float)unpack('g',$read(4))[1];break;
                            case 5:
                                $ref=(int)unpack('V',$read(4))[1];
                                if($ref>$objectCount)throw new RuntimeException('UProperties UObject reference invalid');
                                $value=['raw_one_based_ref'=>$ref,
                                    'object_index'=>$ref===0?null:$ref-1];break;
                            case 6:
                                $ref=(int)unpack('v',$read(2))[1];
                                if($ref>count($names))throw new RuntimeException('UProperties FName ref invalid');
                                $value=['raw_one_based_ref'=>$ref,
                                    'name'=>$ref===0?null:(string)$names[$ref-1]['name']];break;
                            case 7:
                                $maxLen=(int)$p['element_size'];
                                if($maxLen<=0||$maxLen>65536)
                                    throw new RuntimeException('Unbounded UProperties string ElementSize');
                                $string='';
                                for($k=0;$k<$maxLen-1;$k++){
                                    $ch=$read(1);
                                    if($ch==="\0")break;
                                    $string.=$ch;
                                }
                                $value=['ansi_bytes_hex'=>strtoupper(bin2hex($string)),
                                    'source_max_length'=>$maxLen];break;
                            case 8:$value=array_values(unpack('g3',$read(12)));break;
                            case 9:
                                $value=array_map(self::signed(...),
                                    array_values(unpack('V3',$read(12))));break;
                            case 10:break; // Enum definition stores no instance bytes
                            default:throw new RuntimeException('Unknown UProperties class-defined type '.$type);
                        }
                        $items[]=$value;
                    }
                    $values[]=['name'=>$p['name'],'property_type_name'=>$p['property_type_name'],
                        'array_dim'=>$dim,'values'=>$items,
                        'serialized_byte_count'=>$pos-$start,
                        'original_member_offset'=>$p['memory_offset']];
                }
                $extra=$len-$pos;
                if($strict&&$extra!==0)
                    throw new RuntimeException('UProperties native serialized stream contains unconsumed bytes');
                $result['status']=$extra===0?'native_UClass_bin_properties_decoded':
                    'native_UClass_bin_properties_trailing_bytes';
                $result['class_schema_object_index']=$classRef-1;
                $result['property_values']=$values;
                $result['serialized_bytes_consumed']=$pos;
                $result['serialized_bytes_remaining']=$extra;
                $results[]=$result;
            }
        }finally{fclose($fp);}
        return ['source_format'=>'early_unrealfile','properties_objects'=>$results,
            'runtime_class_preloads_executed'=>false];
    }
    private static function signed(int $v):int{return $v>=0x80000000?$v-0x100000000:$v;}
    private static function span($fp,int $offset,int $length):string
    {
        if($length<0||$length>16000000||fseek($fp,$offset,SEEK_SET)!==0)
            throw new RuntimeException('Invalid UProperties data segment');
        $bytes='';
        while(strlen($bytes)<$length){
            $s=fread($fp,min(65536,$length-strlen($bytes)));
            if(!is_string($s)||$s==='')throw new RuntimeException('Truncated UProperties stream');
            $bytes.=$s;
        }
        return $bytes;
    }
}
