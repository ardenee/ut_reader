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
        int &$pos,int $limit,?array $legacyDatabase=null,
        ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        // UT99 v400 old UBspNodes class still loads the same nodes/zones
        // database; its FBspNode operator clears NF_IsNew/Front/Back on load.
        $signed=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $db=CatalogUE1ClassicDatabaseCounts::read(
            $version,$signed,$legacyDatabase,$limit-$pos,$family);
        // FBspNode: Plane(16), ZoneMask(8), Flags(1),
        // seven AR_INDEX (>=7), three BYTE(3), two INT32(8) = 43.
        // UBspNodes always adds >=1 byte for NumZones after the records.
        if($limit-$pos<1||$db['count']>intdiv($limit-$pos-1,43)){
            throw new RuntimeException('UE1 BSP nodes exceed object SerialSize');
        }
        $nodes=[];
        for($i=0;$i<$db['count'];$i++){
            $node=CatalogUE1ClassicGeometryRecordReader::bspNode(
                $version,$read,$compact,$uint,$family);
            if($i<16){$nodes[]=$node;}
        }
        $numZones=$compact();
        if($numZones<0||$numZones>64
            ||$numZones>intdiv($limit-$pos,17)){
            throw new RuntimeException('UE1 BSP invalid NumZones or truncated zones');
        }
        $zones=[];
        for($i=0;$i<$numZones;$i++){
            $zone=CatalogUE1ClassicGeometryRecordReader::zone(
                $pkg,$read,$compact);
            if($i<16){$zones[]=$zone;}
        }
        return $db+[
            'archive_family'=>$family,
            'status'=>$pos===$limit?'native_bspnodes_database_complete':
                'native_bspnodes_database_has_extension',
            'nodes_preview'=>$nodes,'num_zones'=>$numZones,
            'zones_preview'=>$zones,
            'last_render_time_serialized'=>false,
            'extension_bytes'=>$limit-$pos,
        ];
    }
}
