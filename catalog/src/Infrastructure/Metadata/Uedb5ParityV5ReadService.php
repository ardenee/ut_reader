<?php
/** Read-only V5 behavioural view used by pre-cutover parity audits. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class Uedb5ParityV5ReadService
{
    private Uedb5MetadataReader $reader;
    /** @var array<int,array<string,mixed>> */
    private array $snapshotCache=[];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db,array $config)
    {
        $storage=rtrim((string)($config['storage_path']??''),"\\/");
        if($storage==='')throw new RuntimeException('Catalog storage_path is required for parity audit.');
        $this->reader=new Uedb5MetadataReader($storage);
    }

    /** @return array<string,mixed> */
    public function snapshot(int $gameId,int $fileId):array
    {
        if(!isset($this->snapshotCache[$fileId])){
            $this->snapshotCache[$fileId]=$this->reader->snapshot($gameId,$fileId);
        }
        return $this->snapshotCache[$fileId];
    }
    /** @return list<array<string,mixed>> */
    public function dependencies(int $gameId,int $fileId):array
    {
        $manifest=$this->reader->manifest($gameId,$fileId);
        $counts=(array)($manifest['counts']??[]);
        if(!array_key_exists('dependency_results',$counts))return[];
        $policy=(string)($manifest['source_policy']??'');$rows=[];
        foreach($this->reader->scan($gameId,$fileId,'dependency_results') as $raw){
            $row=(array)$raw;$selected=(array)($row['selected_provider_object']??[]);
            $requiredPackage=$row['required_package_identity']??null;
            $requiredObject=$row['required_object_identity']??null;
            $rows[]=[
                'source_index'=>(int)($row['source_index']??-1),'source_section'=>(string)($row['source_section']??''),
                'outcome'=>(string)($row['outcome']??''),
                'required_package'=>is_array($requiredPackage)?(string)($requiredPackage['value']??''):(string)($row['required_package_id']??''),
                'required_object'=>is_array($requiredObject)?(string)($requiredObject['object_name']??''):(string)$requiredObject,
                'required_object_path'=>is_array($requiredObject)?(string)($requiredObject['object_path']??''):(string)($row['required_object_path']??''),
                'class_package'=>is_array($requiredObject)?(string)($requiredObject['class_package']??''):'',
                'class_name'=>is_array($requiredObject)?(string)($requiredObject['class_name']??''):'',
                'resolved_file_id'=>isset($row['selected_provider_file_id'])?(int)$row['selected_provider_file_id']:null,
                'resolved_object_index'=>array_key_exists('export_index',$selected)?(int)$selected['export_index']:(array_key_exists('cell_export_index',$selected)?(int)$selected['cell_export_index']:null),
                'reason_code'=>(string)($row['reason_code']??''),'source_policy'=>(string)($row['source_policy']??$policy),
                'resolver_detail'=>(array)($row['resolver_detail']??[]),'hard'=>(bool)($row['hard']??false),
            ];
        }
        usort($rows,static fn(array$a,array$b):int=>$a['source_index']<=>$b['source_index']);return$rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function dependenciesByIndex(int $gameId,int $fileId):array
    {
        $out=[];foreach($this->dependencies($gameId,$fileId) as $row)$out[(int)$row['source_index']]=$row;return$out;
    }
    /** @return list<int> */
    public function requiresFileIds(int $gameId,int $fileId):array
    {
        $ids=[];foreach($this->dependencies($gameId,$fileId) as $row){
            $id=$row['resolved_file_id'];if($id===null||$id===$fileId||($row['outcome']??'')==='common')continue;
            $ids[(int)$id]=true;
        }
        $out=array_map('intval',array_keys($ids));sort($out,SORT_NUMERIC);return$out;
    }

    /** @return list<int> */
    public function requiredByFileIds(int $gameId,int $targetFileId):array
    {
        $s=$this->db->prepare(
            'SELECT DISTINCT e.file_id FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files f ON f.id=e.file_id AND f.game_id=? AND f.scan_status="verified" '
            . 'WHERE e.resolved_file_id=? AND e.file_id<>? ORDER BY e.file_id'
        );
        $s->execute([$gameId,$targetFileId,$targetFileId]);
        return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)?:[]);
    }

    /** @return list<int> */
    public function exactMetadataSearch(int $gameId,string $query,array $fields,int $limit=5000):array
    {
        $query=trim($query);if($query==='')return[];$limit=max(1,min(20000,$limit));
        $needle=CatalogUnrealIdentityHash::nameKey($this->candidateNeedle($query));
        $hash=md5($needle,true);$finger=hash('sha256',$needle,true);$ids=[];
        if(in_array('names',$fields,true)){
            $this->collectIds($ids,
                'SELECT n.file_id FROM ue_uedb5_name_candidates n JOIN ue_files f ON f.id=n.file_id '
                .'WHERE f.game_id=? AND f.scan_status="verified" AND n.name_key_hash=? AND n.name_key_length=? '
                .'AND n.name_key_fingerprint=? ORDER BY n.file_id LIMIT '.$limit,
                [$gameId,$hash,strlen($needle),$finger]);
        }
        if(in_array('exports',$fields,true)){
            $this->collectIds($ids,
                'SELECT DISTINCT o.file_id FROM ue_uedb5_object_candidates o JOIN ue_files f ON f.id=o.file_id '
                .'WHERE f.game_id=? AND f.scan_status="verified" AND o.object_name_hash=? AND o.object_name_length=? '
                .'ORDER BY o.file_id LIMIT '.$limit,
                [$gameId,$hash,strlen($needle)]);
        }
        if(in_array('imports',$fields,true)){
            $this->collectIds($ids,
                'SELECT DISTINCT e.file_id FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
                .'WHERE f.game_id=? AND f.scan_status="verified" AND ((e.required_object_key_kind=1 AND e.required_object_key=?) '
                .'OR (e.required_package_key_kind=1 AND e.required_package_key=?)) ORDER BY e.file_id LIMIT '.$limit,
                [$gameId,$hash,md5(CatalogUnrealIdentityHash::nameKey($query),true)]);
        }
        $out=[];$candidateIds=array_map('intval',array_keys($ids));sort($candidateIds,SORT_NUMERIC);
        foreach(array_slice($candidateIds,0,$limit) as $fileId){
            if($this->snapshotMatches($this->snapshot($gameId,$fileId),$query,$fields))$out[]=$fileId;
        }
        return $out;
    }

    /** @param array<int,bool> $ids @param list<mixed> $args */
    private function collectIds(array &$ids,string $sql,array $args):void
    {
        $s=$this->db->prepare($sql);$s->execute($args);
        foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $fileId)$ids[(int)$fileId]=true;
    }

    private function candidateNeedle(string $query):string
    {
        $query=trim($query,'. ');$dot=strrpos($query,'.');
        return $dot===false?$query:substr($query,$dot+1);
    }

    private function snapshotMatches(array $snapshot,string $query,array $fields):bool
    {
        $needle=CatalogUnrealIdentityHash::nameKey($query);$sections=(array)($snapshot['sections']??[]);
        if(in_array('names',$fields,true)){
            foreach((array)($sections['names']??$sections['name_map']??[]) as $row){
                $text=$this->rowText((array)$row);if($text!==''&&CatalogUnrealIdentityHash::nameKey($text)===$needle)return true;
            }
        }
        if(in_array('imports',$fields,true)){
            foreach(array_merge((array)($sections['imports']??[]),(array)($sections['cell_imports']??[])) as $row){
                $row=(array)$row;$text=$this->fnameText($row['object_name']??$row['objectName']??null);
                if($text!==''&&CatalogUnrealIdentityHash::nameKey($text)===$needle)return true;
            }
            foreach((array)($sections['dependency_results']??[]) as $row){
                $row=(array)$row;$pkg=$row['required_package_identity']??null;$obj=$row['required_object_identity']??null;
                $values=[];
                if(is_array($pkg))$values[]=(string)($pkg['value']??'');else $values[]=(string)($row['required_package_id']??'');
                if(is_array($obj)){
                    $values[]=(string)($obj['object_name']??'');
                    $values[]=(string)($obj['object_path']??'');
                }else $values[]=(string)$obj;
                $values[]=(string)($row['required_object_path']??'');
                foreach($values as $value){
                    if($value!==''&&(CatalogUnrealIdentityHash::nameKey($value)===$needle||CatalogUnrealIdentityHash::pathKey($value)===CatalogUnrealIdentityHash::pathKey($query)))return true;
                }
            }
        }
        if(in_array('exports',$fields,true)){
            $package=trim((string)($snapshot['file']['package_name']??''));
            foreach((array)($sections['exports']??[]) as $fallback=>$row){
                $row=(array)$row;$text=$this->fnameText($row['object_name']??$row['objectName']??null);
                if($text!==''&&CatalogUnrealIdentityHash::nameKey($text)===$needle)return true;
                $local=$this->exportLocalPath($snapshot,(int)($row['index']??$fallback));
                if($local!==''&&CatalogUnrealIdentityHash::pathKey($local)===CatalogUnrealIdentityHash::pathKey($query))return true;
                if($package!==''&&$local!==''&&CatalogUnrealIdentityHash::pathKey($package.'.'.$local)===CatalogUnrealIdentityHash::pathKey($query))return true;
            }
        }
        return false;
    }

    private function rowText(array $row):string
    {
        return trim((string)($row['text']??$row['name']??''));
    }

    private function fnameText(mixed $value):string
    {
        if(is_array($value))return trim((string)($value['text']??$value['base_text']??''));
        return trim((string)$value);
    }

    private function exportLocalPath(array $snapshot,int $exportIndex):string
    {
        $exports=array_values((array)($snapshot['sections']['exports']??[]));
        $imports=array_values((array)($snapshot['sections']['imports']??[]));
        if(!isset($exports[$exportIndex]))return'';
        $parts=[];$seen=[];$packageIndex=$exportIndex+1;
        while($packageIndex!==0&&count($parts)<128){
            if(isset($seen[$packageIndex]))return'';$seen[$packageIndex]=true;
            if($packageIndex>0){
                $idx=$packageIndex-1;if(!isset($exports[$idx]))return'';$row=(array)$exports[$idx];
                $parts[]=$this->fnameText($row['object_name']??$row['objectName']??null);
                $packageIndex=(int)($row['outer_index']??$row['outerIndex']??0);
            }else{
                $idx=(-$packageIndex)-1;if(!isset($imports[$idx]))return'';$row=(array)$imports[$idx];
                $parts[]=$this->fnameText($row['object_name']??$row['objectName']??null);
                $packageIndex=(int)($row['outer_index']??$row['outerIndex']??0);
            }
        }
        $parts=array_values(array_filter(array_reverse($parts),static fn(string $v):bool=>$v!==''));
        return implode('.',$parts);
    }
}
