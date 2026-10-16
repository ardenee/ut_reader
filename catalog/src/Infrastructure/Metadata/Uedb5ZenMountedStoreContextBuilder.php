<?php
/** Epic UE5 FilePackageStore mount ordering and first-wins mapping aggregation. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use RuntimeException;
final class Uedb5ZenMountedStoreContextBuilder
{
    /**
     * A declared mount set is not by itself proof of the complete runtime environment.
     * Each optional package_entries list maps source-observed 16-hex FPackageId
     * to a separately verified positive catalog file_id. Missing entries do not
     * imply Missing at runtime, and these lists do not confer authority.
     * @param list<array<string,mixed>> $mounts
     * @return array<string,mixed>
     */
    public static function build(array $mounts): array
    {
        $ordered=[];
        $seen=[];
        foreach($mounts as $mount){
            if(!is_array($mount))throw new RuntimeException('Mount descriptor must be an array.');
            $id=trim((string)($mount['container_id']??''));
            if($id===''||isset($seen[$id]))throw new RuntimeException('Mount container IDs must be nonempty and unique.');
            $seen[$id]=true;
            foreach(['order','sequence'] as $field){
                if(!isset($mount[$field])||!is_int($mount[$field])||$mount[$field]<0)
                    throw new RuntimeException('Mount '.$field.' must be an explicit nonnegative integer.');
            }
            foreach(['package_redirects','localized_packages'] as $field){
                if(!isset($mount[$field])||!is_array($mount[$field])||!array_is_list($mount[$field]))
                    throw new RuntimeException('Mount '.$field.' must be an explicit list (possibly empty).');
            }
            $ordered[]=$mount;
        }
        usort($ordered,static fn(array $a,array $b):int=>
            ($b['order']<=>$a['order'])?:($b['sequence']<=>$a['sequence']));
        $redirects=[];$localized=[];$seenRedirect=[];$seenLocalized=[];
        $entries=[];
        foreach($ordered as $mount){
            // FilePackageStore::Update builds its package map from PackageIds
            // in the effective mount order. Physical catalog file IDs must be
            // independently evidenced; never infer them from a TOC alone.
            if(array_key_exists('package_entries',$mount)){
                if(!is_array($mount['package_entries'])||!array_is_list($mount['package_entries']))
                    throw new RuntimeException('Mount package_entries must be an explicit list.');
                foreach($mount['package_entries'] as $row){
                    if(!is_array($row))throw new RuntimeException('Mount package entry must be an object.');
                    $id=strtoupper((string)($row['package_id']??''));
                    if(!preg_match('/^[0-9A-F]{16}$/D',$id))
                        throw new RuntimeException('Mount package entry requires 16-digit FPackageId.');
                    $fileId=$row['file_id']??null;
                    if(!is_int($fileId)||$fileId<1)
                        throw new RuntimeException('Mount package entry requires evidenced positive integer file_id.');
                    if(!isset($entries[$id])){
                        $entries[$id]=['status'=>'Ok','file_id'=>$fileId];
                    }
                }
            }
            foreach($mount['package_redirects'] as $row){
                if(!is_array($row))throw new RuntimeException('Mount redirect must be an object.');
                $source=self::packageId($row);
                $target=strtoupper(trim((string)($row['target_package_id']??'')));
                if(!preg_match('/^[0-9A-F]{16}$/D',$target))
                    throw new RuntimeException('Mount redirect requires 16-digit hexadecimal target_package_id.');
                if(isset($seenRedirect[$source]))continue;
                $seenRedirect[$source]=true;
                $redirects[]=$row;
            }
            foreach($mount['localized_packages'] as $row){
                if(!is_array($row))throw new RuntimeException('Mount localization must be an object.');
                $source=self::packageId($row);
                if(isset($seenLocalized[$source]))continue;
                $seenLocalized[$source]=true;
                $localized[]=$row;
            }
        }
        return [
            'file_backend_entries'=>$entries,
            'package_redirects'=>$redirects,
            'localized_packages'=>$localized,
            'mounted_container_order'=>array_map(static fn(array $m):array=>[
                'container_id'=>(string)$m['container_id'],
                'order'=>$m['order'],'sequence'=>$m['sequence'],
            ],$ordered),
            // No claims about backend completeness, CoreRedirect, culture or runtime rewrites.
            'package_store_context_authoritative'=>false,
            'package_identity_context_authoritative'=>false,
        ];
    }
    private static function packageId(array $row): string
    {
        $source=strtoupper(trim((string)($row['source_package_id']??'')));
        if(!preg_match('/^[0-9A-F]{16}$/D',$source))
            throw new RuntimeException('Mount mapping requires 16-digit hexadecimal source_package_id.');
        return $source;
    }
}
