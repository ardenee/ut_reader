<?php
/**
 * Original 1996 native UDatabase byte/word/reference-array resource records.
 *
 * Epic v0.84a Src/Engine/UnRes.cpp UDatabase::QueryMinSize=Num*RecordSize.
 * Src/Engine/UnRes.cpp UTextBuffer, UBuffer, UArray, UEnum::Register.
 * Src/Inc/UnRes.h::UTextBuffer::Pos adds one INT after Num,Max.
 * UArray::QueryDataReferences and UEnum::QueryDataReferences walk members
 * through native runtime callbacks; words are preserved as serialized
 * indices, NOT declared resolved pointers or global names.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceSimpleDatabaseReader
{
    /** Native EResourceType => serialized primitive record bytes. */
    private const RECORDS=[
        1=>['UBuffer',1,'byte_stream'],
        3=>['UArray',4,'resource_reference_words'],
        4=>['UTextBuffer',1,'source_text_bytes'],
        35=>['UEnum',2,'name_index_words'],
    ];
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict);
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];
        $fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original resource data');
        try{
            foreach($meta['database_headers'] as $i=>$db){
                $type=(int)$db['resource_type'];
                if(!isset(self::RECORDS[$type]))continue;
                [$class,$bytes,$semantic]=self::RECORDS[$type];
                $h=$headers[$i];
                $outRow=[
                    'export_index'=>$h['export_index'],
                    'native_class'=>$class,'record_kind'=>$semantic,
                    'status'=>'incomplete_native_database_header',
                    'runtime_references_resolved'=>false,
                ];
                if(($db['status']??'')!=='native_database_num_max_decoded'){
                    if($strict)throw new RuntimeException('Incomplete source database header for '.$class);
                    $out[]=$outRow;continue;
                }
                $num=(int)$db['database_count'];
                $length=(int)$h['file_data_size'];
                if($num>intdiv(PHP_INT_MAX,$bytes)||$num*$bytes!==$length){
                    throw new RuntimeException($class.' native QueryMinSize does not match serialized data length');
                }
                $headerExtra=(int)$h['serialized_length']-84;
                $cursor=null;
                if($type===4){
                    if($headerExtra<4){
                        if($strict)throw new RuntimeException('Native UTextBuffer header lacks INT Pos');
                        $out[]=$outRow;continue;
                    }
                    if(fseek($fp,(int)$h['serialized_offset']+84,SEEK_SET)!==0)
                        throw new RuntimeException('Cannot seek UTextBuffer cursor');
                    $readPos=fread($fp,4);
                    if(!is_string($readPos)||strlen($readPos)!==4)
                        throw new RuntimeException('Truncated UTextBuffer cursor');
                    $cursor=(int)unpack('V',$readPos)[1];
                }
                $off=(int)$h['file_data_offset'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('Cannot seek original database data');
                $raw='';
                while(strlen($raw)<$length){
                    $part=fread($fp,min(65536,$length-strlen($raw)));
                    if(!is_string($part)||$part==='')throw new RuntimeException('Truncated original database data');
                    $raw.=$part;
                }
                $words=[];
                if($type===3 || $type===35){
                    for($j=0;$j<$num;$j++){
                        $words[]=(int)unpack($type===3?'V':'v',substr($raw,$j*$bytes,$bytes))[1];
                    }
                }
                $outRow=[
                    'export_index'=>$h['export_index'],'native_class'=>$class,
                    'status'=>'native_database_records_decoded',
                    'record_kind'=>$semantic,'record_count'=>$num,
                    'record_size'=>$bytes,'payload_bytes'=>$length,
                    'data_offset'=>$off,'data_sha256'=>hash('sha256',$raw),
                    'text_editor_cursor'=>$cursor,
                    'serialized_reference_words'=>in_array($type,[3,35],true)?$words:null,
                    'reference_words_are_runtime_resolved'=>false,
                    'runtime_references_resolved'=>false,
                    'class_header_extra_bytes'=>$headerExtra-($type===4?4:0),
                ];
                $out[]=$outRow;
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'simple_database_records'=>$out,
            'runtime_references_resolved'=>false];
    }
}
