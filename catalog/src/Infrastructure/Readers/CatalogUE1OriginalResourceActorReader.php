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
        $meta=CatalogUE1OriginalResourceHeaderReader::read($path);
        $headers=$meta['resource_headers'];
        $importCount=count($meta['imports']);
        $resmapCount=$importCount+count($meta['exports']);
        $propertyTables=[];
        foreach(CatalogUE1OriginalResourceClassPropertyReader::read($path,false)['class_property_tables'] as $table){
            if(($table['status']??null)==='native_UClass_property_records_decoded')
                $propertyTables[(int)$table['export_index']]=$table['properties'];
        }
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open native original actor records');
        try{
            $classFrames=[];
            foreach($headers as $candidate){
                if((int)$candidate['resource_type']!==10
                    ||(int)$candidate['serialized_length']<1272)continue;
                $id=(int)$candidate['export_index'];
                if(!isset($propertyTables[$id]))continue;
                $classFrames[$id]=[
                    'raw'=>self::slice($fp,(int)$candidate['serialized_offset']+124,self::ACTOR_BYTES),
                    'properties'=>$propertyTables[$id],
                    'resource_name'=>$candidate['resource_name']
                ];
            }
            $sourceImportProviders=null;
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
                    $rawActor=self::slice($fp,(int)$h['serialized_offset']+124,self::ACTOR_BYTES);
                    $actor=self::decodeActor($rawActor);
                    $defaultValues=CatalogUE1OriginalActorClassPropertyValueReader::read(
                        $rawActor,$propertyTables[(int)$h['export_index']]??[],$rawActor
                    );
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
                        'default_actor_class_properties'=>$defaultValues,
                        'actor_UClass_properties_decoded'=>true,
                        'actor_UClass_runtime_local_bin_resolved'=>false,
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
                        $rawActor=self::slice($fp,$offset,self::ACTOR_BYTES);
                        $actor=self::decodeActor($rawActor);
                        $actor['record_index']=$j;
                        $actor['class_reference_status']='unresolved';
                        $actor['class_property_values']=null;
                        $ref=(int)$actor['class_reference_raw_word'];
                        if($ref===0){
                            $actor['class_reference_status']='null_UClass';
                        }elseif($ref>$resmapCount){
                            $actor['class_reference_status']='one_based_resmap_ref_out_of_range';
                        }elseif($ref<=$importCount){
                            if($sourceImportProviders===null){
                                $sourceImportProviders=CatalogUE1OriginalResourceClassImportResolver::discover(
                                    $path,$meta['imports']
                                );
                            }
                            $provider=$sourceImportProviders['class_providers'][$ref-1]??null;
                            if(is_array($provider)){
                                $actor['class_property_values']=CatalogUE1OriginalActorClassPropertyValueReader::read(
                                    $rawActor,$provider['properties'],$provider['raw_default_actor']
                                );
                                $actor['class_reference_status']='source_unique_sibling_import_UClass';
                                $actor['class_resource_name']=$provider['resource_name'];
                                $actor['class_provider_filename']=$provider['provider_file_name'];
                                $actor['class_provider_sha256']=$provider['provider_file_sha256'];
                                $actor['class_provider_export_index']=$provider['provider_export_index'];
                            }else{
                                $actor['class_reference_status']='imported_UClass_provider_unavailable';
                                foreach($sourceImportProviders['diagnostics'] as $diagnostic){
                                    if((int)$diagnostic['import_index']===$ref-1){
                                        $actor['class_import_resolution_status']=$diagnostic['status'];
                                        break;
                                    }
                                }
                            }
                        }else{
                            $exportId=$ref-$importCount-1;
                            if(isset($classFrames[$exportId])){
                                $sourceClass=$classFrames[$exportId];
                                $actor['class_property_values']=CatalogUE1OriginalActorClassPropertyValueReader::read(
                                    $rawActor,$sourceClass['properties'],$sourceClass['raw']
                                );
                                $actor['class_reference_status']='source_local_export_UClass';
                                $actor['class_export_index']=$exportId;
                                $actor['class_resource_name']=$sourceClass['resource_name'];
                            }else{
                                $actor['class_reference_status']='resmap_export_not_decoded_UClass';
                            }
                        }
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
            'actor_resources'=>$out,
            'source_import_class_diagnostics'=>$sourceImportProviders['diagnostics']??[],
            'runtime_class_links_resolved'=>false,
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
