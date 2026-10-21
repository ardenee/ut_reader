<?php
/**
 * Native v0.867 UClass::SerializeBin(PROPBIN_PerObject) binary values.
 *
 * Data descriptors come exclusively from UClass::SerializeData FProperty
 * records; without those descriptors no object property interpretation is
 * attempted. Source: Src/Engine/UnClass.cpp:725-750,
 * Src/Engine/UnProp.cpp:23-74; Src/Inc/UnProp.h.
 * Parent-class properties are followed through local ResMap; unavailable
 * external parents stay unresolved rather than fabricated.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileBinaryPropertyReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict = false): array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $schemas=CatalogUE1OriginalUnrealfileClassSchemaReader::read($path,false)['class_schemas'];
        $byName=[];$byIndex=[];
        foreach($schemas as $schema){
            if(($schema['status']??'')!=='native_UClass_schema_decoded')continue;
            $byIndex[(int)$schema['object_index']]=$schema;
            $key=strtolower((string)$schema['class_name']);
            if(!isset($byName[$key]))$byName[$key]=[];
            $byName[$key][]=$schema;
        }
        $stacks=[];
        foreach(CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'] as $s)
            $stacks[(int)$s['object_index']]=$s;
        $names=$meta['names'];$objects=$meta['object_bases'];$numObjects=count($objects);
        $results=[];
        foreach($objects as $o){
            if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Class')===0)continue;
            $id=(int)$o['index'];$key=strtolower((string)$o['class_name']);
            $entry=['object_index'=>$id,'object_name'=>$o['name'],
                'class_name'=>$o['class_name'],'property_values'=>[],
                'runtime_object_constructed'=>false];
            $matches=$byName[$key]??[];
            if(count($matches)!==1){
                $entry['status']='source_UClass_schema_unavailable_or_ambiguous';
                $results[]=$entry;continue;
            }
            $stack=$stacks[$id]??null;
            if(($stack['status']??null)!=='native_stack_prefix_decoded'){
                $entry['status']='incomplete_native_stack_prefix';
                $results[]=$entry;continue;
            }
            $all=[];$visited=[];$class=$matches[0];$missingParent=false;
            while(true){
                $classId=(int)$class['object_index'];
                if(isset($visited[$classId]))throw new RuntimeException('Cyclic original UClass ancestry');
                $visited[$classId]=true;
                foreach($class['properties'] as $prop)if((int)$prop['bin']===1)$all[]=$prop;
                $parentRef=(int)$class['parent_class_ref'];
                if($parentRef===0)break;
                if($parentRef>$numObjects)throw new RuntimeException('UClass parent reference exceeds ResMap');
                $index=$parentRef-1;
                if(!isset($byIndex[$index])){
                    $missingParent=true;break;
                }
                $class=$byIndex[$index];
            }
            if($missingParent){
                $entry['status']='parent_UClass_schema_unavailable';
                $results[]=$entry;continue;
            }
            $hOff=(int)$o['file_header_offset'];
            $hSize=(int)$o['file_header_size'];
            $pos=(int)$stack['serialized_prefix_bytes'];
            $bytes=self::slice($path,$hOff,$hSize);
            $read=static function(int $n)use($bytes,&$pos):string{
                if($n<0||$n>strlen($bytes)-$pos)throw new RuntimeException('UClass binary property exceeds serialized object header');
                $data=substr($bytes,$pos,$n);$pos+=$n;return $data;
            };
            $values=[];
            foreach($all as $prop){
                $type=(int)$prop['property_type'];$flags=(int)$prop['flags'];
                if(($flags&0x3000)!==0)continue; // CPF_Transient | CPF_Intrinsic
                $dim=(int)$prop['array_dim'];
                if($dim<0||$dim>1000000)throw new RuntimeException('Invalid property ArrayDim');
                $items=[];
                for($j=0;$j<$dim;$j++){
                    $val=null;
                    switch($type){
                        case 1:$val=ord($read(1));break;
                        case 2:
                            $v=(int)unpack('V',$read(4))[1];
                            $val=$v>=0x80000000?$v-0x100000000:$v;break;
                        case 3:
                            if((int)$prop['bool_bitmask']===1)$val=(int)unpack('V',$read(4))[1]!==0;
                            else $val=['not_serialized_nontrivial_bool_bitmask'=>$prop['bool_bitmask']];
                            break;
                        case 4:$val=unpack('g',$read(4))[1];break;
                        case 5:
                            $ref=(int)unpack('V',$read(4))[1];
                            if($ref>$numObjects)throw new RuntimeException('Original binary UObject ref exceeds ResMap');
                            $val=['object_ref'=>$ref,'object_index'=>$ref===0?null:$ref-1];break;
                        case 6:
                            $n=(int)unpack('v',$read(2))[1];
                            if($n>count($names))throw new RuntimeException('Original FName ref exceeds NameMap');
                            $val=['name_ref'=>$n,'name'=>$n===0?null:$names[$n-1]['name']];break;
                        case 8:
                            $val=array_values(unpack('g3',$read(12)));break;
                        case 9:
                            $val=array_values(unpack('V3',$read(12)));break;
                        case 10:break; // Enum definition stores no instance data.
                        case 7:
                            // v0.867 Ar.String uses source class ElementSize;
                            // non-fixed/unknown string layout is not guessed.
                            $entry['status']='binary_string_serializer_requires_length_contract';
                            $results[]=$entry;continue 4;
                        default:throw new RuntimeException('Unsupported native FProperty type '.$type);
                    }
                    $items[]=$val;
                }
                $values[]=['name'=>$prop['name'],'type'=>$prop['property_type_name'],
                    'array_dim'=>$dim,'values'=>$items,
                    'property_memory_offset'=>$prop['memory_offset']];
            }
            $left=$hSize-$pos;
            if($strict&&$left!==0)throw new RuntimeException('Original binary object has additional native class header data');
            $entry['property_values']=$values;
            $entry['class_schema_object_index']=$matches[0]['object_index'];
            $entry['consumed_class_member_bytes']=$pos-(int)$stack['serialized_prefix_bytes'];
            $entry['remaining_class_member_bytes']=$left;
            $entry['status']=$left===0?'native_binary_properties_decoded':'native_binary_properties_with_extension';
            $results[]=$entry;
        }
        return ['source_family'=>'early_unrealfile',
            'objects'=>$results,'runtime_objects_created'=>false,
            'full_object_data_decoded'=>false];
    }
    private static function slice(string $path,int $offset,int $length):string
    {
        if($length<0||$length>67108864)throw new RuntimeException('Original binary object header too large');
        $fp=fopen($path,'rb');if($fp===false)throw new RuntimeException('Cannot open native object header');
        try{
            if(fseek($fp,$offset)!==0)throw new RuntimeException('Cannot seek native binary object header');
            $buf='';
            while(strlen($buf)<$length){
                $part=fread($fp,min(65536,$length-strlen($buf)));
                if($part===false||$part==='')throw new RuntimeException('Truncated binary object header');
                $buf.=$part;
            }
            return $buf;
        }finally{fclose($fp);}
    }
}
