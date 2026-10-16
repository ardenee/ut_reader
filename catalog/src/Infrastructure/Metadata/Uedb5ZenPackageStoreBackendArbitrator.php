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
            }
            $seen[$id]=true;$backend['_ordinal']=$i;$rows[]=$backend;
        }
        usort($rows,static fn(array $a,array $b):int=>
            ($b['priority']<=>$a['priority'])?:($b['_ordinal']<=>$a['_ordinal']));
        return $rows;
    }
}
