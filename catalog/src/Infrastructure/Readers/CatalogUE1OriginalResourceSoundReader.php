<?php
/**
 * Unreal 1996 v9/v10 native USound (RES_Sound=12) derived header.
 * Epic v0.84a Src/Inc/UnSound.h and Src/Engine/UnSound.cpp.
 *
 * 32-bit UResource 76 + DataSize INT32 + FamilyName FName WORD16,
 * 2 alignment bytes + SoundID INT32 + reserved Pad[256] = 344 bytes.
 * FamilyName is subject to Epic QueryHeaderReferences and cannot be
 * asserted as a valid global FName without the runtime name manager.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceSoundReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalResourceHeaderReader::read($path);
        $records=[];$fp=fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original USound header');
        try{
            foreach($meta['resource_headers'] as $head){
                if((int)$head['resource_type']!==12)continue;
                $n=(int)$head['serialized_length'];
                $result=[
                    'export_index'=>$head['export_index'],
                    'resource_type'=>12,
                    'status'=>'incomplete_native_sound_header',
                    'runtime_name_fixups_evaluated'=>false,
                ];
                if($n<344){
                    if($strict)throw new RuntimeException('Original USound native header shorter than 344 bytes');
                    $records[]=$result;continue;
                }
                if(fseek($fp,(int)$head['serialized_offset']+76,SEEK_SET)!==0){
                    throw new RuntimeException('Cannot seek original USound native fields');
                }
                $read=fread($fp,268);
                if(!is_string($read)||strlen($read)!==268){
                    throw new RuntimeException('Truncated original USound native header');
                }
                $size=(int)unpack('V',substr($read,0,4))[1];
                $family=(int)unpack('v',substr($read,4,2))[1];
                $driver=(int)unpack('V',substr($read,8,4))[1];
                if($driver>=0x80000000)$driver-=0x100000000;
                $match=$size===(int)$head['file_data_size'];
                if($strict&&!$match){
                    throw new RuntimeException('Original USound QuerySize disagrees with FileDataSize');
                }
                $result=[
                    'export_index'=>$head['export_index'],
                    'resource_type'=>12,
                    'status'=>'native_sound_header_decoded',
                    'source_native_header_bytes'=>344,
                    'extra_header_bytes'=>$n-344,
                    'sound_data_size'=>$size,
                    'family_name_raw_word'=>$family,
                    'sound_driver_id'=>$driver,
                    'reserve_bytes_sha256'=>hash('sha256',substr($read,12,256)),
                    'data_size_matches_source'=>$match,
                    'runtime_name_fixups_evaluated'=>false,
                    'audio_bytes_decoded'=>false,
                ];
                $records[]=$result;
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'sound_headers'=>$records,'runtime_names_resolved'=>false];
    }
}
