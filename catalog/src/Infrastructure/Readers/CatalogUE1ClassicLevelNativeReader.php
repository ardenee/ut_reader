<?php
/**
 * Source-ordered UE1 ULevel native file data.
 *
 * Unreal v1.200 Engine/Src/UnLevel.cpp and UnReach.h:
 *   UObject -> UDatabase::Serialize (v<40 uses UObject TempNum/TempMax,
 *   v>=40 writes DbNum/DbMax, followed by DbNum compact actor references)
 *   -> [FURL if >=59] -> Model, ReachSpecs, TimeSeconds, FirstDeleted,
 *   16 TextBlocks, and TravelNames/TravelItems for >=61.
 * UT99 v400 Engine/Src/UnLevel.cpp and UnReach.h:
 *   UObject -> serialized actor DbNum/DbMax and actor references -> FURL
 *   -> Model, ReachSpecs, ApproxTime, FirstDeleted, 16 TextBlocks,
 *   versioned TravelInfo. The actor database wire sequence is the SAME
 *   as Unreal v1.200 for versions 60/61; source ancestry is different.
 *
 * Only file archive bytes; no runtime Preload, actor fixup, or networking.
 * Genuine matching samples and complete archive-family identity remain open.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicLevelNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $ver,callable $read,
        callable $compact,callable $uint,int &$position,int $limit,
        ?array $legacyDatabase=null
    ): array {
        $int=static function()use($uint):int {
            $v=$uint(); return $v>=0x80000000?$v-0x100000000:$v;
        };
        $float=static fn():float=>(float)unpack('g',$read(4))[1];
        $ref=static function(string $field)use($compact,$pkg):int {
            $n=$compact();
            if(($n<0&&-$n>count($pkg->getImports()))||
               ($n>0&&$n>count($pkg->getExports()))) {
                throw new RuntimeException('ULevel invalid '.$field.' object reference');
            }
            return $n;
        };
        $count=static function(string $field,int $atLeastBytes=1)use($compact,&$position,$limit):int {
            $n=$compact();
            if($n<0||$n>intdiv($limit-$position,$atLeastBytes)) {
                throw new RuntimeException('ULevel invalid '.$field.' array count');
            }
            return $n;
        };
        $str=static function()use($compact,$read,$ver,&$position,$limit):array {
            $signed=$compact();$wide=$signed<0;
            if($wide&&$ver<64) {
                throw new RuntimeException('ULevel Unicode FString unsupported by legacy version');
            }
            $n=abs($signed);$bytes=$n*($wide?2:1);
            if($bytes>$limit-$position) {
                throw new RuntimeException('ULevel FString exceeds source object');
            }
            $offset=$position;$raw=$read($bytes);
            $terminator=$wide?"\x00\x00":"\x00";
            if($n>0&&!str_ends_with($raw,$terminator)){
                throw new RuntimeException('ULevel FString lacks final NUL');
            }
            // UT99 Core/Src/UnMisc.cpp::operator<<(FString&) loads
            // ANSI bytes through FromAnsi(), not the OS code page.
            $body=$n>0?substr($raw,0,-strlen($terminator)):'';
            $text=$body===''?'':($wide
                ?@iconv('UTF-16LE','UTF-8',$body)
                :\UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromBytes($body));
            if($text===false){throw new RuntimeException('ULevel FString invalid encoding');}
            return ['value'=>$text,'offset'=>$offset,'code_units'=>$n,
                    'encoding'=>$wide?'UTF-16LE':'Epic-FromAnsi-bytewise'];
        };
        $stringArray=static function(string $field)use($count,$str):array {
            $n=$count($field);
            $items=[];
            for($i=0;$i<$n;$i++){$items[]=$str();}
            return $items;
        };
        $url=static function()use($str,$stringArray,$int):array {
            // FURL::operator<<: Protocol, Host, Map, Portal, Op, Port, Valid.
            return ['protocol'=>$str(),'host'=>$str(),'map'=>$str(),
                    'portal'=>$str(),'options'=>$stringArray('FURL.Op'),
                    'port'=>$int(),'valid'=>$int()];
        };
        $result=['archive_family'=>$ver<60?'Unreal-v1200':
                   ($ver<=61?'Unreal-v1200-and-UT99-v400-common-actor-wire':'UT99-v400'),
                 'actors_runtime_fixed_up'=>false];
        // Epic v1.200 ULevelBase inherits UDatabase. UDatabase::Serialize
        // reads TempNum/TempMax from the UObject prefix for versions <40,
        // otherwise serializes DbNum/DbMax before SerializeData(AActor*).
        // Epic UT99 ULevelBase serializes the same count/capacity + actors
        // for every supported non-transaction package archive (>=60).
        $database=CatalogUE1ClassicDatabaseCounts::read(
            $ver,$int,$legacyDatabase,$limit-$position);
        $actors=[];
        for($i=0;$i<$database['count'];$i++){$actors[]=$ref('Actors');}
        $result['actor_database']=$database+['actors'=>$actors];
        if($ver>=59){
            $result['url']=$url();
        }else{
            $result['url']=null;
        }
        $result['model_reference']=$ref('Model');
        $specCount=$count('ReachSpecs',$ver<54?18:19);
        $specs=[];
        for($i=0;$i<$specCount;$i++){
            $distance=$int();$start=$ref('ReachSpec.Start');$end=$ref('ReachSpec.End');
            $legacyFloatRadius=null;$legacyFloatHeight=null;
            if($ver<54){
                // Unreal v1.200 Engine/Inc/UnReach.h::FReachSpec::operator<<
                // archives two FLOAT values, but immediately assigns each
                // to the runtime INT CollisionRadius/CollisionHeight. A
                // parsed wire float is NOT the value the engine loads.
                $legacyFloatRadius=$float();$legacyFloatHeight=$float();
                // C++ FLOAT -> INT truncates toward zero for finite values
                // representable as signed INT32. Out-of-range, NaN and
                // infinity have implementation-dependent native outcomes:
                // retain the archive FLOAT and do not fabricate a value.
                $toEpicInt=static fn(float $v):?int=>
                    is_finite($v)&&$v>=-2147483648.0&&$v<2147483648.0
                        ?(int)$v:null;
                $radius=$toEpicInt($legacyFloatRadius);
                $height=$toEpicInt($legacyFloatHeight);
                $flags=$int();$pruned=null;
            }else{
                $radius=$int();$height=$int();$flags=$int();$pruned=ord($read(1));
            }
            $spec=['distance'=>$distance,'start_reference'=>$start,
                'end_reference'=>$end,'collision_radius'=>$radius,
                'collision_height'=>$height,'reach_flags'=>$flags,'pruned'=>$pruned];
            if($ver<54){
                // Preserve both source archive values and the actual
                // post-Deserialize INT values; no invented bPruned byte.
                $spec['archived_collision_radius_float']=$legacyFloatRadius;
                $spec['archived_collision_height_float']=$legacyFloatHeight;
                $spec['legacy_float_to_int_applied']=$radius!==null&&$height!==null;
                $spec['legacy_float_to_int_unspecified_native_result']=
                    $radius===null||$height===null;
            }
            $specs[]=$spec;
        }
        $result['reach_specs']=$specs;
        $result['time_seconds']=$float();
        $result['first_deleted_reference']=$ref('FirstDeleted');
        $blocks=[];
        for($i=0;$i<16;$i++){$blocks[]=$ref('TextBlocks['.$i.']');}
        $result['text_blocks']=$blocks;
        if($ver>=61 && $ver<=62){
            $result['travel_names']=$stringArray('TravelNames');
            $result['travel_items']=$stringArray('TravelItems');
        }elseif($ver>=63){
            // UT99 TMapBase::operator<< writes TArray<TPair> where
            // TPair::operator<< archives Key/Value only, not HashNext.
            $n=$count('TravelInfo');
            $pairs=[];
            for($i=0;$i<$n;$i++){
                $pairs[]=['key'=>$str(),'value'=>$str()];
            }
            $result['travel_info_pairs']=$pairs;
        }
        $result['status']=$position===$limit?
            'native_level_record_complete':'native_level_has_extension';
        $result['extension_bytes']=$limit-$position;
        return $result;
    }
}
