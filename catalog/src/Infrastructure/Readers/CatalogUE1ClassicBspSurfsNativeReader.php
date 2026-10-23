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
        int &$pos,int $limit,?array $legacyDatabase=null,
        ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        // UT99 v400 Engine/Src/UnModel.cpp old UBspSurfs class still
        // loads DbNum/DbMax and FBspSurf array. Its operator<< clears
        // PF_Transient on load and skips Decals on file archives.
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
        $preview=[];
        for($i=0;$i<$db['count'];$i++){
            $surf=CatalogUE1ClassicGeometryRecordReader::bspSurf(
                $pkg,$version,$read,$compact,$uint,$family);
            if($i<16){$preview[]=$surf;}
        }
        return $db+['archive_family'=>$family,
            'status'=>$pos===$limit?'native_bspsurfs_database_complete':
                'native_bspsurfs_database_has_extension',
            'surfaces_preview'=>$preview,'extension_bytes'=>$limit-$pos];
    }
}
