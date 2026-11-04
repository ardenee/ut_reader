<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;

/**
 * SQL-only source-backed labels for cooked UE5 ScriptImport references.
 *
 * The index is generated from retained global.utoc during a controlled
 * import/backfill. Browser requests never hash, read, or decode that container.
 * Cooked object identity does not assert runtime loading/registration.
 */
final class CatalogZenCookedScriptLabelLookup
{
    /** @param list<string> $rawIndices @return array<string,string> */
    public static function labels(PDO $db, string $storageRoot, int $gameId, array $rawIndices): array
    {
        $details=self::describe($db,$storageRoot,$gameId,$rawIndices);
        $labels=[];
        foreach($details as $index=>$detail)$labels[$index]=$detail['path'];
        return $labels;
    }

    /**
     * @param list<string> $rawIndices
     * @return array<string,array<string,string>>
     */
    public static function describe(PDO $db, string $storageRoot, int $gameId, array $rawIndices): array
    {
        $wanted=[];
        foreach($rawIndices as $value){
            $raw=strtoupper((string)$value);
            if(preg_match('/^[0-9A-F]{16}$/D',$raw)===1)$wanted[$raw]=true;
        }
        if($gameId<1||$wanted===[])return [];
        // Do not guess mount precedence if multiple ScriptObjects globals exist.
        $q=$db->prepare('SELECT id,utoc_sha256 FROM ue_iostore_containers '
            . 'WHERE game_id=? AND LOWER(name)="global.utoc" AND status="ready" ORDER BY id LIMIT 2');
        $q->execute([$gameId]);
        $containers=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($containers)!==1)return [];
        $container=$containers[0];
        $sha=strtolower((string)$container['utoc_sha256']);
        if(preg_match('/^[0-9a-f]{64}$/D',$sha)!==1)return [];
        $containerId=(int)$container['id'];
        $result=[];
        foreach(array_chunk(array_keys($wanted),200) as $batch){
            $in=implode(',',array_fill(0,count($batch),'?'));
            $sql='SELECT HEX(o.object_index) object_index,o.object_name,o.object_path,'
                .'HEX(o.outer_index) outer_index,HEX(o.cdo_class_index) cdo_class_index,'
                .'IFNULL(oo.object_path,"") outer_path,IFNULL(cc.object_path,"") cdo_class_path '
                .'FROM ue_iostore_zen_script_objects o '
                .'LEFT JOIN ue_iostore_zen_script_objects oo ON oo.container_id=o.container_id '
                .'AND oo.object_index=o.outer_index AND oo.toc_sha256=o.toc_sha256 '
                .'LEFT JOIN ue_iostore_zen_script_objects cc ON cc.container_id=o.container_id '
                .'AND cc.object_index=o.cdo_class_index AND cc.toc_sha256=o.toc_sha256 '
                .'WHERE o.container_id=? AND o.toc_sha256=? '
                .'AND o.object_index IN ('.$in.')';
            $params=[$containerId,hex2bin($sha)];
            foreach($batch as $hex)$params[]=hex2bin($hex);
            $statement=$db->prepare($sql);
            $statement->execute($params);
            foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){
                $index=strtoupper((string)$row['object_index']);
                $path=(string)$row['object_path'];
                $dot=strpos($path,'.');
                $module=$dot===false?$path:substr($path,0,$dot);
                if(!str_starts_with($module,'/Script/'))$module='';
                $result[$index]=[
                    'path'=>$path,
                    'name'=>(string)$row['object_name'],
                    'module'=>$module,
                    'outer_index'=>strtoupper((string)$row['outer_index']),
                    'outer_path'=>(string)$row['outer_path'],
                    'cdo_class_index'=>strtoupper((string)$row['cdo_class_index']),
                    'cdo_class_path'=>(string)$row['cdo_class_path'],
                    'source'=>'cooked_script_objects_sql_projection',
                ];
            }
        }
        return $result;
    }
}
