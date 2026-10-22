<?php
/**
 * Original May v0.86x / July v0.867 UPrimitive::SerializeHeader.
 * Both Src/Inc/UnPrim.h write inherited UObject::SerializeHeader followed
 * by FBoundingVolume LocalBound. On-disk FBoundingRect/FBoundingBox use
 * FVector Min/Max, BYTE IsValid (not DWORD), FPlane Sphere = 41 bytes.
 * bCheckCollision is not part of the persistent archive.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalUnrealfilePrimitiveHeaderReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $by=[];foreach($stacks as $s)$by[(int)$s['object_index']]=$s;
        $out=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original UPrimitive file');
        try{
            foreach($meta['object_bases'] as $obj){
                if($obj['kind']!=='export'||strcasecmp((string)$obj['class_name'],'Primitive')!==0)continue;
                $idx=(int)$obj['index'];$s=$by[$idx]??[];
                $row=['object_index'=>$idx,'object_name'=>$obj['name'],
                    'native_class'=>'UPrimitive','status'=>'incomplete_native_primitive_header',
                    'runtime_object_constructed'=>false];
                if(($s['status']??null)!=='native_stack_prefix_decoded'
                   ||(int)($s['class_member_bytes_remaining']??0)<CatalogUE1OriginalUnrealfileArchiveMath::BOUND_BYTES){
                    if($strict)throw new RuntimeException('Original UPrimitive native header missing FBoundingVolume bytes');
                    $out[]=$row;continue;
                }
                $native=(int)$s['class_member_bytes_remaining'];
                $offset=(int)$obj['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$offset,SEEK_SET)!==0)throw new RuntimeException('Original UPrimitive native seek failed');
                $bytes=fread($fp,CatalogUE1OriginalUnrealfileArchiveMath::BOUND_BYTES);
                if(!is_string($bytes)||strlen($bytes)!==CatalogUE1OriginalUnrealfileArchiveMath::BOUND_BYTES)
                    throw new RuntimeException('Truncated original UPrimitive FBoundingVolume archive');
                $pos=0;
                $bounds=CatalogUE1OriginalUnrealfileArchiveMath::bound($bytes,$pos);
                $extra=$native-$pos;$data=(int)$obj['file_data_size'];
                if($strict&&($extra!==0||$data!==0))
                    throw new RuntimeException('UPrimitive contains unexpected additional header/data bytes');
                $out[]=array_merge($row,[
                    'status'=>($extra===0&&$data===0)?'native_primitive_header_decoded':'native_primitive_extra_serialized_bytes',
                    'local_bound'=>$bounds,'native_member_bytes'=>$pos,
                    'unconsumed_header_bytes'=>$extra,'unconsumed_data_bytes'=>$data,
                    'source_lineage_requirement'=>'none: both original May/July persist the same 41-byte FBoundingVolume',
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','primitive_headers'=>$out,
            'runtime_object_constructed'=>false];
    }
}
