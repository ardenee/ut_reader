<?php
/**
 * Unreal v1.200 Engine/Inc/UnObj.h FPoly::operator<< native disk grammar.
 * UPolys : UDatabase calls generated SerializeData -> FPoly per element.
 * Version <45 additionally reads two BYTEs; masked+translucent flags
 * clear PF_Masked during Epic's load, without altering original disk bytes.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicPolysNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $version,
        callable $read,callable $compact,callable $uint,
        int &$pos,int $limit,?array $legacyDatabase=null
    ): array {
        if($version>61){
            return ['status'=>'source_c98_polys_version_unverified',
                'extension_bytes'=>$limit-$pos];
        }
        $int=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $signed16=static function()use($read):int{
            $v=(int)unpack('v',$read(2))[1];
            return $v>=0x8000?$v-0x10000:$v;
        };
        $obj=static function(string $field)use($compact,$pkg):int{
            $n=$compact();
            if(($n<0&&-$n>count($pkg->getImports()))
                ||($n>0&&$n>count($pkg->getExports()))){
                throw new RuntimeException('FPoly '.$field.' object reference outside package map');
            }
            return $n;
        };
        $name=static function()use($compact,$pkg):int{
            $n=$compact();
            if($n<0||!isset($pkg->getNames()[$n])){
                throw new RuntimeException('FPoly ItemName outside name table');
            }
            return $n;
        };
        $vec=static function()use($read):array{
            $v=unpack('g3',$read(12));
            return [(float)$v[1],(float)$v[2],(float)$v[3]];
        };
        $db=CatalogUE1ClassicDatabaseCounts::read(
            $version,$int,$legacyDatabase,$limit-$pos);
        $min=$version<45?64:62;
        if($db['count']>intdiv($limit-$pos,$min)){
            throw new RuntimeException('UPolys serialized count exceeds object bounds');
        }
        $preview=[];
        for($i=0;$i<$db['count'];$i++){
            $count=$compact();
            if($count<0||$count>16){
                throw new RuntimeException('FPoly NumVertices outside Epic MAX_VERTICES');
            }
            if($limit-$pos<($min-1)+$count*12){
                throw new RuntimeException('FPoly vertices and trailer exceed export SerialSize');
            }
            $base=$vec();$normal=$vec();$texU=$vec();$texV=$vec();
            $vertices=[];
            for($j=0;$j<$count;$j++){
                $v=$vec();if($i<16){$vertices[]=$v;}
            }
            $flags=$uint();$actor=$obj('Actor');$texture=$obj('Texture');
            $itemName=$name();$link=$compact();$brushPoly=$compact();
            $panU=$signed16();$panV=$signed16();
            $legacy=null;
            if($version<45){$legacy=[ord($read(1)),ord($read(1))];}
            // Epic code explicitly clears PF_Masked when both set on load.
            $after=($flags&0x6)===0x6 ? ($flags&~0x2) : $flags;
            if(count($preview)<16){
                $preview[]=['num_vertices'=>$count,'base'=>$base,
                    'normal'=>$normal,'texture_u'=>$texU,'texture_v'=>$texV,
                    'vertices'=>$vertices,'poly_flags_serialized'=>$flags,
                    'poly_flags_after_epic_load'=>$after,
                    'actor_reference'=>$actor,'texture_reference'=>$texture,
                    'item_name_index'=>$itemName,
                    'link_index'=>$link,'brush_poly_index'=>$brushPoly,
                    'pan_u'=>$panU,'pan_v'=>$panV,
                    'legacy_two_bytes'=>$legacy];
            }
        }
        return $db+[
            'status'=>$pos===$limit?'native_polys_database_complete':
                'native_polys_database_has_extension',
            'polys_preview'=>$preview,'extension_bytes'=>$limit-$pos,
        ];
    }
}
