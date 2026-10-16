<?php
/** Combines Epic file-backend mounted-store mappings and prioritized package-store backends. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use RuntimeException;
final class Uedb5ZenEffectivePackageStoreContextBuilder
{
    /**
     * @param list<array<string,mixed>> $mounts explicit FilePackageStore Mount data
     * @param list<array<string,mixed>> $otherBackends explicit non-file FPackageStore backends
     */
    public static function build(array $mounts, array $otherBackends, int $fileBackendPriority): array
    {
        $file=Uedb5ZenMountedStoreContextBuilder::build($mounts);
        $redirects=[];
        foreach($file['package_redirects'] as $row){
            $key=strtoupper((string)$row['source_package_id']);
            $redirects[$key]=$row;
        }
        $backends=$otherBackends;
        $backends[]=[
            'backend_id'=>'file',
            'priority'=>$fileBackendPriority,
            'entries'=>[],
            'redirects'=>$redirects,
        ];
        $ordered=Uedb5ZenPackageStoreBackendArbitrator::ordered($backends);
        $effective=[];$claimed=[];
        foreach($ordered as $backend){
            foreach($backend['redirects'] as $key=>$row){
                if(!is_array($row))throw new RuntimeException('Backend redirect must be an object.');
                $source=strtoupper(trim((string)($row['source_package_id']??$key)));
                if(!preg_match('/^[0-9A-F]{16}$/D',$source))
                    throw new RuntimeException('Backend redirect source ID must be 16-digit hex.');
                if(isset($claimed[$source]))continue;
                $claimed[$source]=true;
                $effective[]=$row;
            }
        }
        $file['package_redirects']=$effective;
        $file['package_store_backend_order']=array_map(static fn(array $backend):array=>[
            'backend_id'=>$backend['backend_id'],
            'priority'=>$backend['priority'],
        ],$ordered);
        // A declared backend list does not establish global runtime completeness.
        return $file;
    }
}
