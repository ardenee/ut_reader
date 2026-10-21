<?php
/**
 * Original 1996 UDatabase-derived resource header extension.
 *
 * Epic v0.84a Src/Inc/UnResTyp.h:483-501:
 * UDatabase extends native UResource (76 bytes on 32-bit MSVC) by
 * INT Num and INT Max; v0.84a Src/Engine/UnRes.cpp:466-489 provides
 * QuerySize, QueryMinSize, InitHeader and byte swapping.
 *
 * Source membership: explicit RESOURCE_DB_CLASS registrations in
 * Src/Inc/UnRes.h, UnClass.h and UnActLst.h; enum values in UnRes.h.
 * UTexture and USound do NOT inherit UDatabase.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalResourceDatabaseReader
{
    /** Native type IDs, not modern UObject class names. */
    private const DATABASE_TYPES = [
        1=>'UBuffer',3=>'UArray',4=>'UTextBuffer',6=>'UFont',
        7=>'UPalette',10=>'UClass',11=>'UActorList',16=>'UVectors',
        17=>'UBspNodes',18=>'UBspSurfs',20=>'UPolys',29=>'UVertPool',
        33=>'UBounds',34=>'UTerrain',35=>'UEnum'
    ];

    /** @return array<string,mixed> */
    public static function read(string $path, bool $rejectIncomplete = false): array
    {
        $meta=CatalogUE1OriginalResourceHeaderReader::read($path);
        $headers=[];
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open original UDatabase header');}
        try {
            foreach($meta['resource_headers'] as $header){
                $type=(int)$header['resource_type'];
                $result=[
                    'export_index'=>(int)$header['export_index'],
                    'resource_type'=>$type,
                    'native_class'=>self::DATABASE_TYPES[$type]??null,
                    'is_database'=>isset(self::DATABASE_TYPES[$type]),
                    'database_count'=>null,
                    'database_capacity'=>null,
                    'status'=>'not_a_database_resource_type',
                ];
                if(!isset(self::DATABASE_TYPES[$type])){$headers[]=$result;continue;}
                if((int)$header['serialized_length']<84){
                    if($rejectIncomplete){
                        throw new RuntimeException('Native UDatabase resource header is shorter than the 84-byte base+Num+Max');
                    }
                    $result['status']='incomplete_native_database_header';
                    $headers[]=$result;continue;
                }
                $offset=(int)$header['serialized_offset']+76;
                if(fseek($fp,$offset,SEEK_SET)!==0){
                    throw new RuntimeException('Cannot seek 1996 UDatabase Num/Max');
                }
                $bytes=fread($fp,8);
                if(!is_string($bytes)||strlen($bytes)!==8){
                    throw new RuntimeException('Truncated 1996 UDatabase Num/Max');
                }
                $ints=unpack('Vnum/Vmax',$bytes);
                $num=(int)$ints['num'];$max=(int)$ints['max'];
                if($num>$max || $num>0x7fffffff || $max>0x7fffffff){
                    throw new RuntimeException('Native 1996 UDatabase Num/Max is invalid');
                }
                $result['database_count']=$num;
                $result['database_capacity']=$max;
                $result['serialized_field_offset']=$offset;
                $result['serialized_field_bytes']=8;
                $result['remaining_class_header_bytes']=(int)$header['serialized_length']-84;
                $result['status']='native_database_num_max_decoded';
                if($type===7){
                    // UPalette::QuerySize/QueryMinSize use 256 FColor entries
                    // regardless of Num/Max; 4 bytes per FColor.
                    $result['palette_fixed_data_bytes']=1024;
                    $result['palette_data_size_matches_source']=(int)$header['file_data_size']===1024;
                    if($rejectIncomplete&&!$result['palette_data_size_matches_source']){
                        throw new RuntimeException('1996 UPalette QueryMinSize is not 1024 bytes');
                    }
                }
                $headers[]=$result;
            }
        }finally{fclose($fp);}
        return [
            'source_family'=>'early_unreal_resource',
            'database_headers'=>$headers,
            'database_members_decoded'=>true,
            'class_specific_additional_members_decoded'=>false,
            'runtime_references_resolved'=>false,
        ];
    }
}
