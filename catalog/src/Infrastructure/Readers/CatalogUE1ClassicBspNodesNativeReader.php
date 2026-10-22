<?php
/**
 * Unreal v1.200 Engine/Inc/UnObj.h::FBspNode/FZoneProperties archive order.
 * UBspNodes::Serialize calls UDatabase::Serialize then compact NumZones,
 * then FZoneProperties[NumZones]. LastRenderTime is NOT on disk: the
 * FZoneProperties operator returns before its later version gate.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicBspNodesNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $version,
        callable $read,callable $compact,callable $uint,
        int &$pos,int $limit,?array $legacyDatabase=null
    ): array {
        // UT99 v400 old UBspNodes class still loads the same nodes/zones
        // database; its FBspNode operator clears NF_IsNew/Front/Back on load.
        $signed=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $ref=static function(string $field)use($compact,$pkg):int{
            $n=$compact();
            if(($n<0&&-$n>count($pkg->getImports()))
                ||($n>0&&$n>count($pkg->getExports()))){
                throw new RuntimeException('UE1 BSP zone '.$field.' object ref outside package');
            }
            return $n;
        };
        $db=CatalogUE1ClassicDatabaseCounts::read(
            $version,$signed,$legacyDatabase,$limit-$pos);
        // FBspNode: Plane(16), ZoneMask(8), Flags(1),
        // seven AR_INDEX (>=7), three BYTE(3), two INT32(8) = 43.
        // UBspNodes always adds >=1 byte for NumZones after the records.
        if($limit-$pos<1||$db['count']>intdiv($limit-$pos-1,43)){
            throw new RuntimeException('UE1 BSP nodes exceed object SerialSize');
        }
        $nodes=[];
        for($i=0;$i<$db['count'];$i++){
            $plane=array_values(unpack('g4',$read(16))?:[]);
            $zoneMask=bin2hex($read(8));
            $flags=ord($read(1));
            $indices=[];
            foreach(['vertex_pool','surface','child_back','child_front',
                     'child_plane','collision_bound','render_bound'] as $field){
                $indices[$field]=$compact();
            }
            $zoneBack=ord($read(1));$zoneFront=ord($read(1));
            $vertices=ord($read(1));
            $leafBack=$signed();$leafFront=$signed();
            if(count($nodes)<16){
                $nodes[]=['plane'=>$plane,'zone_mask_le_hex'=>$zoneMask,
                    'node_flags'=>$flags,
                    'node_flags_after_epic_load'=>$version>61?($flags&~0xE0):$flags,
                    'indices'=>$indices,
                    'zones'=>[$zoneBack,$zoneFront],
                    'num_vertices'=>$vertices,
                    'leaves'=>[$leafBack,$leafFront]];
            }
        }
        $numZones=$compact();
        if($numZones<0||$numZones>64
            ||$numZones>intdiv($limit-$pos,17)){
            throw new RuntimeException('UE1 BSP invalid NumZones or truncated zones');
        }
        $zones=[];
        for($i=0;$i<$numZones;$i++){
            $actor=$ref('ZoneActor');
            $connectivity=bin2hex($read(8));
            $visibility=bin2hex($read(8));
            if(count($zones)<16){
                $zones[]=['actor_reference'=>$actor,
                    'connectivity_le_hex'=>$connectivity,
                    'visibility_le_hex'=>$visibility];
            }
        }
        return $db+[
            'archive_family'=>$version>61?'UT99-v400-legacy-database':'Unreal-v1200',
            'status'=>$pos===$limit?'native_bspnodes_database_complete':
                'native_bspnodes_database_has_extension',
            'nodes_preview'=>$nodes,'num_zones'=>$numZones,
            'zones_preview'=>$zones,
            'last_render_time_serialized'=>false,
            'extension_bytes'=>$limit-$pos,
        ];
    }
}
