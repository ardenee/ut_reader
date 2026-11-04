<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/**
 * One-time source-backed projection of the cooked ScriptObjects chunk.
 * This is presentation metadata, not proof of runtime registration or mount.
 */
final class PdoZenScriptObjectProjector
{
    public function __construct(private readonly PDO $db) {}

    public function publish(int $containerId, Uedb5IoStoreTocReader $toc): array
    {
        if ($containerId < 1 || $this->db->inTransaction()) {
            throw new RuntimeException('ScriptObjects projection requires a positive container ID and its own transaction.');
        }
        $q=$this->db->prepare('SELECT utoc_sha256,name,status FROM ue_iostore_containers WHERE id=?');
        $q->execute([$containerId]);
        $container=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($container) || (string)$container['status']!=='ready'
            || strtolower((string)$container['name'])!=='global.utoc'
            || !hash_equals(strtolower((string)$container['utoc_sha256']),strtolower($toc->tocSha256()))) {
            throw new RuntimeException('Cooked ScriptObjects projection needs the exact retained ready global.utoc.');
        }
        $sha=hex2bin(strtolower((string)$container['utoc_sha256']));
        if(!is_string($sha)||strlen($sha)!==32)throw new RuntimeException('Invalid source hash.');
        $source=Uedb5ZenScriptObjectsReader::fromToc($toc);
        if(empty($source['present']) || !is_array($source['objects'])){
            throw new RuntimeException('Selected global.utoc contains no cooked ScriptObjects records.');
        }
        $objects=$source['objects'];
        if(count($objects)!==(int)$source['count']){
            throw new RuntimeException('Cooked ScriptObjects count mismatch.');
        }
        $rows=[];
        foreach($objects as $index=>$record){
            $full=self::scriptPath((string)$index,$objects);
            if($full===null)throw new RuntimeException('Malformed ScriptObjects outer chain at '.$index);
            foreach(['outer_index','cdo_class_index'] as $field){
                if(!is_string($record[$field]??null)
                    || preg_match('/^[0-9A-F]{16}$/D',(string)$record[$field])!==1) {
                    throw new RuntimeException('Malformed script reference at '.$index);
                }
            }
            $id=hex2bin((string)$index);
            if($id===false)throw new RuntimeException('Malformed cooked script index.');
            $rows[]=[$containerId,$id,$sha,(string)$record['name'],$full,
                hex2bin((string)$record['outer_index']),
                hex2bin((string)$record['cdo_class_index'])];
        }
        $this->db->beginTransaction();
        try {
            $d=$this->db->prepare('DELETE FROM ue_iostore_zen_script_objects WHERE container_id=?');
            $d->execute([$containerId]);
            foreach(array_chunk($rows,100) as $batch){
                $values=implode(',',array_fill(0,count($batch),'(?,?,?,?,?,?,?)'));
                $insert=$this->db->prepare('INSERT INTO ue_iostore_zen_script_objects '
                    . '(container_id,object_index,toc_sha256,object_name,object_path,outer_index,cdo_class_index) '
                    . 'VALUES '.$values);
                $params=[];
                foreach($batch as $row)array_push($params,...$row);
                $insert->execute($params);
            }
            $this->db->commit();
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        return ['container_id'=>$containerId,'source_sha256'=>bin2hex($sha),'indexed_objects'=>count($rows)];
    }

    /** @param array<string,array<string,mixed>> $objects */
    private static function scriptPath(string $index,array $objects):?string
    {
        $parts=[];$visited=[];
        for($i=0;$i<128;$i++){
            if(isset($visited[$index])||!isset($objects[$index]))return null;
            $visited[$index]=true;
            $record=$objects[$index];
            $name=(string)($record['name']??'');
            if($name==='')return null;
            $parts[]=$name;
            $outer=(string)($record['outer_index']??'');
            if($outer==='FFFFFFFFFFFFFFFF')return implode('.',array_reverse($parts));
            $index=$outer;
        }
        return null;
    }
}
