<?php
/**
 * UE1 original 1996 FClassProperty native AActor frame values.
 *
 * Epic v0.84a UnRoot.h: PROPBIN_Global=0 (this), Static=1
 * (&Class->DefaultActor), Local=2 (current script frame, NULL otherwise).
 * UnClsPrp.h FClassProperty: offset, ArrayDim, ElementSize, BitMask.
 * UnClsPrp.cpp AddToFrame uses original x86 native sizes.
 *
 * Never resolve on-disk process pointers or infer script execution frames.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalActorClassPropertyValueReader
{
    private const ACTOR_BYTES=1148;
    private const SOURCE_FRAME_LIMIT=944; // sizeof(AActorBase) 304 + CLASS_PROP_EXTRA 640.
    private const TYPE_STRIDE=[
        1=>1,2=>4,3=>4,4=>4,5=>4,6=>4,7=>2,8=>1,9=>16,10=>12
    ];

    /** @param array<int,array<string,mixed>> $descriptors
     *  @return array<string,mixed>
     */
    public static function read(
        string $actor,
        array $descriptors,
        ?string $defaultActor=null
    ):array {
        if(strlen($actor)!==self::ACTOR_BYTES
            ||($defaultActor!==null&&strlen($defaultActor)!==self::ACTOR_BYTES)){
            throw new RuntimeException('Original actor class-frame input must be 1148 bytes');
        }
        if(count($descriptors)>384)throw new RuntimeException('Native class property descriptor count above 384');
        $rows=[];$complete=0;$unresolved=0;
        $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
        foreach($descriptors as $descriptor){
            $type=(int)$descriptor['type'];
            if($type<0||$type>10)throw new RuntimeException('Unknown native 1996 EClassPropertyType');
            $bin=(int)$descriptor['bin'];
            $offset=(int)$descriptor['property_memory_offset'];
            $dim=(int)$descriptor['array_dim'];
            $elemSize=(int)$descriptor['element_size'];
            $row=[
                'index'=>$descriptor['index'],
                'name_index_raw_word'=>$descriptor['name_index_raw_word'],
                'type_name'=>$descriptor['type_name'],
                'type'=>$type,'bin'=>$bin,'property_offset'=>$offset,
                'array_dim'=>$dim,'element_size'=>$elemSize,
                'values'=>[],'runtime_resource_links_resolved'=>false
            ];
            if($bin===2){
                $row['status']='runtime_local_execution_bin_unavailable';
                $rows[]=$row;$unresolved++;continue;
            }
            if($bin!==0&&$bin!==1)throw new RuntimeException('Unknown native EPropertyBin');
            if($bin===1&&$defaultActor===null){
                $row['status']='static_default_actor_provider_unavailable';
                $rows[]=$row;$unresolved++;continue;
            }
            if($type===0){
                $row['status']='no_native_member_for_None_type';
                $rows[]=$row;$complete++;continue;
            }
            if($dim<0||$dim>1000000||$offset<0||$elemSize<0)
                throw new RuntimeException('Bad 1996 class property dimension/offset/element size');
            if($dim===0){
                $row['status']='zero_dim_native_property';
                $rows[]=$row;$complete++;continue;
            }
            $required=self::TYPE_STRIDE[$type];
            if($type!==3&&$elemSize<$required){
                throw new RuntimeException('FClassProperty ElementSize smaller than source native type');
            }
            if($type===3){
                // Consecutive native Boolean properties share one DWORD, with
                // ElementSize=0 on the previous property (AddToFrame).
                if($dim!==1)throw new RuntimeException('1996 native bool arrays are not permitted');
                $mask=(int)$descriptor['boolean_bitmask'];
                if($mask===0)throw new RuntimeException('Missing FClassProperty native boolean bitmask');
            }
            // A zero ElementSize is valid for packed Booleans only.
            $stride=$type===3 ? max(0,$elemSize) : $elemSize;
            $readSize=$type===8 ? $elemSize : $required;
            if($readSize===0)throw new RuntimeException('Zero native string element width');
            $last=$offset+($dim-1)*$stride;
            if($last<0||$last>$offset+1048576||$last>$offset+self::SOURCE_FRAME_LIMIT
                ||$readSize>self::SOURCE_FRAME_LIMIT-$last){
                throw new RuntimeException('FClassProperty frame offset exceeds native AActor source capacity');
            }
            $selected=$bin===0?$actor:$defaultActor;
            $items=[];
            for($i=0;$i<$dim;$i++){
                $cursor=$offset+$i*$stride;
                $bytes=substr($selected,$cursor,$readSize);
                if(strlen($bytes)!==$readSize)throw new RuntimeException('Original property frame truncated');
                switch($type){
                    case 1:$value=ord($bytes[0]);break;
                    case 2:$value=$signed((int)unpack('V',$bytes)[1]);break;
                    case 3:
                        $bits=(int)unpack('V',$bytes)[1];
                        $value=(($bits & $mask)!==0);break;
                    case 4:$value=(float)unpack('g',$bytes)[1];break;
                    case 5:
                        $value=['actor_index_raw'=>$signed((int)unpack('V',$bytes)[1])];
                        break;
                    case 6:
                        $value=['resource_pointer_raw_word'=>(int)unpack('V',$bytes)[1]];
                        break;
                    case 7:
                        $value=['fname_index_raw_word'=>(int)unpack('v',$bytes)[1]];
                        break;
                    case 8:
                        $nul=strpos($bytes,"\0");
                        $payload=$nul===false?$bytes:substr($bytes,0,$nul);
                        $value=['ansi_bytes_hex'=>strtoupper(bin2hex($payload)),
                            'native_element_size'=>$elemSize,'nul_terminated'=>$nul!==false];
                        break;
                    case 9:
                        $value=['xyz'=>array_values(unpack('g3',substr($bytes,0,12))),
                            'w_or_flags_raw_word'=>(int)unpack('V',substr($bytes,12,4))[1]];
                        break;
                    case 10:
                        $words=array_values(unpack('V3',$bytes));
                        $value=array_map($signed,$words);break;
                    default:throw new RuntimeException('Unsupported class property type');
                }
                $items[]=$value;
            }
            $row['values']=$items;
            $row['status']='source_native_class_property_decoded';
            $rows[]=$row;$complete++;
        }
        return [
            'status'=>$unresolved===0?'native_class_frame_values_decoded':
                'class_frame_values_partially_unresolved',
            'property_values'=>$rows,
            'decoded_property_count'=>$complete,
            'unresolved_property_count'=>$unresolved,
            'global_bin_actor_bytes'=>self::SOURCE_FRAME_LIMIT,
            'static_bin_uses_class_default_actor'=>true,
            'runtime_local_script_bin_loaded'=>false,
            'runtime_resource_refs_resolved'=>false
        ];
    }
}
