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
    public static function read(string $path, bool $strict = false, array $declaredDependencyProviders = [], ?string $sourceLineage = null): array
    {
        CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
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
            $all=[];$visited=[];$class=$matches[0];$missingParent=false;$externalSources=[];
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
                    if($declaredDependencyProviders!==[]){
                        $resolution=CatalogUE1OriginalUnrealfileExternalSchemaResolver::resolve(
                            $path,$index,$declaredDependencyProviders
                        );
                        if(($resolution['status']??null)==='resolved'){
                            foreach($resolution['property_descriptors'] as $prop){
                                if((int)$prop['bin']===1)$all[]=$prop;
                            }
                            $externalSources=$resolution['sources'];
                            break;
                        }
                        $entry['external_schema_status']=$resolution['status'];
                    }
                    $missingParent=true;break;
                }
                $class=$byIndex[$index];
            }
            if($missingParent){
                $entry['status']='parent_UClass_schema_unavailable';
                $results[]=$entry;continue;
            }
            if(CatalogUE1OriginalUnrealfilePropertySerializationGate::unresolved($all,$sourceLineage)){
                if($strict)throw new RuntimeException('Original May/July 1997 CPF_Intrinsic file-serialization behavior ambiguous: source lineage required');
                $entry['status']='source_1997_property_lineage_required';
                $entry['source_lineage']=null;
                $entry['ambiguity']='May 1997 serializes flag 0x1000; July 1997 skips it (both file version 23)';
                $results[]=$entry;continue;
            }
            $hOff=(int)$o['file_header_offset'];
            $hSize=(int)$o['file_header_size'];
            $pos=(int)$stack['serialized_prefix_bytes'];
            $bytes=self::slice($path,$hOff,$hSize);
            $read=static function(int $n)use($bytes,&$pos):string{
                if($n<0||$n>strlen($bytes)-$pos)throw new RuntimeException('UClass binary property exceeds serialized object header at relative byte '.$pos.' of '.strlen($bytes).' (request '.$n.')');
                $data=substr($bytes,$pos,$n);$pos+=$n;return $data;
            };
            $values=[];
            foreach($all as $prop){
                $type=(int)$prop['property_type'];$flags=(int)$prop['flags'];
                if(!CatalogUE1OriginalUnrealfilePropertySerializationGate::serialized($flags,$sourceLineage))continue;
                $dim=(int)$prop['array_dim'];
                if($dim<0||$dim>1000000)throw new RuntimeException('Invalid property ArrayDim');
                $items=[];$rawBoolWords=[];
                for($j=0;$j<$dim;$j++){
                    $val=null;
                    switch($type){
                        case 1:$val=ord($read(1));break;
                        case 2:
                            $v=(int)unpack('V',$read(4))[1];
                            $val=$v>=0x80000000?$v-0x100000000:$v;break;
                        case 3:
                            if((int)$prop['bool_bitmask']===1){
                                // Epic May/July UnProp.cpp archives the entire
                                // DWORD. The property's truth is only bit 0;
                                // a nonzero word with bit 0 clear is false.
                                $word=(int)unpack('V',$read(4))[1];
                                $rawBoolWords[$j]=$word;
                                $val=($word&1)!==0;
                            }else{
                                // Packed Boolean members do not archive a
                                // second DWORD; their memory cannot be
                                // resolved without the containing word.
                                $val=['not_serialized_nontrivial_bool_bitmask'=>$prop['bool_bitmask']];
                            }
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
                            // May 1997 FRotation::operator<< writes WORD
                            // Pitch/Yaw/Roll then zero-extends to INT.
                            // July 1997 writes three signed INTs. Neither
                            // fork is identifiable from file version alone.
                            if($sourceLineage===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY){
                                $val=array_values(unpack('v3',$read(6)));
                            }else{
                                $angles=array_values(unpack('V3',$read(12)));
                                $val=array_map(static fn(int $a):int=>
                                    $a>=0x80000000?$a-0x100000000:$a,$angles);
                            }
                            break;
                        case 10:break; // Enum definition stores no instance data.
                        case 7:
                            // v0.867 Src/Inc/UnArc.h FArchive::String reads
                            // up to ElementSize-1 bytes, stopping at NUL,
                            // and writes a forced terminator in memory.
                            $max=(int)$prop['element_size'];
                            if($max<=0||$max>65536){
                                $entry['status']='binary_string_requires_valid_ElementSize';
                                $results[]=$entry;continue 4;
                            }
                            $raw='';
                            for($k=0;$k<$max-1;$k++){
                                $ch=$read(1);
                                if($ch==="\0")break;
                                $raw.=$ch;
                            }
                            // Serialized source ANSI bytes are preserved;
                            // no locale/UTF-8 identity is manufactured.
                            $val=['ansi_bytes_hex'=>strtoupper(bin2hex($raw)),
                                'source_max_length'=>$max];break;
                        default:throw new RuntimeException('Unsupported native FProperty type '.$type);
                    }
                    $items[]=$val;
                }
                $row=['name'=>$prop['name'],'type'=>$prop['property_type_name'],
                    'array_dim'=>$dim,'values'=>$items,
                    'property_memory_offset'=>$prop['memory_offset']];
                if($type===3)$row['serialized_boolean_dwords']=$rawBoolWords;
                $values[]=$row;
            }
            $left=$hSize-$pos;
            if($strict&&$left!==0)throw new RuntimeException('Original binary object has additional native class header data');
            $entry['property_values']=$values;
            $entry['external_property_schema_sources']=$externalSources;
            $entry['source_lineage']=$sourceLineage;
            $entry['runtime_external_classes_loaded']=false;
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
