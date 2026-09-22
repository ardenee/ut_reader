<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides bounded Names/Imports/Exports paging and lookups from authoritative format-2 metadata.
 * Why: Verified package examination must never fall back to retired SQL metadata tables.
 * Role: Infrastructure compact metadata query used by file-examine pages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class PdoPackageTablePageQuery
{
    public const DEFAULT_PAGE_SIZE = 250;

    /** @return array{index_column:string,count_column:string,columns:list<string>} */
    public static function definition(string $table): array
    {
        return match (strtolower(trim($table))) {
            'imports' => [
                'index_column' => 'import_index',
                'count_column' => 'import_count',
                'columns' => ['id','import_index','class_package','class_name','object_name','outer_index','full_path','root_package','relative_object_path','is_common'],
            ],
            'exports' => [
                'index_column' => 'export_index',
                'count_column' => 'export_count',
                'columns' => ['id','export_index','class_name','object_name','outer_index','local_path','full_path','object_flags','serial_size','serial_offset'],
            ],
            default => [
                'index_column' => 'name_index',
                'count_column' => 'name_count',
                'columns' => ['id','name_index','name_text','flags'],
            ],
        };
    }

    public static function normalizeTable(string $table): string
    {
        $table = strtolower(trim($table));
        return in_array($table, ['names', 'imports', 'exports'], true) ? $table : 'names';
    }

    public static function normalizePageSize(int $size): int
    {
        return in_array($size, [100, 250, 500, 1000], true) ? $size : self::DEFAULT_PAGE_SIZE;
    }

    public static function targetIndex(string $target, string $table): ?int
    {
        $table = self::normalizeTable($table);
        $prefix = match ($table) {
            'imports' => 'import-',
            'exports' => 'export-',
            default => 'name-',
        };
        if (!str_starts_with($target, $prefix)) {
            return null;
        }
        $value = substr($target, strlen($prefix));
        return preg_match('/^\d+$/', $value) === 1 ? (int)$value : null;
    }

    public static function pageForIndex(int $index, int $pageSize): int
    {
        return max(1, intdiv(max(0, $index), self::normalizePageSize($pageSize)) + 1);
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int,page_size:int,start:int,end:int} */
    public static function fetchPage(PDO $db, array $file, string $table, int $page, int $pageSize): array
    {
        $table = self::normalizeTable($table);
        $definition = self::definition($table);
        $pageSize = self::normalizePageSize($pageSize);
        $total = max(0, (int)($file[$definition['count_column']] ?? 0));
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = max(1, min($page, $pages));
        $start = ($page - 1) * $pageSize;

        if ($table === 'names') {
            $sql = 'SELECT n.name_index+1 id,n.name_index,CAST(t.value_prefix AS CHAR CHARACTER SET utf8mb4) name_text,NULL flags '
                . 'FROM ue_name_lookup n JOIN ue_terms t ON t.id=n.name_term_id '
                . 'WHERE n.file_id=? ORDER BY n.name_index LIMIT ? OFFSET ?';
        } elseif ($table === 'imports') {
            $sql = 'SELECT d.import_index+1 id,d.import_index,'
                . 'CAST(cp.value_prefix AS CHAR CHARACTER SET utf8mb4) class_package,'
                . 'CAST(cn.value_prefix AS CHAR CHARACTER SET utf8mb4) class_name,'
                . 'CAST(io.value_prefix AS CHAR CHARACTER SET utf8mb4) object_name,'
                . '0 outer_index,'
                . 'CAST(ro.value_prefix AS CHAR CHARACTER SET utf8mb4) full_path,'
                . 'CAST(rp.value_prefix AS CHAR CHARACTER SET utf8mb4) root_package,'
                . 'CAST(ro.value_prefix AS CHAR CHARACTER SET utf8mb4) relative_object_path,0 is_common '
                . 'FROM ue_dependency_links d '
                . 'LEFT JOIN ue_terms cp ON cp.id=d.import_class_package_term_id '
                . 'LEFT JOIN ue_terms cn ON cn.id=d.import_class_name_term_id '
                . 'LEFT JOIN ue_terms io ON io.id=d.import_object_term_id '
                . 'LEFT JOIN ue_terms rp ON rp.id=d.required_package_term_id '
                . 'LEFT JOIN ue_terms ro ON ro.id=d.required_object_term_id '
                . 'WHERE d.file_id=? ORDER BY d.import_index LIMIT ? OFFSET ?';
        } else {
            $sql = 'SELECT e.export_index+1 id,e.export_index,'
                . 'CAST(c.value_prefix AS CHAR CHARACTER SET utf8mb4) class_name,'
                . 'CAST(o.value_prefix AS CHAR CHARACTER SET utf8mb4) object_name,'
                . '0 outer_index,CAST(lp.value_prefix AS CHAR CHARACTER SET utf8mb4) local_path,'
                . 'CAST(lp.value_prefix AS CHAR CHARACTER SET utf8mb4) full_path,'
                . 'NULL object_flags,NULL serial_size,NULL serial_offset '
                . 'FROM ue_export_lookup e '
                . 'JOIN ue_terms o ON o.id=e.object_term_id '
                . 'LEFT JOIN ue_terms c ON c.id=e.class_term_id '
                . 'LEFT JOIN ue_terms lp ON lp.id=e.local_path_term_id '
                . 'WHERE e.file_id=? ORDER BY e.export_index LIMIT ? OFFSET ?';
        }
        $statement = $db->prepare($sql);
        $statement->bindValue(1, (int)$file['id'], PDO::PARAM_INT);
        $statement->bindValue(2, $pageSize, PDO::PARAM_INT);
        $statement->bindValue(3, $start, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,'page_size'=>$pageSize,
            'start'=>$total > 0 ? $start + 1 : 0,'end'=>min($total,$start + count($rows))];
    }

    /** @param list<string> $values @return array<string,int> */
    public static function nameLookup(PDO $db, int $fileId, array $values): array
    {
        $values = self::uniqueValues($values);
        if ($values === []) return [];
        $out = [];
        foreach (array_chunk($values, 200) as $chunk) {
            $predicates=[]; $args=[$fileId];
            foreach ($chunk as $value) { $predicates[]='(t.value_hash=? AND t.value_length=?)'; $args[]=md5($value,true); $args[]=strlen($value); }
            $st=$db->prepare('SELECT n.name_index,t.value_hash,t.value_length FROM ue_name_lookup n JOIN ue_terms t ON t.id=n.name_term_id WHERE n.file_id=? AND ('.implode(' OR ',$predicates).')');
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[bin2hex((string)$row['value_hash']).':'.(int)$row['value_length']] = (int)$row['name_index'];
        }
        $resolved=[];
        foreach($values as $value){$k=md5($value).':'.strlen($value); if(isset($out[$k])) $resolved[mb_strtolower($value,'UTF-8')]=$out[$k];}
        return $resolved;
    }

    /** @param list<string> $names @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}> */
    public static function nameUsage(PDO $db, int $fileId, array $names): array
    {
        $usage=[]; foreach(self::uniqueValues($names) as $name) $usage[mb_strtolower($name,'UTF-8')]=['imports_count'=>0,'imports_target'=>'','exports_count'=>0,'exports_target'=>''];
        if($usage===[]) return [];
        foreach($names as $name){
            $key=mb_strtolower(trim($name),'UTF-8'); if(!isset($usage[$key])) continue;
            $hash=md5(trim($name),true); $len=strlen(trim($name));
            $st=$db->prepare('SELECT d.import_index FROM ue_dependency_links d LEFT JOIN ue_terms a ON a.id=d.import_object_term_id LEFT JOIN ue_terms b ON b.id=d.import_class_name_term_id LEFT JOIN ue_terms c ON c.id=d.import_class_package_term_id WHERE d.file_id=? AND ((a.value_hash=? AND a.value_length=?) OR (b.value_hash=? AND b.value_length=?) OR (c.value_hash=? AND c.value_length=?)) ORDER BY d.import_index LIMIT 1001');
            $st->execute([$fileId,$hash,$len,$hash,$len,$hash,$len]); $ir=$st->fetchAll(PDO::FETCH_COLUMN) ?: []; $usage[$key]['imports_count']=count($ir); if($ir) $usage[$key]['imports_target']='import-'.(int)$ir[0];
            $st=$db->prepare('SELECT e.export_index FROM ue_export_lookup e LEFT JOIN ue_terms a ON a.id=e.object_term_id LEFT JOIN ue_terms b ON b.id=e.class_term_id WHERE e.file_id=? AND ((a.value_hash=? AND a.value_length=?) OR (b.value_hash=? AND b.value_length=?)) ORDER BY e.export_index LIMIT 1001');
            $st->execute([$fileId,$hash,$len,$hash,$len]); $er=$st->fetchAll(PDO::FETCH_COLUMN) ?: []; $usage[$key]['exports_count']=count($er); if($er) $usage[$key]['exports_target']='export-'.(int)$er[0];
        }
        return $usage;
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    public static function dependencyMap(PDO $db, int $fileId, array $imports): array
    {
        $indexes=array_values(array_unique(array_map(static fn(array $r):int=>(int)($r['import_index']??-1),$imports)));
        if($indexes===[]) return [];
        $ph=implode(',',array_fill(0,count($indexes),'?')); $args=array_merge([$fileId],$indexes);
        $st=$db->prepare('SELECT import_index,status FROM ue_dependency_links WHERE file_id=? AND import_index IN ('.$ph.')'); $st->execute($args);
        $labels=[0=>'missing',1=>'resolved',2=>'package_only',3=>'common']; $out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r){$idx=(int)$r['import_index'];$out[$idx+1]=['status'=>$labels[(int)$r['status']]??'unknown','required_object_path'=>''];}
        return $out;
    }

    /** @param list<string> $values @return list<string> */
    private static function uniqueValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                // PHP converts numeric-string array keys (for example "123") to
                // integers. Preserve the original string as the value so a
                // numeric Unreal FName cannot become int 123 before trim()/lookup.
                $out['s:' . $value] = $value;
            }
        }
        return array_values($out);
    }
}
