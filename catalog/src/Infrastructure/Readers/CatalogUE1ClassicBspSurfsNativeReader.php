<?php
/** Epic v1.200 UBspSurfs archive: inherited UDatabase counts and FBspSurf records. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1ClassicBspSurfsNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $version,
        callable $read,callable $compact,callable $uint,
        int &$pos,int $limit,?array $legacyDatabase=null
    ): array {
        if($version>61){
            return ['status'=>'source_c98_bspsurfs_version_unverified',
                'extension_bytes'=>$limit-$pos];
        }
        $int=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $db=CatalogUE1ClassicDatabaseCounts::read(
            $version,$int,$legacyDatabase,$limit-$pos);
        // FBspSurf: object ref + DWORD flags + six compact indices
        // + SWORD PanU/V + object ref, at least 16 bytes per record.
        if($db['count']>intdiv($limit-$pos,16)){
            throw new RuntimeException('UE1 BSP surface records exceed SerialSize');
        }
        $ref=static function(string $field)use($compact,$pkg):int{
            $v=$compact();
            if(($v<0&&-$v>count($pkg->getImports()))
                ||($v>0&&$v>count($pkg->getExports()))){
                throw new RuntimeException('Invalid FBspSurf '.$field.' object ref');
            }
            return $v;
        };
        $signed16=static function()use($read):int{
            $v=(int)unpack('v',$read(2))[1];
            return $v>=0x8000?$v-0x10000:$v;
        };
        $preview=[];
        for($i=0;$i<$db['count'];$i++){
            $texture=$ref('Texture');$flags=$uint();$indices=[];
            foreach(['p_base','v_normal','v_texture_u','v_texture_v',
                     'light_map','brush_poly'] as $name){
                $indices[$name]=$compact();
            }
            $panU=$signed16();$panV=$signed16();$actor=$ref('Actor');
            if(count($preview)<16){
                $preview[]=['texture_reference'=>$texture,'poly_flags'=>$flags,
                    'indices'=>$indices,'pan_u'=>$panU,'pan_v'=>$panV,
                    'actor_reference'=>$actor];
            }
        }
        return $db+['status'=>$pos===$limit?'native_bspsurfs_database_complete':
                'native_bspsurfs_database_has_extension',
            'surfaces_preview'=>$preview,'extension_bytes'=>$limit-$pos];
    }
}
