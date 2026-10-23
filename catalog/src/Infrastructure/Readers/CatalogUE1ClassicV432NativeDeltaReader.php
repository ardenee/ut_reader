<?php
/**
 * UT99 v432 native-source additions layered on the v400 archive baseline.
 *
 * v432 Engine/Inc/UnMesh.h defines FMeshExtWedge (2 WORDs and 2 FLOATs).
 * v432 Engine/Inc/UnSkeletalMesh.h adds USkeletalMesh and UAnimation.
 * Their Serialize implementations are not in the v432 public tree:
 * Core/Engine Lib has compiled declarations but no shipped Src bodies.
 *
 * Do NOT infer class-native serialization order from member declarations.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicV432NativeDeltaReader
{
    /**
     * FMeshExtWedge::operator<<(FArchive&,FMeshExtWedge&):
     * iVertex : WORD, Flags : WORD, TexUV.U/V : FLOAT32LE.
     * Caller must establish the actual native array offset/count from
     * compiled/source evidence before invoking this helper.
     *
     * @return array<string,mixed>
     */
    public static function decodeExtendedWedges(
        string $bytes,int $offset,int $count
    ):array{
        $length=strlen($bytes);
        if($offset<0||$count<0||$offset>$length
            ||$count>intdiv($length-$offset,12)){
            throw new RuntimeException('UT99 v432 FMeshExtWedge archive bounds');
        }
        $byteCount=$count*12;
        $sha=hash_init('sha256');
        $preview=[];
        for($n=0;$n<$count;$n++){
            $base=$offset+$n*12;
            $entry=substr($bytes,$base,12);
            hash_update($sha,$entry);
            if($n<16){
                $parts=unpack('vvertex/vflags/gU/gV',$entry);
                $preview[]=[
                    'vertex_index'=>(int)$parts['vertex'],
                    'flags'=>(int)$parts['flags'],
                    'uv_u'=>(float)$parts['U'],
                    'uv_v'=>(float)$parts['V'],
                ];
            }
        }
        return [
            'source_record'=>'FMeshExtWedge',
            'source'=>'UT99 v432 Engine/Inc/UnMesh.h',
            'source_record_size_bytes'=>12,
            'count'=>$count,
            'archive_offset'=>$offset,
            'archive_bytes'=>$byteCount,
            'archive_sha256'=>hash_final($sha),
            'records_preview'=>$preview,
            'preview_truncated'=>$count>16,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function inspect(
        string $path,string $className,int $offset,int $length,
        string $sourceProfile
    ):?array{
        if($sourceProfile!==CatalogUE1ClassicSourceFamily::UT99_V432){
            return null;
        }
        $kind=strtolower($className);
        if(!in_array($kind,['skeletalmesh','animation'],true)){
            return null;
        }
        if($length<0||$offset<0){
            throw new RuntimeException('Invalid UT99 v432 native extent');
        }
        $size=filesize($path);
        if($size===false||$offset>$size||$length>$size-$offset){
            throw new RuntimeException('UT99 v432 native bytes exceed file');
        }
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open UT99 v432 package');}
        try{
            if(fseek($fp,$offset)!==0){
                throw new RuntimeException('Cannot seek UT99 v432 native bytes');
            }
            $digest=hash_init('sha256');
            $remaining=$length;
            while($remaining>0){
                $chunk=fread($fp,min(65536,$remaining));
                if(!is_string($chunk)||$chunk===''){
                    throw new RuntimeException('Truncated UT99 v432 native payload');
                }
                hash_update($digest,$chunk);
                $remaining-=strlen($chunk);
            }
        }finally{
            fclose($fp);
        }
        return [
            'status'=>'native_v432_serializer_source_order_unverified',
            'class_name'=>$className,
            'native_payload_offset'=>$offset,
            'native_payload_bytes'=>$length,
            'native_payload_sha256'=>hash_final($digest),
            'inherited_engine_source'=>'UT99 v400 Core/Engine Serialize implementation baseline',
            'v432_class_declaration'=>'Engine/Inc/UnSkeletalMesh.h',
            'v432_extra_struct_definition'=>'Engine/Inc/UnMesh.h::FMeshExtWedge',
            'v432_native_method_body_available'=>false,
            'archive_order_guessed'=>false,
            'requires_compiled_engine_method_evidence'=>true,
            'g5_verification'=>'OPEN',
        ];
    }
}
