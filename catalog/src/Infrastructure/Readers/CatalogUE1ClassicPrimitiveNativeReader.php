<?php
/**
 * Classic UE1 UPrimitive base archive: FBox + versioned FSphere/legacy FPlane.
 * Epic Unreal v1.200 Engine/Src/UnPrim.cpp:22-35 and
 * Engine/Inc/UnMath.h FBox/FVector/FPlane serializers;
 * UT99 v400 Engine/Src/UnPrim.cpp:22-29 and Core/Inc/UnMath.h:
 * FSphere::operator<< serializes FVector only when Ver<=61.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1ClassicPrimitiveNativeReader
{
    /**
     * @return array<string,mixed>
     * The caller owns $position and $limit. Derived UModel and UMesh
     * serializers can reuse the exact same field-order prefix.
     * Source selection is explicit. Epic UE1 is the baseline;
     * UT99 game source overrides the old FVector-only FSphere format.
     */
    public static function readBase(
        int $version,callable $read,int &$position,int $limit,
        ?string $sourceFamily=null
    ):array
    {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        $original=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1;
        $f32=static function()use($read):float{
            return (float)unpack('gvalue',$read(4))['value'];
        };
        $vector=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $box=['min'=>$vector(),'max'=>$vector(),'is_valid'=>ord($read(1))];
        $sphere=$vector();
        $oldPlane=null;
        if($original&&$version<=40){
            // Epic UE1 engine v1.200 UnPrim.cpp archives a legacy
            // FPlane instead of FSphere before archive version 41.
            $oldPlane=['x'=>$sphere['x'],'y'=>$sphere['y'],
                'z'=>$sphere['z'],'w'=>$f32()];
            $sphere=['x'=>0.0,'y'=>0.0,'z'=>0.0,'w'=>0.0];
        }elseif($original||$version>61){
            // Canonical Epic Core/Inc/UnMath.h always archives
            // FVector + W. UT99's amended FSphere operator sends
            // just FVector when loading versions <=61.
            $sphere['w']=$f32();
        }else{
            $sphere['w']=null;
        }
        return [
            'bounding_box'=>$box,'bounding_sphere'=>$sphere,
            'old_legacy_plane'=>$oldPlane,
            'source_serialized_base_bytes'=>($original||$version>61)?41:37,
            'source_family'=>$family,
            'runtime_sphere_radius_linked'=>false,
        ];
    }
    /** @return array<string,mixed> */
    public static function inspect(int $version,callable $read,int &$position,int $limit,?string $sourceFamily=null):array
    {
        $data=self::readBase($version,$read,$position,$limit,$sourceFamily);
        return $data+[
            'status'=>$position===$limit?'native_primitive_record_complete':'native_primitive_has_extension',
            'extension_bytes'=>$limit-$position
        ];
    }
}
