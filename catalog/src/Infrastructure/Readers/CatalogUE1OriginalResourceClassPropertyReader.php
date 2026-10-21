<?php
/**
 * Source-native 1996 v9/v10 UClass FClassProperty descriptors.
 * Epic v0.84a Src/Inc/UnClsPrp.h 32-byte FClassProperty (x86 /Zp4);
 * UnClass.cpp QueryMinSize()=Num*32; QuerySize()=Max*32.
 * The *embedded AActor DefaultActor header* and raw member pointer
 * fixups remain unimplemented and must not be represented as decoded.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceClassPropertyReader
{
    private const TYPES=[
        0=>'None',1=>'Byte',2=>'Integer',3=>'Boolean',4=>'Real',
        5=>'Actor',6=>'Resource',7=>'Name',8=>'String',
        9=>'Vector',10=>'Rotation'
    ];
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open source UClass property records');
        try{
            foreach($db as $i=>$row){
                if((int)$row['resource_type']!==10)continue;
                $h=$headers[$i];
                $partial=['export_index'=>(int)$h['export_index'],
                    'native_class'=>'UClass','status'=>'incomplete_UClass_database_header',
                    'default_actor_header_decoded'=>false,
                    'runtime_class_references_resolved'=>false];
                if(($row['status']??null)!=='native_database_num_max_decoded'){
                    if($strict)throw new RuntimeException('UClass does not contain UDatabase Num/Max');
                    $out[]=$partial;continue;
                }
                $num=(int)$row['database_count'];$max=(int)$row['database_capacity'];
                if($max>384)throw new RuntimeException('UClass Max exceeds MAX_CLASS_PROPERTIES 384');
                $len=(int)$h['file_data_size'];
                if($num>intdiv(PHP_INT_MAX,32)||$max>intdiv(PHP_INT_MAX,32)
                    ||!in_array($len,[$num*32,$max*32],true))
                    throw new RuntimeException('UClass data bytes disagree with native FClassProperty count');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek source FClassProperty table');
                $props=[];
                for($j=0;$j<$num;$j++){
                    $r=fread($fp,32);
                    if(!is_string($r)||strlen($r)!==32)
                        throw new RuntimeException('Truncated 1996 FClassProperty');
                    $names=unpack('vname/vcategory',substr($r,0,4));
                    $words=unpack('Vtype/Voffset/Vflags/Varray/Vsize/Vbin/Vextra',
                        substr($r,4,28));
                    $type=(int)$words['type'];
                    if(!isset(self::TYPES[$type]))
                        throw new RuntimeException('Invalid EClassPropertyType '.$type);
                    $dim=(int)$words['array'];$size=(int)$words['size'];
                    if($dim>1000000||$size>1048576)
                        throw new RuntimeException('Invalid original class property dimension or size');
                    $extra=(int)$words['extra'];
                    $props[]=[
                        'index'=>$j,'name_index_raw_word'=>(int)$names['name'],
                        'category_index_raw_word'=>(int)$names['category'],
                        'type'=>$type,'type_name'=>self::TYPES[$type],
                        'property_memory_offset'=>self::signed((int)$words['offset']),
                        'flags'=>(int)$words['flags'],
                        'array_dim'=>$dim,'element_size'=>$size,
                        'bin'=>self::signed((int)$words['bin']),
                        'extra_raw_word'=>$extra,
                        'enum_reference_raw_word'=>in_array($type,[1,2],true)?$extra:null,
                        'actor_class_reference_raw_word'=>$type===5?$extra:null,
                        'resource_type_word'=>$type===6?$extra:null,
                        'boolean_bitmask'=>$type===3?$extra:null,
                        'runtime_references_resolved'=>false,
                    ];
                }
                $out[]=[
                    'export_index'=>(int)$h['export_index'],'native_class'=>'UClass',
                    'status'=>'native_UClass_property_records_decoded',
                    'property_count'=>$num,'property_capacity'=>$max,
                    'property_record_bytes'=>32,'properties'=>$props,
                    'stored_record_count'=>intdiv($len,32),
                    'stored_mode'=>$len===$num*32?'QueryMinSize':'QuerySize',
                    'default_actor_header_decoded'=>false,
                    'class_member_header_decoded'=>false,
                    'runtime_class_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','class_property_tables'=>$out,
            'runtime_references_resolved'=>false,'default_actor_decoded'=>false];
    }
    private static function signed(int $v):int{return $v>=0x80000000?$v-0x100000000:$v;}
}
