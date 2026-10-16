<?php
/** Epic UE5 FilePackageStore mount ordering and first-wins mapping aggregation. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use RuntimeException;
final class Uedb5ZenMountedStoreContextBuilder
{
    /**
     * A declared mount set is not by itself proof of the complete runtime environment.
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
        foreach($ordered as $mount){
            foreach([['package_redirects',&$redirects,&$seenRedirect],
                     ['localized_packages',&$localized,&$seenLocalized]] as &$set){
                [$key,&$target,&$dedupe]=$set;
                foreach($mount[$key] as $row){
                    if(!is_array($row))throw new RuntimeException('Mount mapping must be an object.');
                    $source=strtoupper(trim((string)($row['source_package_id']??'')));
                    if(!preg_match('/^[0-9A-F]{16}$/D',$source))
                        throw new RuntimeException('Mount mapping requires 16-digit hexadecimal source_package_id.');
                    if(isset($dedupe[$source]))continue;
                    $dedupe[$source]=true;
                    $target[]=$row;
                }
                unset($set);
            }
        }
        return [
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
}
