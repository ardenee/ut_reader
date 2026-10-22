<?php
/**
 * Unreal v1.200 UDatabase-derived UVectors/UVerts native file payload.
 *
 * Engine/Inc/UnObj.h: UVectors::BaseDataType=FVector (3 FLOAT32),
 * UVerts::BaseDataType=FVert (compact pVertex + compact iSide),
 * UVerts::Serialize additionally writes compact NumSharedSides.
 * Engine/Src/UnModel.cpp UDatabase::Serialize + IMPLEMENT_DB_CLASS macros.
 * No native runtime object pointers or model fixup.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicDatabaseNativeReader
{
    /** @param callable(int):string $read
     *  @param callable():int $compact
     *  @param callable():int $uint
     *  @return array<string,mixed>
     */
    public static function inspect(
        string $kind,int $version,callable $read,callable $compact,callable $uint,
        int &$position,int $limit,?array $legacyDatabase=null
    ): array {
        if(!in_array($kind,['vectors','verts'],true)){
            throw new RuntimeException('Not a classic UE1 vector/vertex database');
        }
        if($version>61){
            return ['status'=>'source_c98_database_class_version_unverified',
                'extension_bytes'=>$limit-$position];
        }
        $int=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $db=CatalogUE1ClassicDatabaseCounts::read(
            $version,$int,$legacyDatabase,$limit-$position);
        $n=$db['count'];
        $minimum=$kind==='vectors'?12:2;
        $suffix=$kind==='verts'?1:0;
        if($limit-$position<$suffix || $n>intdiv($limit-$position-$suffix,$minimum)){
            throw new RuntimeException('UE1 '.$kind.' database records exceed export bounds');
        }
        $preview=[];$digest=hash_init('sha256');
        for($i=0;$i<$n;$i++){
            if($kind==='vectors'){
                $raw=$read(12);
                $v=unpack('gx/gy/gz',$raw);
                hash_update($digest,$raw);
                $item=['x'=>$v['x'],'y'=>$v['y'],'z'=>$v['z']];
            }else{
                $point=$compact();$side=$compact();
                // Canonical decoded record digest, not a digest of compact
                // bytes (the compact decoder does not expose the raw bytes).
                hash_update($digest,pack('V2',$point & 0xffffffff,$side & 0xffffffff));
                $item=['vertex_index'=>$point,'side_index'=>$side];
            }
            if(count($preview)<16){$preview[]=$item;}
        }
        $extra=[];
        if($kind==='verts'){
            $shared=$compact();
            if($shared<0){
                throw new RuntimeException('UE1 UVerts negative NumSharedSides');
            }
            $extra=['num_shared_sides'=>$shared];
        }
        return $db+$extra+[
            'status'=>$position===$limit?'native_'.$kind.'_database_complete':
                'native_'.$kind.'_database_has_extension',
            'records_preview'=>$preview,
            'record_digest_sha256'=>hash_final($digest),
            'record_digest_kind'=>$kind==='vectors'?'wire_bytes':'decoded_int32_pairs',
            'extension_bytes'=>$limit-$position,
        ];
    }
}
