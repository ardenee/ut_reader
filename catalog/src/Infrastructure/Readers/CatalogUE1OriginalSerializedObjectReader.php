<?php
/**
 * Original UE1 physical serializer record boundaries (read-only).
 *
 * v9/v10: FGlobalResourceManager::AddFile (UnRes.cpp) stores a native,
 * per-type UResource header and a separate raw Data payload.
 * v22/v23: UObjectBase exports store independent SerializeData and
 * SerializeHeader byte ranges (v0.867 Src/Engine/UnObj.cpp:1447-1457).
 *
 * A digest of file bytes is not a decoded UObject or native pointer.
 * Per-class member serialization, Bin defaults and runtime deserialization
 * remain explicitly UNKNOWN, never represented as verified objects.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalSerializedObjectReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, string $family): array
    {
        if ($family === 'early_unreal_resource') {
            $meta=CatalogUE1OriginalResourceHeaderReader::read($path);
            $records=[];
            foreach ($meta['resource_headers'] as $h) {
                $records[]=self::record($path,
                    (int)$h['export_index'],(string)$h['resource_name'],(string)$h['resource_type'],
                    (int)$h['serialized_offset'],(int)$h['serialized_length'],
                    (int)$h['file_data_offset'],(int)$h['file_data_size'],
                    'UResource native type-specific header; raw data');
            }
        } elseif ($family === 'early_unrealfile') {
            $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
            $records=[];
            foreach ($meta['object_bases'] as $o) {
                if ($o['kind'] !== 'export') {continue;}
                $records[]=self::record($path,
                    (int)$o['index'],(string)$o['name'],(string)$o['class_name'],
                    (int)$o['file_header_offset'],(int)$o['file_header_size'],
                    (int)$o['file_data_offset'],(int)$o['file_data_size'],
                    'UObject::SerializeHeader and SerializeData; class-defined binaries');
            }
        } else {
            throw new RuntimeException('Unsupported original UE1 payload family: '.$family);
        }
        return [
            'source_family'=>$family,
            'object_ranges'=>$records,
            'range_records_decoded'=>count($records),
            'class_property_schema_decoded'=>false,
            'runtime_objects_created'=>false,
            'status'=>'physical_object_ranges_verified_class_schema_unresolved',
        ];
    }

    /** @return array<string,mixed> */
    private static function record(
        string $path,int $index,string $name,string $class,int $hOff,int $hSize,
        int $dOff,int $dSize,string $contract
    ):array {
        return [
            'index'=>$index,'name'=>$name,'source_type'=>$class,
            'serializer_contract'=>$contract,
            'header'=>self::section($path,$hOff,$hSize),
            'data'=>self::section($path,$dOff,$dSize),
            'member_properties_decoded'=>false,
        ];
    }

    /** @return array{offset:int,size:int,sha256:string|null} */
    private static function section(string $path,int $offset,int $length):array
    {
        $size=(int)filesize($path);
        if($offset<0||$length<0||$offset>$size||$length>$size-$offset){
            throw new RuntimeException('Original UE1 object serialized range exceeds physical file');
        }
        if($length===0){return ['offset'=>$offset,'size'=>0,'sha256'=>null];}
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open original UE1 data range');}
        try{
            if(fseek($fp,$offset)!==0){throw new RuntimeException('Cannot seek original object data');}
            $hash=hash_init('sha256');$remaining=$length;
            while($remaining>0){
                $chunk=fread($fp,min($remaining,65536));
                if(!is_string($chunk)||$chunk===''){throw new RuntimeException('Truncated original UE1 object data');}
                hash_update($hash,$chunk);$remaining-=strlen($chunk);
            }
            return ['offset'=>$offset,'size'=>$length,'sha256'=>hash_final($hash)];
        }finally{fclose($fp);}
    }
}
