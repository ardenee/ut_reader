<?php
/**
 * Unreal v0.867 simple native UDatabase::SerializeHeader and
 * IMPLEMENT_DB_CLASS(...).StandardSerializeData.
 *
 * Source: Src/Inc/UnObjBas.h:280-293,643-685; UnObj.h declarations.
 * UTextBuffer::SerializeHeader adds Pos and Top (2xINT).
 * Data serialization uses Num, NOT Max. BYTE/char raw, WORD/FName 2,
 * INT/UObject* 4, FLOAT 4, FVector X/Y/Z 12 via archive operator.
 * Class-defined SerializeBin fields, external class loading and runtime
 * object/name interning are not claimed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileDatabaseDataReader
{
    private const CLASSES=[
        'buffer'=>['Buffer',1,'bytes'],
        'textbuffer'=>['TextBuffer',1,'text_bytes'],
        'words'=>['Words',2,'words'],
        'ints'=>['Ints',4,'ints'],
        'array'=>['Array',4,'object_references'],
        'enumdef'=>['EnumDef',2,'name_references'],
        'floats'=>['Floats',4,'floats'],
        'vectors'=>['Vectors',12,'vectors'],
        'dependencies'=>['Dependencies',8,'dependencies'],
        'reachspecs'=>['ReachSpecs',36,'reach_specs'],
    ];

    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $stackBy=[];
        foreach($stacks as $s)$stackBy[(int)$s['object_index']]=$s;
        $objectCount=(int)$meta['summary']['object_count'];
        $names=$meta['names'];$nameCount=count($names);
        $results=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original UDatabase file');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export')continue;
                $key=strtolower((string)$o['class_name']);
                if(!isset(self::CLASSES[$key]))continue;
                [$kind,$stride,$encoding]=self::CLASSES[$key];
                $s=$stackBy[(int)$o['index']]??null;
                $row=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'U'.$kind,'status'=>'incomplete_native_database_header',
                    'runtime_references_resolved'=>false];
                $required=$key==='textbuffer'?16:8;
                if(($s['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($s['class_member_bytes_remaining']??0)<$required){
                    if($strict)throw new RuntimeException('Original '.$kind.' header lacks Num/Max or Pos/Top');
                    $results[]=$row;continue;
                }
                $remaining=(int)$s['class_member_bytes_remaining'];
                $hdrOff=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$hdrOff,SEEK_SET)!==0)throw new RuntimeException('Cannot seek original database Num/Max');
                $hdr=fread($fp,$required);
                if(!is_string($hdr)||strlen($hdr)!==$required)throw new RuntimeException('Truncated UDatabase native header');
                $words=unpack('V'.($required/4),$hdr);
                $num=(int)$words[1];$max=(int)$words[2];
                if($num>$max||$num>10000000||$max>10000000)
                    throw new RuntimeException('Invalid original UDatabase active/capacity pair');
                if($num>intdiv(PHP_INT_MAX,$stride)||$num*$stride!==(int)$o['file_data_size'])
                    throw new RuntimeException('Original UDatabase Num records disagree with serialized data length');
                $extraHeader=$remaining-$required;
                if($strict&&$extraHeader!==0)
                    throw new RuntimeException('Original primitive database has unexplained class-specific header members');
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek primitive original UDatabase data');
                $byteCount=$num*$stride;$buf='';
                while(strlen($buf)<$byteCount){
                    $piece=fread($fp,min(65536,$byteCount-strlen($buf)));
                    if(!is_string($piece)||$piece==='')throw new RuntimeException('Truncated primitive UDatabase stream');
                    $buf.=$piece;
                }
                $items=[];$signed=static fn(int $x):int=>$x>=0x80000000?$x-0x100000000:$x;
                if($stride>1){
                    for($i=0;$i<$num;$i++){
                        $v=substr($buf,$i*$stride,$stride);
                        switch($encoding){
                            case 'words':$items[]=(int)unpack('v',$v)[1];break;
                            case 'ints':$items[]=$signed((int)unpack('V',$v)[1]);break;
                            case 'floats':$items[]=(float)unpack('g',$v)[1];break;
                            case 'vectors':$items[]=array_values(unpack('g3',$v));break;
                            case 'reach_specs':
                                // UnPath.h FReachSpec::operator<<: distance,
                                // Goal/Start/End, 4 float extents, flags.
                                $distance=(float)unpack('g',substr($v,0,4))[1];
                                $refs=array_values(unpack('V3',substr($v,4,12)));
                                foreach($refs as $ref){
                                    if($ref>$objectCount)throw new RuntimeException('FReachSpec actor reference exceeds ResMap');
                                }
                                $extents=array_values(unpack('g4',substr($v,16,16)));
                                $items[]=['distance'=>$distance,
                                    'goal_ref'=>$refs[0], 'start_ref'=>$refs[1],
                                    'end_ref'=>$refs[2],
                                    'collision_radius'=>$extents[0],
                                    'collision_height'=>$extents[1],
                                    'jump_z'=>$extents[2], 'drop_z'=>$extents[3],
                                    'reach_flags'=>(int)unpack('V',substr($v,32,4))[1],
                                    'runtime_actors_resolved'=>false];break;
                            case 'dependencies':
                                $words=unpack('Vref/Vcrc',$v);
                                $ref=(int)$words['ref'];
                                if($ref>$objectCount)throw new RuntimeException('FDependency UClass reference exceeds ResMap');
                                $items[]=['class_ref'=>$ref,
                                    'class_object_index'=>$ref===0?null:$ref-1,
                                    'script_text_crc'=>(int)$words['crc']];break;
                            case 'object_references':
                                $ref=(int)unpack('V',$v)[1];
                                if($ref>$objectCount)throw new RuntimeException('Original database UObject* ref exceeds ResMap');
                                $items[]=['raw_one_based_ref'=>$ref,
                                    'object_index'=>$ref===0?null:$ref-1];break;
                            case 'name_references':
                                $ref=(int)unpack('v',$v)[1];
                                if($ref>$nameCount)throw new RuntimeException('Original database FName ref exceeds NameMap');
                                $items[]=['raw_one_based_ref'=>$ref,
                                    'name'=>$ref===0?null:(string)$names[$ref-1]['name']];break;
                        }
                    }
                }
                $results[]=[
                    'object_index'=>(int)$o['index'],
                    'object_name'=>$o['name'],'native_class'=>'U'.$kind,
                    'status'=>$extraHeader===0?'native_database_records_decoded':'native_database_with_unconsumed_header',
                    'active_count'=>$num,'capacity'=>$max,'serialized_record_bytes'=>$stride,
                    'serialized_data_bytes'=>$byteCount,'encoding'=>$encoding,
                    'text_edit_position'=>$key==='textbuffer'?$signed((int)$words[3]):null,
                    'text_top_position'=>$key==='textbuffer'?$signed((int)$words[4]):null,
                    'opaque_byte_stream_hex'=>$stride===1?strtoupper(bin2hex($buf)):null,
                    'record_values'=>$items,'unconsumed_header_bytes'=>$extraHeader,
                    'runtime_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','database_records'=>$results,
            'class_specific_primitive_data_decoded'=>true,
            'runtime_references_resolved'=>false,
            'other_class_serializers_decoded'=>false];
    }
}
