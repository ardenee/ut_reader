<?php
/**
 * Unreal v0.867 UObject::SerializeHeader FExecStackMain prefix.
 *
 * Src/Inc/UnScript.h:449-457,507-529:
 * Ar << Exec.Link (DWORD 1-based class object ref; optional INT iNode),
 * Ar << Exec.Script (DWORD 1-based script object ref),
 * optional WORD code offset when script is present, then QWORD ProbeMask.
 * Source v0.867 Src/Inc/UnLinker.h::FArchiveResLoad maps zero refs to
 * NULL, all positive object indices through ResMap[index-1].
 *
 * The bytes after this prefix are UClass::SerializeBin class-defined
 * member data: NOT inferred from arbitrary bytes.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileStackReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, bool $rejectShortHeaders = false): array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $count=(int)$meta['summary']['object_count'];
        $results=[];
        $fp=@fopen($path,'rb');
        if(!is_resource($fp)){throw new RuntimeException('Cannot open Unrealfile object headers');}
        try {
            foreach ($meta['object_bases'] as $o) {
                if($o['kind']!=='export'){continue;}
                $header=(int)$o['file_header_offset'];
                $size=(int)$o['file_header_size'];
                $index=(int)$o['index'];
                if($size<16) {
                    if($rejectShortHeaders){
                        throw new RuntimeException('Original Unrealfile export '.$index.' cannot contain the minimum 16-byte FExecStackMain prefix');
                    }
                    $results[]=['object_index'=>$index,'status'=>'invalid_or_incomplete_stack_prefix',
                        'header_bytes'=>$size,'member_properties_decoded'=>false];
                    continue;
                }
                if(fseek($fp,$header,SEEK_SET)!==0){
                    throw new RuntimeException('Cannot seek original Unrealfile object header');
                }
                $consumed=0;
                $read=static function(int $bytes)use($fp,$size,&$consumed):string{
                    if($bytes>$size-$consumed){throw new RuntimeException('Original Unrealfile FExecStackMain exceeds header size');}
                    $data='';
                    while(strlen($data)<$bytes){
                        $part=fread($fp,$bytes-strlen($data));
                        if(!is_string($part)||$part===''){throw new RuntimeException('Truncated FExecStackMain');}
                        $data.=$part;
                    }
                    $consumed+=$bytes;return $data;
                };
                $word=static fn():int=>(int)unpack('V',$read(4))[1];
                $ref=static function(int $id)use($count):?int{
                    if($id>$count){throw new RuntimeException('FExecStackMain object ref exceeds original ResMap');}
                    return $id===0?null:$id-1;
                };
                $link=$word();$linkIndex=$ref($link);
                $node=$link!==0?$word():null;
                $script=$word();$scriptIndex=$ref($script);
                $code=$script!==0?(int)unpack('v',$read(2))[1]:null;
                $mask=$read(8);
                $results[]=[
                    'object_index'=>$index,'status'=>'native_stack_prefix_decoded',
                    'link_class_ref'=>$link,'link_class_index'=>$linkIndex,
                    'link_node_index'=>$node,
                    'script_ref'=>$script,'script_index'=>$scriptIndex,
                    'code_offset'=>$code===0xffff?null:$code,
                    'code_offset_none'=>$code===0xffff,
                    'probe_mask_le_hex'=>strtoupper(bin2hex($mask)),
                    'serialized_prefix_bytes'=>$consumed,
                    'class_member_bytes_remaining'=>$size-$consumed,
                    'member_properties_decoded'=>false,
                    'runtime_stack_constructed'=>false,
                ];
            }
        }finally{fclose($fp);}
        return [
            'source_format'=>'early_unrealfile',
            'stack_prefixes'=>$results,
            'object_class_members_decoded'=>false,
            'source_serializer'=>'FExecStackMain binary v0.867',
        ];
    }
}
