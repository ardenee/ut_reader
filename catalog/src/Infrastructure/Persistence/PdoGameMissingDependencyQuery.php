<?php
/** UEDB5 read model for the per-game missing-dependency page. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

final class PdoGameMissingDependencyQuery
{
    private ?Uedb5ParityV5ReadService $v5 = null;

    public function __construct(private readonly PDO $db) {}

    public function officialBaseGamePackageNames(int $gameId): array
    {
        if ($gameId < 1) return [];
        $s = $this->db->prepare(
            'SELECT b.package_name,b.original_name,f.package_name source_package_name,f.original_name source_original_name '
            . 'FROM ue_base_game_files b LEFT JOIN ue_files f ON f.id=b.source_file_id AND f.game_id=b.game_id '
            . 'WHERE b.game_id=?'
        );
        $s->execute([$gameId]);
        $names = [];
        while (($r = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ([
                (string)($r['package_name'] ?? ''),
                self::filenameStem((string)($r['original_name'] ?? '')),
                (string)($r['source_package_name'] ?? ''),
                self::filenameStem((string)($r['source_original_name'] ?? '')),
            ] as $n) {
                $n = trim($n);
                if ($n === '') continue;
                $k = self::key($n);
                $names[$k] ??= $n;
            }
        }
        natcasesort($names);
        return array_values($names);
    }

    /** @return array{stale_files:int,stale_rows:int,stale_missing_rows:int,owners:list<array<string,mixed>>} */
    public function projectionHealth(int $gameId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if ($gameId < 1) {
            return ['stale_files'=>0,'stale_rows'=>0,'stale_missing_rows'=>0,'owners'=>[]];
        }
        $predicate = '(f.id IS NULL OR f.scan_status<>"verified")';
        $q = $this->db->prepare(
            'SELECT COUNT(DISTINCT s.file_id) stale_files,COUNT(*) stale_rows,'
            . 'COALESCE(SUM(s.missing_count),0) stale_missing_rows '
            . 'FROM ue_uedb5_dependency_packages s '
            . 'LEFT JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
            . 'WHERE s.game_id=? AND ' . $predicate
        );
        $q->execute([$gameId]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $owners = $this->db->prepare(
            'SELECT s.file_id,COALESCE(f.scan_status,"missing") scan_status,COUNT(*) summary_rows,'
            . 'COALESCE(SUM(s.missing_count),0) stale_missing_rows,'
            . '(SELECT COUNT(*) FROM ue_uedb5_dependency_edges e WHERE e.file_id=s.file_id) live_dependency_rows '
            . 'FROM ue_uedb5_dependency_packages s '
            . 'LEFT JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
            . 'WHERE s.game_id=? AND ' . $predicate . ' '
            . 'GROUP BY s.file_id,f.scan_status '
            . 'ORDER BY stale_missing_rows DESC,summary_rows DESC,s.file_id LIMIT ' . $limit
        );
        $owners->execute([$gameId]);
        return [
            'stale_files'=>(int)($r['stale_files'] ?? 0),
            'stale_rows'=>(int)($r['stale_rows'] ?? 0),
            'stale_missing_rows'=>(int)($r['stale_missing_rows'] ?? 0),
            'owners'=>$owners->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public function totals(int $gameId, ?array $packageNames): array
    {
        if ($gameId < 1 || $packageNames === []) {
            return ['missing_objects'=>0,'missing_packages'=>0,'files_with_missing'=>0];
        }
        [$w,$a] = $this->summaryWhere('s',$gameId,$packageNames);
        $s = $this->db->prepare(
            'SELECT COALESCE(SUM(s.missing_count),0) missing_objects,'
            . 'COUNT(DISTINCT s.required_package_name) missing_packages,'
            . 'COUNT(DISTINCT s.file_id) files_with_missing '
            . 'FROM ue_uedb5_dependency_packages s WHERE ' . $w
        );
        $s->execute($a);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'missing_objects'=>(int)($r['missing_objects'] ?? 0),
            'missing_packages'=>(int)($r['missing_packages'] ?? 0),
            'files_with_missing'=>(int)($r['files_with_missing'] ?? 0),
        ];
    }

    public function fileRows(int $gameId, ?array $packageNames, int $limit, int $offset): array
    {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        if ($gameId<1 || $packageNames===[]) return [];
        [$w,$a]=$this->summaryWhere('s',$gameId,$packageNames);
        $s=$this->db->prepare(
            'SELECT f.id file_id,f.package_name,f.original_name,g.name game_name,'
            . 'SUM(s.missing_count) missing_object_rows,COUNT(*) missing_package_count,'
            . 'GROUP_CONCAT(s.required_package_name ORDER BY s.required_package_name SEPARATOR ", ") missing_package_names '
            . 'FROM ue_uedb5_dependency_packages s '
            . 'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
            . 'JOIN ue_games g ON g.id=s.game_id '
            . 'WHERE '.$w.' GROUP BY f.id,f.package_name,f.original_name,g.name '
            . 'ORDER BY missing_object_rows DESC,missing_package_count DESC,f.id '
            . 'LIMIT '.$limit.' OFFSET '.$offset
        );
        $s->execute($a);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function packageRows(
        int $gameId, ?array $packageNames, int $limit, int $offset, string $search=''
    ): array {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        if ($gameId<1 || $packageNames===[]) return [];
        [$w,$a]=$this->summaryWhere('s',$gameId,$packageNames);
        $search=trim($search);
        if($search!==''){ $w.=' AND s.required_package_name LIKE ?'; $a[]='%'.$search.'%'; }
        $s=$this->db->prepare(
            'SELECT s.required_package_name required_package,SUM(s.missing_count) missing_object_rows,'
            . 'COUNT(DISTINCT s.file_id) requiring_file_count '
            . 'FROM ue_uedb5_dependency_packages s WHERE '.$w.' '
            . 'GROUP BY s.required_package_name '
            . 'ORDER BY missing_object_rows DESC,requiring_file_count DESC,s.required_package_name '
            . 'LIMIT '.$limit.' OFFSET '.$offset
        );
        $s->execute($a);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function objectTotal(
        int $gameId,string $packageName,?array $packageNames,string $search=''
    ): int {
        $rows=$this->missingDetails($gameId,[$packageName],$packageNames);
        $search=self::key($search);
        $unique=[];
        foreach($rows as $row){
            $path=(string)$row['required_object_path'];
            if($path==='' || ($search!=='' && !str_contains(self::key($path),$search))) continue;
            $unique[self::key($path)]=true;
        }
        return count($unique);
    }

    public function objectRows(
        int $gameId,string $packageName,?array $packageNames,int $limit,int $offset,string $search=''
    ): array {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        $rows=$this->missingDetails($gameId,[$packageName],$packageNames);
        $search=self::key($search);
        $agg=[];
        foreach($rows as $row){
            $path=(string)$row['required_object_path'];
            if($path==='' || ($search!=='' && !str_contains(self::key($path),$search))) continue;
            $k=self::key($path);
            $agg[$k] ??= [
                'required_object_term_id'=>0,
                'required_object_path'=>$path,
                'missing_import_count'=>0,
                'affected_file_ids'=>[],
            ];
            $agg[$k]['missing_import_count']++;
            $agg[$k]['affected_file_ids'][(int)$row['file_id']]=true;
        }
        $out=[];
        foreach($agg as $row){
            $row['affected_file_count']=count($row['affected_file_ids']);
            unset($row['affected_file_ids']);
            $out[]=$row;
        }
        usort($out,static fn(array $a,array $b):int =>
            ((int)$b['missing_import_count'] <=> (int)$a['missing_import_count'])
            ?: ((int)$b['affected_file_count'] <=> (int)$a['affected_file_count'])
            ?: strnatcasecmp((string)$a['required_object_path'],(string)$b['required_object_path'])
        );
        return array_slice($out,$offset,$limit);
    }

    public function objectFileRows(
        int $gameId,string $packageName,string $objectPath,?array $packageNames,int $limit,int $offset
    ): array {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        $objectPath=trim($objectPath);
        if($objectPath==='') return [];
        $rows=array_values(array_filter(
            $this->missingDetails($gameId,[$packageName],$packageNames),
            static fn(array $r):bool => strcasecmp((string)$r['required_object_path'],$objectPath)===0
        ));
        return array_slice($rows,$offset,$limit);
    }

    public function missingRows(int $gameId, ?array $packageNames, int $limit, int $offset): array
    {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        if($gameId<1 || $packageNames===[]) return [];
        return array_slice($this->missingDetails($gameId,$packageNames,$packageNames),$offset,$limit);
    }

    public function detailTotal(int $gameId,string $packageName,?array $packageNames): int
    {
        $packageName=trim($packageName);
        if($gameId<1 || $packageName==='' || !$this->scopeContains($packageName,$packageNames)) return 0;
        $s=$this->db->prepare(
            'SELECT COALESCE(SUM(missing_count),0) FROM ue_uedb5_dependency_packages '
            . 'WHERE game_id=? AND missing_count>0 AND required_package_name=?'
        );
        $s->execute([$gameId,$packageName]);
        return (int)($s->fetchColumn() ?: 0);
    }

    public function detailRows(
        int $gameId,string $packageName,?array $packageNames,int $limit,int $offset
    ): array {
        $limit=max(1,min(500,$limit)); $offset=max(0,$offset);
        return array_slice($this->missingDetails($gameId,[$packageName],$packageNames),$offset,$limit);
    }

    /**
     * @param list<string>|null $requestedPackages
     * @param list<string>|null $scope
     * @return list<array<string,mixed>>
     */
    private function missingDetails(int $gameId, ?array $requestedPackages, ?array $scope): array
    {
        if($gameId<1 || $requestedPackages===[]) return [];
        if($requestedPackages!==null){
            $requestedPackages=array_values(array_filter(
                array_map(static fn(mixed $v):string=>trim((string)$v),$requestedPackages),
                fn(string $v):bool=>$v!=='' && $this->scopeContains($v,$scope)
            ));
            if($requestedPackages===[]) return [];
        }

        $sql='SELECT e.file_id,e.source_index,p.required_package_name,'
            . 'f.package_name owner_package_name,f.original_name owner_original_name,'
            . 'rf.package_name provider_package_name,rf.original_name provider_original_name '
            . 'FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_uedb5_dependency_packages p ON p.file_id=e.file_id '
            . 'AND p.package_key_kind=e.required_package_key_kind AND p.package_key=e.required_package_key '
            . 'JOIN ue_files f ON f.id=e.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_files rf ON rf.id=e.resolved_file_id '
            . 'WHERE p.game_id=? AND e.outcome=0 AND e.source_kind=1';
        $args=[$gameId];
        if($requestedPackages!==null){
            $sql.=' AND p.required_package_name IN ('.implode(',',array_fill(0,count($requestedPackages),'?')).')';
            array_push($args,...$requestedPackages);
        }
        $sql.=' ORDER BY f.package_name,f.original_name,p.required_package_name,e.source_index';
        $s=$this->db->prepare($sql);
        $s->execute($args);
        $candidates=$s->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byFile=[];
        foreach($candidates as $row){
            $fid=(int)$row['file_id'];
            $byFile[$fid]['meta'][(int)$row['source_index']]=$row;
            $byFile[$fid]['indexes'][]=(int)$row['source_index'];
        }

        $config=function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot=is_array($config) ? trim((string)($config['storage_path']??'')) : '';
        $metadataReader=$storageRoot!=='' ? new Uedb5MetadataReader($storageRoot) : null;
        $out=[];
        foreach($byFile as $fileId=>$entry){
            $indexes=array_values(array_unique((array)$entry['indexes']));
            $details=$this->reader()->dependenciesAtIndexes($gameId,(int)$fileId,$indexes);
            $coverage=[];
            if($metadataReader!==null){
                try{
                    $snapshot=$metadataReader->snapshot($gameId,(int)$fileId);
                    foreach(Uedb5ClassicDependencyResolver::importCoverageRows($snapshot) as $row){
                        $coverage[(int)$row['import_index']]=$row;
                    }
                }catch(\Throwable){
                    $coverage=[];
                }finally{
                    $metadataReader->clearCache($gameId,(int)$fileId);
                }
            }
            foreach((array)$entry['meta'] as $index=>$meta){
                $detail=$details[(int)$index] ?? null;
                if(!is_array($detail) || (string)($detail['outcome']??'')!=='missing') continue;
                $importCoverage=$coverage[(int)$index]??[];
                $requiredPackage=(string)($detail['required_package']??$meta['required_package_name']??'');
                if($requiredPackage==='' && is_array($importCoverage)){
                    $requiredPackage=(string)($importCoverage['root_package']??'');
                }
                if($requestedPackages!==null && !$this->scopeContains($requiredPackage,$requestedPackages)) continue;
                $requiredObjectPath=(string)($detail['required_object_path']??'');
                if($requiredObjectPath==='' && is_array($importCoverage)){
                    $requiredObjectPath=(string)($importCoverage['full_path']??'');
                }
                $classPackage=(string)($detail['class_package']??'');
                $className=(string)($detail['class_name']??'');
                if(is_array($importCoverage)){
                    if($classPackage==='')$classPackage=(string)($importCoverage['class_package']??'');
                    if($className==='')$className=(string)($importCoverage['class_name']??'');
                }
                $out[]=[
                    'file_id'=>(int)$fileId,
                    'owner_package_name'=>(string)$meta['owner_package_name'],
                    'owner_original_name'=>(string)$meta['owner_original_name'],
                    'required_package'=>$requiredPackage,
                    'required_object_path'=>$requiredObjectPath,
                    'class_package'=>$classPackage,
                    'class_name'=>$className,
                    'import_index'=>(int)$index,
                    'resolved_file_id'=>$detail['resolved_file_id']??null,
                    'provider_package_name'=>(string)($meta['provider_package_name']??''),
                    'provider_original_name'=>(string)($meta['provider_original_name']??''),
                ];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function summaryWhere(string $alias,int $gameId,?array $packageNames):array
    {
        $w=$alias.'.game_id=? AND '.$alias.'.missing_count>0 '
            . 'AND EXISTS (SELECT 1 FROM ue_files summary_owner WHERE summary_owner.id='.$alias.'.file_id '
            . 'AND summary_owner.game_id='.$alias.'.game_id AND summary_owner.scan_status="verified")';
        $a=[$gameId];
        if($packageNames!==null){
            if($packageNames===[]) return ['1=0',[]];
            $w.=' AND '.$alias.'.required_package_name IN ('.implode(',',array_fill(0,count($packageNames),'?')).')';
            array_push($a,...$packageNames);
        }
        return [$w,$a];
    }

    private function scopeContains(string $packageName, ?array $packageNames): bool
    {
        if($packageNames===null) return true;
        $needle=self::key($packageName);
        foreach($packageNames as $candidate){
            $candidate=self::key((string)$candidate);
            if($candidate!=='' && hash_equals($candidate,$needle)) return true;
        }
        return false;
    }

    private function reader(): Uedb5ParityV5ReadService
    {
        if($this->v5!==null) return $this->v5;
        $config=function_exists('catalog_config') ? \catalog_config() : [];
        if(!is_array($config)) $config=[];
        return $this->v5=new Uedb5ParityV5ReadService($this->db,$config);
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value),'UTF-8')
            : strtolower(trim($value));
    }

    private static function filenameStem(string $filename):string
    {
        $filename=trim(str_replace('\\','/',$filename));
        $slash=strrpos($filename,'/');
        if($slash!==false) $filename=substr($filename,$slash+1);
        $dot=strrpos($filename,'.');
        return $dot!==false?substr($filename,0,$dot):$filename;
    }
}
