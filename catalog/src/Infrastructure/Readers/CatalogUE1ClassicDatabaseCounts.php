<?php
/**
 * Unreal v1.200 Engine/Src/UnModel.cpp UDatabase::Serialize:
 * v<40 uses TempNum/TempMax from UObject, v>=40 reads DbNum/DbMax
 * as two signed 32-bit values from the native tail.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicDatabaseCounts
{
    /** @param callable():int $signedInt
     *  @param array<string,mixed>|null $legacy
     *  @return array{count:int,capacity:int,counts_in_uobject_prefix:bool}
     */
    public static function read(
        int $version, callable $signedInt, ?array $legacy, int $remaining,
        ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        $inPrefix=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1&&$version<40;
        if($inPrefix){
            if($legacy===null || !isset($legacy['count'],$legacy['capacity'])){
                throw new RuntimeException('UE1 database legacy UObject counts unavailable');
            }
            $num=(int)$legacy['count'];
            $max=(int)$legacy['capacity'];
            if($num>=0x80000000){$num-=0x100000000;}
            if($max>=0x80000000){$max-=0x100000000;}
        }else{
            $num=$signedInt();$max=$signedInt();
        }
        if($num<0 || $max<$num || $num>$remaining){
            throw new RuntimeException('UE1 database Num/Max invalid or outside export SerialSize');
        }
        return ['count'=>$num,'capacity'=>$max,'counts_in_uobject_prefix'=>$inPrefix];
    }
}
