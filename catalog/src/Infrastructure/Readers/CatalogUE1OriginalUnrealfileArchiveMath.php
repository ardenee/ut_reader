<?php
/**
 * Real early UE1 FArchive math wire formats, used by UModel and UMesh.
 *
 * May v0.86x: Src/Inc/UnMath.h FBoundingRect (24B min/max,
 * BYTE IsValid, 16B FPlane Sphere) = 41B. FRotation writes three WORD
 * angles (6B), zero-extended into the in-memory INT fields on load.
 *
 * July v0.867: FBoundingBox replaces FBoundingRect but has the SAME
 * 41B archive layout. FRotation writes three INT angles (12B).
 * Src/Inc/UnArc.h proves BYTE=1B and WORD=2B, not aligned C++ sizeof.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileArchiveMath
{
    public const BOUND_BYTES=41;
    public const ROTATION_MAY_BYTES=6;
    public const ROTATION_JULY_BYTES=12;

    public static function bound(string $data,int &$pos):array
    {
        $chunk=self::take($data,$pos,self::BOUND_BYTES);
        // Both Epic May FBoundingRect and July FBoundingBox archive this
        // member as a raw BYTE.  Neither FArchive operator imposes a
        // boolean-range check; preserve every serialized 0..255 value.
        $valid=ord($chunk[24]);
        $sphere=array_values(unpack('g4',substr($chunk,25,16)));
        return [
            'min'=>array_values(unpack('g3',substr($chunk,0,12))),
            'max'=>array_values(unpack('g3',substr($chunk,12,12))),
            'is_valid'=>$valid,
            'sphere_center'=>array_slice($sphere,0,3),
            'sphere_radius'=>$sphere[3],
        ];
    }

    public static function rotation(string $data,int &$pos,bool $may):array
    {
        if($may){
            // Epic assigns three serialized WORDs back into INTs,
            // intentionally ZERO extending, not signed 16-bit values.
            return array_values(unpack('v3',self::take($data,$pos,self::ROTATION_MAY_BYTES)));
        }
        return array_map(
            static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v,
            array_values(unpack('V3',self::take($data,$pos,self::ROTATION_JULY_BYTES)))
        );
    }

    private static function take(string $data,int &$pos,int $size):string
    {
        if($size<0||$pos<0||$size>strlen($data)-$pos)
            throw new RuntimeException('Truncated original UE1 FArchive math value');
        $out=substr($data,$pos,$size);
        $pos+=$size;
        return $out;
    }
}
