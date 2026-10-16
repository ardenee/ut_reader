<?php
/** UE5 FPackageStore backend arbitration over explicitly evidenced backend results. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use RuntimeException;
final class Uedb5ZenPackageStoreBackendArbitrator
{
    /** @param list<array<string,mixed>> $backends */
    public static function entry(array $backends, string $packageId): array
    {
        foreach(self::ordered($backends) as $backend){
            $row=$backend['entries'][strtoupper($packageId)]??null;
            if($row===null)continue;
            if(!is_array($row))throw new RuntimeException('Package-store entry must be an object.');
            $status=(string)($row['status']??'');
            if(!in_array($status,['None','Missing','NotInstalled','Ok','Pending'],true))
                throw new RuntimeException('Unsupported package-store backend status: '.$status);
            if($status==='None'||$status==='Missing')continue;
            return ['status'=>$status,'backend_id'=>$backend['backend_id'],'entry'=>$row];
        }
        return ['status'=>'Missing','backend_id'=>null,'entry'=>null];
    }
    /**
     * The selector and dependency publisher must consume one identical
     * source-evidenced backend list. Reject contradictory input paths rather
     * than accepting an Ok provider on one path and Pending on another.
     *
     * @param array<string,mixed> $runtimeContext
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>|null
     */
    public static function evidencedBackends(array $runtimeContext, array $options): ?array
    {
        $nested=$runtimeContext['package_store_backend_results']??null;
        $explicit=$options['zen_package_store_backend_results']??null;
        foreach([$nested,$explicit] as $source){
            if($source!==null && (!is_array($source)||!array_is_list($source))){
                throw new RuntimeException('Zen package-store backend results must be an explicit list.');
            }
        }
        if($nested!==null && $explicit!==null
            && self::ordered($nested)!==self::ordered($explicit)){
            throw new RuntimeException(
                'Conflicting authoritative Zen backend evidence between runtime context and selector options.'
            );
        }
        return $explicit??$nested;
    }
    /** @param list<array<string,mixed>> $backends */
    public static function redirect(array $backends, string $packageId): ?array
    {
        foreach(self::ordered($backends) as $backend){
            $row=$backend['redirects'][strtoupper($packageId)]??null;
            if($row!==null){
                if(!is_array($row))throw new RuntimeException('Backend redirect must be an object.');
                return ['backend_id'=>$backend['backend_id'],'redirect'=>$row];
            }
        }
        return null;
    }
    /** @param list<array<string,mixed>> $backends @return list<array<string,mixed>> */
    public static function ordered(array $backends): array
    {
        $seen=[];$rows=[];
        foreach($backends as $i=>$backend){
            if(!is_array($backend))throw new RuntimeException('Backend descriptor must be an object.');
            $id=trim((string)($backend['backend_id']??''));
            if($id===''||isset($seen[$id]))throw new RuntimeException('Backend IDs must be unique and nonempty.');
            if(!isset($backend['priority'])||!is_int($backend['priority']))
                throw new RuntimeException('Backend priority must be an explicit integer.');
            foreach(['entries','redirects'] as $field){
                if(!isset($backend[$field])||!is_array($backend[$field]))
                    throw new RuntimeException('Backend '.$field.' must be explicit.');
                // Epic keys its store by binary FPackageId, not a case-sensitive
                // hexadecimal string. Normalize textual keys before arbitration.
                $normalized=[];
                foreach($backend[$field] as $rawPackageId=>$value){
                    $canonical=strtoupper((string)$rawPackageId);
                    if(!preg_match('/^[0-9A-F]{16}$/D',$canonical))
                        throw new RuntimeException('Backend '.$field.' requires 16-digit hexadecimal FPackageId keys.');
                    if(array_key_exists($canonical,$normalized))
                        throw new RuntimeException('Duplicate FPackageId in backend '.$field.' after normalization: '.$canonical);
                    $normalized[$canonical]=$value;
                }
                $backend[$field]=$normalized;
            }
            $seen[$id]=true;$backend['_ordinal']=$i;$rows[]=$backend;
        }
        usort($rows,static fn(array $a,array $b):int=>
            ($b['priority']<=>$a['priority'])?:($b['_ordinal']<=>$a['_ordinal']));
        return $rows;
    }
}
