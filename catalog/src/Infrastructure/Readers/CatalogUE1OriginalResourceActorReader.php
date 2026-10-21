<?php
/**
 * Original UE1 v0.84a 32-bit native UClass default actor and UActorList.
 * Source: Root.h AActorBase and AActor (UnRoot.h private record), UnClass.h,
 * UnActLst.h, 1996 x86 MSVC source-extracted actor-layout proof.
 * Header UClass=1272B (AActor starts at +124), UActorList=116B.
 * AActor=1148B, AActorBase=304B. This reader decodes core source fields,
 * not all actor member fields or inherited runtime class properties.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceActorReader
{
    private const ACTOR_BYTES=1148;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open native original actor records');
        try{
            foreach($db as $i=>$row){
                $type=(int)$row['resource_type'];
                if($type!==10 && $type!==11)continue;
                $h=$headers[$i];$isClass=$type===10;
                $required=$isClass?1272:116;
                $brief=['export_index'=>(int)$h['export_index'],
                    'native_class'=>$isClass?'UClass':'UActorList',
                    'status'=>'incomplete_original_actor_container_header',
                    'runtime_class_links_resolved'=>false,
                    'all_native_actor_members_decoded'=>false];
                if(($row['status']??null)!=='native_database_num_max_decoded'
                    ||(int)$h['serialized_length']<$required){
                    if($strict)throw new RuntimeException('Original actor container header shorter than source ABI');
                    $out[]=$brief;continue;
                }
                $num=(int)$row['database_count'];$max=(int)$row['database_capacity'];
                $dataSize=(int)$h['file_data_size'];
                if($isClass){
                    // The FClassProperty table is decoded by its dedicated reader.
                    // This class contributes its default embedded native actor.
                    $expected=[self::safeProduct($num,32),self::safeProduct($max,32)];
                    if(!in_array($dataSize,$expected,true)||$max>384)
                        throw new RuntimeException('UClass FClassProperty size exceeds source record contract');
                    $memberOffset=(int)$h['serialized_offset']+84;
                    $head=self::slice($fp,$memberOffset,40);
                    $w=array_values(unpack('V10',$head));
                    $actor=self::decodeActor(self::slice($fp,(int)$h['serialized_offset']+124,self::ACTOR_BYTES));
                    $out[]=[
                        'export_index'=>(int)$h['export_index'],'native_class'=>'UClass',
                        'status'=>'native_UClass_default_actor_core_decoded',
                        'native_header_bytes'=>$required,
                        'class_reference_raw_words'=>[
                            'parent_class'=>$w[0],
                            'script_text'=>$w[1],
                            'script'=>$w[2]
                        ],
                        'intrinsic_raw_int'=>self::signed($w[3]),
                        'actor_function_pointer_raw_word'=>$w[4],
                        'script_text_crc'=>$w[5],
                        'parent_text_crc'=>$w[6],
                        'reserved_words'=>array_slice($w,7,3),
                        'default_actor'=>$actor,
                        'property_record_count'=>$num,'property_record_capacity'=>$max,
                        'runtime_class_links_resolved'=>false,
                        'all_native_actor_members_decoded'=>false,
                        'native_property_descriptors_decoded_elsewhere'=>true,
                    ];
                }else{
                    $bytesExpected=[self::safeProduct($num,self::ACTOR_BYTES),
                        self::safeProduct($max,self::ACTOR_BYTES)];
                    if(!in_array($dataSize,$bytesExpected,true)||$max>500000)
                        throw new RuntimeException('UActorList record Num/Max disagrees with sizeof(AActor)');
                    $w=array_values(unpack('V8',
                        self::slice($fp,(int)$h['serialized_offset']+84,32)));
                    $actors=[];
                    for($j=0;$j<$num;$j++){
                        $offset=(int)$h['file_data_offset']+$j*self::ACTOR_BYTES;
                        $actor=self::decodeActor(self::slice($fp,$offset,self::ACTOR_BYTES));
                        $actor['record_index']=$j;
                        $actors[]=$actor;
                    }
                    $out[]=[
                        'export_index'=>(int)$h['export_index'],'native_class'=>'UActorList',
                        'status'=>'native_actor_list_core_records_decoded',
                        'native_header_bytes'=>$required,
                        'lock_type_raw_word'=>$w[0],
                        'transaction_enabled_raw_int'=>self::signed($w[1]),
                        'runtime_compact_actor_lists_raw_words'=>array_slice($w,2,6),
                        'record_size_bytes'=>self::ACTOR_BYTES,
                        'record_count'=>$num,'record_capacity'=>$max,
                        'actors'=>$actors,
                        'runtime_class_links_resolved'=>false,
                        'all_native_actor_members_decoded'=>false
                    ];
                }
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'actor_resources'=>$out,'runtime_class_links_resolved'=>false,
            'all_native_actor_members_decoded'=>false];
    }
    /** @return array<string,mixed> */
    private static function decodeActor(string $bytes):array
    {
        $class=(int)unpack('V',substr($bytes,0,4))[1];
        $vec=static fn(int $off):array=>[
            'xyz'=>array_values(unpack('g3',substr($bytes,$off,12))),
            'w_or_flags_raw_word'=>(int)unpack('V',substr($bytes,$off+12,4))[1]
        ];
        return [
            ...CatalogUE1OriginalNativeActorValueReader::read($bytes),
            'class_reference_raw_word'=>$class,
            'location'=>$vec(4),'velocity'=>$vec(20),
            'draw_rotation'=>self::signedTriplet($bytes,36),
            'view_rotation'=>self::signedTriplet($bytes,48),
            'name_index_raw_word'=>(int)unpack('v',substr($bytes,144,2))[1],
            'event_index_raw_word'=>(int)unpack('v',substr($bytes,146,2))[1],
            'actor_index'=>self::signed((int)unpack('V',substr($bytes,152,4))[1]),
            'native_property_pad_bytes'=>643,
            'native_private_segment_bytes'=>200,
            'raw_record_sha256'=>hash('sha256',$bytes),
            'runtime_class_reference_resolved'=>false,
            'all_native_member_values_decoded'=>false,
        ];
    }
    private static function safeProduct(int $count,int $stride):int
    {
        if($count>intdiv(PHP_INT_MAX,$stride))
            throw new RuntimeException('Original actor record multiplication overflow');
        return $count*$stride;
    }
    private static function signed(int $v):int{return $v>=0x80000000?$v-0x100000000:$v;}
    /** @return array<int,int> */
    private static function signedTriplet(string $data,int $offset):array
    {return array_map(self::signed(...),array_values(unpack('V3',substr($data,$offset,12))));}
    private static function slice($fp,int $offset,int $len):string
    {
        if($offset<0||$len<0||$len>160000000)
            throw new RuntimeException('Invalid native actor span');
        if(fseek($fp,$offset,SEEK_SET)!==0)
            throw new RuntimeException('Cannot seek native actor span');
        $buf='';
        while(strlen($buf)<$len){
            $part=fread($fp,min(65536,$len-strlen($buf)));
            if(!is_string($part)||$part==='')
                throw new RuntimeException('Truncated native actor field');
            $buf.=$part;
        }
        return $buf;
    }
}
