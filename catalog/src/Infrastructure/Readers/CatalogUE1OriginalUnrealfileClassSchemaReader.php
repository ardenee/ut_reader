<?php
/**
 * Unreal v0.867 serialized UClass schema and FProperty descriptor reader.
 *
 * Native:
 * UnClass.h::UClass::SerializeHeader after UDatabase::SerializeHeader:
 * ParentClass; ScriptText, Script, StackTree, Dependencies; AllPropertyFlags;
 * PackageName; Bins[3]; ResFullHeaderSize, ResThisHeaderSize,
 * ResRecordSize, ClassFlags, GUID[4].
 * UnObjBas.h::UDatabase::SerializeHeader: UObject frame, INT Num/Max.
 * IMPLEMENT_DB_CLASS(UClass): SerializeData emits Num FProperty records.
 * UnProp.h::operator<<(FProperty): 28-byte base, four extra bytes for
 * Byte, Bool, Object or EnumDef types. FNames and references are 1-based.
 *
 * Does not create a runtime UClass or resolve cross-file parent classes.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileClassSchemaReader
{
    private const TYPES = [
        0=>'None',1=>'Byte',2=>'Int',3=>'Bool',4=>'Float',5=>'Object',
        6=>'Name',7=>'String',8=>'Vector',9=>'Rotation',10=>'EnumDef'
    ];

    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict = false): array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $map=[];
        foreach(CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'] as $s){
            $map[$s['object_index']]=$s;
        }
        $names=$meta['names'];$count=(int)$meta['summary']['object_count'];
        $schemas=[];
        foreach($meta['object_bases'] as $o){
            if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Class')!==0){continue;}
            $s=$map[$o['index']]??[];
            if(($s['status']??null)!=='native_stack_prefix_decoded'
                ||(int)($s['class_member_bytes_remaining']??0)<78){
                if($strict){throw new RuntimeException('UClass export header cannot contain native UDatabase/UClass fields');}
                $schemas[]=['object_index'=>$o['index'],'class_name'=>$o['name'],
                    'status'=>'incomplete_native_UClass_header',
                    'native_member_descriptors_decoded'=>false];
                continue;
            }
            $headerOffset=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
            $headerLength=(int)$s['class_member_bytes_remaining'];
            $header=self::slice($path,$headerOffset,$headerLength);
            $offset=0;
            $u32=static function()use($header,&$offset):int{
                if($offset+4>strlen($header)){throw new RuntimeException('Truncated UClass header field');}
                $n=(int)unpack('V',substr($header,$offset,4))[1];$offset+=4;return $n;
            };
            $u16=static function()use($header,&$offset):int{
                if($offset+2>strlen($header)){throw new RuntimeException('Truncated UClass FName');}
                $n=(int)unpack('v',substr($header,$offset,2))[1];$offset+=2;return $n;
            };
            $num=$u32();$max=$u32();
            if($num>$max||$num>0x7fffffff||$max>0x7fffffff){
                throw new RuntimeException('Invalid UClass UDatabase Num/Max');
            }
            $parent=$u32();
            $scriptText=$u32();$script=$u32();$stack=$u32();$deps=$u32();
            $flags=$u32();$packageName=$u16();
            $bins=[$u32(),$u32(),$u32()];
            $fullSize=$u32();$thisSize=$u32();$recordSize=$u32();$classFlags=$u32();
            $guid=[$u32(),$u32(),$u32(),$u32()];
            foreach([$parent,$scriptText,$script,$stack,$deps,...$bins] as $ref){
                if($ref>$count){throw new RuntimeException('UClass native object ref exceeds ResMap');}
            }
            $package=self::name($packageName,$names);
            $dataLen=(int)$o['file_data_size'];
            if($num>intdiv($dataLen,28)||$num>100000){
                throw new RuntimeException('UClass property count exceeds possible serialized FProperty records');
            }
            $bytes=self::slice($path,(int)$o['file_data_offset'],$dataLen);
            $pos=0;$properties=[];
            for($i=0;$i<$num;$i++){
                if($pos+28>strlen($bytes)){throw new RuntimeException('Truncated native FProperty descriptor');}
                $start=$pos;
                $nameIndex=(int)unpack('v',substr($bytes,$pos,2))[1];$pos+=2;
                $categoryIndex=(int)unpack('v',substr($bytes,$pos,2))[1];$pos+=2;
                $info=unpack('Vtype/Voffset/Vflags/VarrayDim/VelementSize/Vbin',substr($bytes,$pos,24));
                $pos+=24;$type=(int)$info['type'];
                if(!isset(self::TYPES[$type])){throw new RuntimeException('Unknown 1997 FProperty type '.$type);}
                $dimension=(int)$info['arrayDim'];$bin=(int)$info['bin'];
                if($dimension>1000000||$bin>2){throw new RuntimeException('Invalid native FProperty dimension/bin');}
                $extra=null;
                if(in_array($type,[1,3,5,10],true)){
                    if($pos+4>strlen($bytes)){throw new RuntimeException('Truncated FProperty conditional union field');}
                    $extra=(int)unpack('V',substr($bytes,$pos,4))[1];$pos+=4;
                    if(in_array($type,[1,5,10],true)&&$extra>$count){
                        throw new RuntimeException('FProperty native enum/class ref exceeds ResMap');
                    }
                }
                $properties[]=[
                    'descriptor_index'=>$i,'name_index'=>$nameIndex,'name'=>self::name($nameIndex,$names),
                    'category_index'=>$categoryIndex,'category'=>self::name($categoryIndex,$names),
                    'property_type'=>$type,'property_type_name'=>self::TYPES[$type],
                    'memory_offset'=>(int)$info['offset'],
                    'flags'=>(int)$info['flags'],'array_dim'=>$dimension,
                    'element_size'=>(int)$info['elementSize'],'bin'=>$bin,
                    'bool_bitmask'=>$type===3?$extra:null,
                    'enum_or_class_ref'=>in_array($type,[1,5,10],true)?$extra:null,
                    'serialized_offset'=>(int)$o['file_data_offset']+$start,
                    'serialized_bytes'=>$pos-$start,
                ];
            }
            $headerExtra=$headerLength-$offset;$dataExtra=$dataLen-$pos;
            if($strict && ($headerExtra!==0||$dataExtra!==0)){
                throw new RuntimeException('UClass header/data has unconsumed source serializer fields');
            }
            $schemas[]=[
                'object_index'=>$o['index'],'class_name'=>$o['name'],
                'status'=>$headerExtra===0&&$dataExtra===0?'native_UClass_schema_decoded':'native_UClass_schema_has_extra',
                'property_count'=>$num,'property_capacity'=>$max,
                'parent_class_ref'=>$parent,'script_text_ref'=>$scriptText,
                'script_ref'=>$script,'stack_tree_ref'=>$stack,'dependencies_ref'=>$deps,
                'all_property_flags'=>$flags,'package_name'=>$package,'bin_refs'=>$bins,
                'full_header_size'=>$fullSize,'this_header_size'=>$thisSize,
                'record_size'=>$recordSize,'class_flags'=>$classFlags,'guid_words'=>$guid,
                'properties'=>$properties,'unconsumed_header_bytes'=>$headerExtra,
                'unconsumed_data_bytes'=>$dataExtra,
                'native_member_descriptors_decoded'=>true,
                'runtime_class_linked'=>false,
            ];
        }
        return ['source_format'=>'early_unrealfile',
            'class_schemas'=>$schemas,
            'class_descriptors_decoded'=>array_sum(array_map(
                static fn(array $s):int=>count($s['properties']??[]),$schemas
            )),
            'runtime_class_linked'=>false];
    }

    private static function name(int $index,array $names):?string
    {
        if($index===0){return null;}
        if(!isset($names[$index-1])){throw new RuntimeException('Invalid UClass/FProperty one-based name index');}
        return (string)$names[$index-1]['name'];
    }

    private static function slice(string $path,int $offset,int $length):string
    {
        if($length<0||$length>67108864){throw new RuntimeException('Invalid original native class segment length');}
        $handle=fopen($path,'rb');
        if($handle===false){throw new RuntimeException('Cannot open Unrealfile UClass segment');}
        try{
            if(fseek($handle,$offset,SEEK_SET)!==0){throw new RuntimeException('Cannot seek UClass segment');}
            $result='';
            while(strlen($result)<$length){
                $bytes=fread($handle,min(65536,$length-strlen($result)));
                if(!is_string($bytes)||$bytes===''){throw new RuntimeException('Truncated original UClass segment');}
                $result.=$bytes;
            }
            return $result;
        }finally{fclose($handle);}
    }
}
