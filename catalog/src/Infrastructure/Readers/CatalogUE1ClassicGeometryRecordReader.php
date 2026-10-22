<?php
/**
 * Source-ordered classic UE1 BSP record archive operators.
 *
 * Unreal v1.200 Engine/Inc/UnObj.h: FVert, FBspSurf, FBspNode,
 * FZoneProperties. UT99 v400 Engine/Src/UnModel.cpp: FBspSurf,
 * FPoly (handled separately), FBspNode; Engine/Inc/UnObj.h:
 * FVert and FZoneProperties.
 *
 * The same operators are used by pre-62 legacy database classes and
 * by UT99 v400 UModel's inline TArray geometry (package version >61).
 * Do not serialize runtime-only FBspSurf::Decals or FZoneProperties
 * LastRenderTime: Epic's zone serializer returns before that statement.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicGeometryRecordReader
{
    /** @param callable():int $compact @return array<string,int> */
    public static function vert(callable $compact):array
    {
        return ['vertex_index'=>$compact(),'side_index'=>$compact()];
    }

    /** @param callable(int):string $read
     * @param callable():int $compact
     * @param callable():int $u32
     * @return array<string,mixed>
     */
    public static function bspNode(
        int $version,callable $read,callable $compact,callable $u32
    ):array {
        $plane=array_values(unpack('g4',$read(16))?:[]);
        $mask=bin2hex($read(8));
        $flags=ord($read(1));
        $indices=[];
        foreach(['vertex_pool','surface','child_back','child_front',
                 'child_plane','collision_bound','render_bound'] as $field){
            $indices[$field]=$compact();
        }
        $back=ord($read(1));$front=ord($read(1));
        $numVertices=ord($read(1));
        $leaf0=$u32();$leaf1=$u32();
        $leaf0=$leaf0>=0x80000000?$leaf0-0x100000000:$leaf0;
        $leaf1=$leaf1>=0x80000000?$leaf1-0x100000000:$leaf1;
        return [
            'plane'=>$plane,'zone_mask_le_hex'=>$mask,
            'node_flags'=>$flags,
            'node_flags_after_epic_load'=>$version>61?($flags&~0xE0):$flags,
            'indices'=>$indices,'zones'=>[$back,$front],
            'num_vertices'=>$numVertices,'leaves'=>[$leaf0,$leaf1],
        ];
    }

    /** @param callable(int):string $read
     * @param callable():int $compact
     * @param callable():int $u32
     * @return array<string,mixed>
     */
    public static function bspSurf(
        CatalogUE1PackageReader $pkg,int $version,
        callable $read,callable $compact,callable $u32
    ):array {
        $texture=self::objectRef($pkg,$compact,'FBspSurf.Texture');
        $flags=$u32();$indices=[];
        foreach(['p_base','v_normal','v_texture_u','v_texture_v',
                 'light_map','brush_poly'] as $field){
            $indices[$field]=$compact();
        }
        $panU=(int)unpack('v',$read(2))[1];
        $panV=(int)unpack('v',$read(2))[1];
        if($panU>=0x8000){$panU-=0x10000;}
        if($panV>=0x8000){$panV-=0x10000;}
        $actor=self::objectRef($pkg,$compact,'FBspSurf.Actor');
        return [
            'texture_reference'=>$texture,
            'poly_flags'=>$flags,
            'poly_flags_after_epic_load'=>$version>61?($flags&~0x10000000):$flags,
            'indices'=>$indices,'pan_u'=>$panU,'pan_v'=>$panV,
            'actor_reference'=>$actor,
        ];
    }

    /** @param callable(int):string $read
     * @param callable():int $compact
     * @return array<string,mixed>
     */
    public static function zone(
        CatalogUE1PackageReader $pkg,callable $read,callable $compact
    ):array {
        return [
            'actor_reference'=>self::objectRef($pkg,$compact,'ZoneActor'),
            'connectivity_le_hex'=>bin2hex($read(8)),
            'visibility_le_hex'=>bin2hex($read(8)),
        ];
    }

    /** @param callable():int $compact */
    private static function objectRef(
        CatalogUE1PackageReader $pkg,callable $compact,string $field
    ):int {
        $n=$compact();
        if(($n<0&&-$n>count($pkg->getImports()))
            ||($n>0&&$n>count($pkg->getExports()))){
            throw new RuntimeException('Invalid UE1 '.$field.' package object reference');
        }
        return $n;
    }
}
