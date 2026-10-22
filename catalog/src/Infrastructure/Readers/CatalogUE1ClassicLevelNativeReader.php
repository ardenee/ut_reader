<?php
/**
 * Source-ordered UE1 ULevel native file data.
 *
 * Unreal v1.200 Engine/Src/UnLevel.cpp and UnReach.h:
 *   UObject -> [URL if >=59] -> Model, ReachSpecs, float TimeSeconds,
 *   FirstDeleted, 16 TextBlocks, optional TravelNames/TravelItems >=61.
 * UT99 v400 Engine/Src/UnLevel.cpp and UnReach.h:
 *   UObject -> DbNum/DbMax actor list + FURL -> Model, ReachSpecs,
 *   float ApproxTime, FirstDeleted, 16 TextBlocks, versioned TravelInfo.
 *
 * Only package archive bytes; no runtime Preload, actor fixup, or networking.
 * Source families overlap historically. v<=61 uses 1998 source branch,
 * v>=62 uses v400 branch; classification is PARTIAL pending genuine samples.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicLevelNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $ver,callable $read,
        callable $compact,callable $uint,int &$position,int $limit
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
            $body=$n>0?substr($raw,0,-strlen($terminator)):'';
            $text=$body===''?'':@iconv($wide?'UTF-16LE':'Windows-1252','UTF-8',$body);
            if($text===false){throw new RuntimeException('ULevel FString invalid encoding');}
            return ['value'=>$text,'offset'=>$offset,'code_units'=>$n,
                    'encoding'=>$wide?'UTF-16LE':'Windows-1252'];
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
        $result=['archive_family'=>$ver<=61?'Unreal-v1200':'UT99-v400',
                 'actors_runtime_fixed_up'=>false];
        if($ver>=62){
            // UT99 ULevelBase::Serialize: only non-transactional package case.
            $dbNum=$int();$dbMax=$int();
            if($dbNum<0||$dbMax<$dbNum||$dbNum>$limit-$position){
                throw new RuntimeException('ULevel actor DbNum/DbMax invalid');
            }
            $actors=[];
            for($i=0;$i<$dbNum;$i++){$actors[]=$ref('Actors');}
            $result['actor_database']=['count'=>$dbNum,'capacity'=>$dbMax,'actors'=>$actors];
        }
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
            if($ver<54){
                $radius=$float();$height=$float();$flags=$int();$pruned=null;
            }else{
                $radius=$int();$height=$int();$flags=$int();$pruned=ord($read(1));
            }
            $specs[]=['distance'=>$distance,'start_reference'=>$start,
                'end_reference'=>$end,'collision_radius'=>$radius,
                'collision_height'=>$height,'reach_flags'=>$flags,'pruned'=>$pruned];
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
