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
     * The optional radius override applies ONLY to an old model's
     * independently trial-parsed alternative sphere layout, never as
     * an unconditional change to a standalone UPrimitive archive.
     */
    public static function readBase(
        int $version,callable $read,int &$position,int $limit,
        bool $radiusForLegacySphere=false
    ):array
    {
        $f32=static function()use($read):float{
            return (float)unpack('gvalue',$read(4))['value'];
        };
        $vector=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $box=['min'=>$vector(),'max'=>$vector(),'is_valid'=>ord($read(1))];
        $sphere=$vector();
        $oldPlane=null;
        if($version<=40){
            $oldPlane=['x'=>$sphere['x'],'y'=>$sphere['y'],
                'z'=>$sphere['z'],'w'=>$f32()];
            $sphere=['x'=>0.0,'y'=>0.0,'z'=>0.0,'w'=>0.0];
        }elseif($version>61||$radiusForLegacySphere){
            $sphere['w']=$f32();
        }else{
            $sphere['w']=null; // v41-v61 legacy FVector-only FSphere
        }
        return [
            'bounding_box'=>$box,'bounding_sphere'=>$sphere,
            'old_legacy_plane'=>$oldPlane,
            'source_serialized_base_bytes'=>($version<=40||$version>61||$radiusForLegacySphere)?41:37,
            'runtime_sphere_radius_linked'=>false,
        ];
    }
    /** @return array<string,mixed> */
    public static function inspect(int $version,callable $read,int &$position,int $limit):array
    {
        $data=self::readBase($version,$read,$position,$limit);
        return $data+[
            'status'=>$position===$limit?'native_primitive_record_complete':'native_primitive_has_extension',
            'extension_bytes'=>$limit-$position
        ];
    }
}
