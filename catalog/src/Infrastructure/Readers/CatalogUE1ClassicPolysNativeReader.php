<?php
/**
 * Unreal v1.200 Engine/Inc/UnObj.h FPoly::operator<< native disk grammar.
 * UPolys : UDatabase calls generated SerializeData -> FPoly per element.
 * Version <45 additionally reads two BYTEs; Unreal v1.200 old-family
 * load clears PF_Masked for masked+translucent flags.
 * UT99 v400 Engine/Inc/UnObj.h UPolys::Serialize (TTransArray, nontransaction)
 * persists INT32 DbNum/DbMax and identical FPoly element wire ordering.
 * UT99 Engine/Src/UnModel.cpp::operator<<(FPoly&) clears PF_Transient
 * (PF_Highlighted = 0x10000000) instead. Preserve original disk flags.
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
        int &$pos,int $limit,?array $legacyDatabase=null,
        ?string $sourceFamily=null
    ): array {
        // UT99 v400 package loading takes its nontransactional path.
        // The compact FPoly element grammar is identical, but the flag
        // cleanup after read differs by the source family selected here.
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
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
            $version,$int,$legacyDatabase,$limit-$pos,$family);
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
            // Unreal-v1200 FPoly clears masked when translucent+masked.
            // UT99-v400 instead clears PF_Transient/PF_Highlighted.
            $after=$family===CatalogUE1ClassicSourceFamily::UT99_V400
                ? ($flags&~0x10000000)
                : ((($flags&0x6)===0x6) ? ($flags&~0x2) : $flags);
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
            'archive_family'=>$family,
            'status'=>$pos===$limit?'native_polys_database_complete':
                'native_polys_database_has_extension',
            'polys_preview'=>$preview,'extension_bytes'=>$limit-$pos,
        ];
    }
}
