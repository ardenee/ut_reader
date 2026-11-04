<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;

/**
 * Catalogue-existence matching, deliberately separate from Epic's runtime
 * PackageStore/loader choice. Never infer a unique mounted provider from
 * multiple matching physical files.
 *
 * ScriptImport identity: exact typed 64-bit FPackageObjectIndex in the retained
 * global.utoc ScriptObjects table, whose TOC SHA is pinned by the SQL lookup.
 * PackageImport identity: (FPackageId, PublicExportHash, ordinary export kind).
 * ImportedPackage identity: FPackageId alone (package presence only).
 *
 * All data comes from verified, indexed MySQL records; never reads containers.
 */
final class CatalogZenStaticDependencyLookup
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @param list<array<string,mixed>> $imports - decoded zen ImportMap
     * @return array<int,array<string,mixed>> import index => result
     */
    public function forImports(int $gameId, array $imports): array
    {
        $refs = [];
        foreach ($imports as $i => $row) {
            $index = (int)($row['import_index'] ?? $row['index'] ?? $i);
            $type = (string)($row['type'] ?? '');
            if ($type === 'ScriptImport') {
                $refs[] = [
                    'lookup_id'=>$index, 'type'=>'script',
                    'object_index'=>strtoupper((string)($row['raw_type_and_id'] ?? '')),
                ];
            } elseif ($type === 'PackageImport') {
                $refs[] = [
                    'lookup_id'=>$index, 'type'=>'export',
                    'package_id'=>strtoupper((string)($row['provider_package_id'] ?? '')),
                    'export_hash'=>strtoupper((string)($row['provider_public_export_hash'] ?? '')),
                ];
            }
        }
        return $this->resolve($gameId,$refs);
    }

    /**
     * @param list<array<string,mixed>> $importedPackageRows - decoded ImportedPackageId references
     * @return array<int,array<string,mixed>> source row index => catalogue result
     */
    public function forImportedPackages(int $gameId, array $importedPackageRows): array
    {
        $refs=[];
        foreach ($importedPackageRows as $index=>$entry) {
            $refs[]=[
                'lookup_id'=>$index,
                'type'=>'package',
                'package_id'=>strtoupper((string)($entry['package_id']??'')),
            ];
        }
        return $this->resolve($gameId,$refs);
    }

    /**
     * @param list<array<string,mixed>> $edges - source-backed unresolved edges
     * @return array<string,array<string,mixed>> "file_id:source_kind:source_index" => result
     */
    public function forEdges(int $gameId, array $edges): array
    {
        $refs=[];
        foreach ($edges as $row) {
            $file=(int)($row['file_id']??0);
            $source=(int)($row['source_kind']??0);
            $index=(int)($row['source_index']??-1);
            if($file<1||$index<0)continue;
            $key=$file.':'.$source.':'.$index;
            $kind=(int)($row['required_object_key_kind']??0);
            $packageKind=(int)($row['required_package_key_kind']??0);
            $object=strtoupper((string)($row['object_key']??''));
            $package=strtoupper((string)($row['package_key']??''));
            if($kind===Uedb5SqlProjectionContract::OBJECT_KEY_SCRIPT_IMPORT_HASH
                && preg_match('/^[0-3][0-9A-F]{15}$/D',$object)) {
                $refs[]=['lookup_id'=>$key,'type'=>'script',
                    'object_index'=>strtoupper(dechex(hexdec($object[0])+4)).substr($object,1)];
            } elseif($packageKind===Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID
                && preg_match('/^[0-9A-F]{16}$/D',$package)) {
                if($kind===Uedb5SqlProjectionContract::OBJECT_KEY_PUBLIC_EXPORT_HASH
                    && preg_match('/^[0-9A-F]{16}$/D',$object)) {
                    $refs[]=['lookup_id'=>$key,'type'=>'export',
                        'package_id'=>$package,'export_hash'=>$object];
                } elseif($kind===0) {
                    $refs[]=['lookup_id'=>$key,'type'=>'package','package_id'=>$package];
                }
            }
        }
        return $this->resolve($gameId,$refs);
    }

    /**
     * @param list<array<string,mixed>> $refs
     * @return array<int|string,array<string,mixed>>
     */
    private function resolve(int $gameId,array $refs): array
    {
        if($gameId<1||$refs===[])return [];
        $scripts=[];$packages=[];$hashes=[];
        foreach($refs as $ref) {
            if($ref['type']==='script'){
                $raw=(string)($ref['object_index']??'');
                if(preg_match('/^[4-7][0-9A-F]{15}$/D',$raw))$scripts[$raw]=true;
            }else{
                $pid=(string)($ref['package_id']??'');
                if(preg_match('/^[0-9A-F]{16}$/D',$pid))$packages[$pid]=true;
                $hash=(string)($ref['export_hash']??'');
                if(preg_match('/^[0-9A-F]{16}$/D',$hash))$hashes[$hash]=true;
            }
        }
        $scriptMatches=$scripts!==[]
            ? CatalogZenCookedScriptLabelLookup::describe($this->db,'',$gameId,array_keys($scripts))
            : [];
        $providers=$this->publishedProviders($gameId,array_keys($packages));
        $providerIds=[];
        foreach($providers as $rows) {
            foreach($rows as $candidate)$providerIds[(int)$candidate['file_id']]=true;
        }
        $exportMatches=$this->publishedExports(array_keys($providerIds),array_keys($hashes));
        $result=[];
        foreach($refs as $ref){
            $key=$ref['lookup_id'];
            if($ref['type']==='script'){
                $raw=(string)($ref['object_index']??'');
                $detail=$scriptMatches[$raw]??null;
                $result[$key]=$detail!==null
                    ? ['state'=>'resolved_script','object_path'=>(string)$detail['path'],
                        'container_id'=>(int)($detail['container_id']??0),
                        'source'=>'global.utoc ScriptObjects']
                    : ['state'=>'not_found','source'=>'ScriptObjects'];
                continue;
            }
            $pid=(string)($ref['package_id']??'');
            $candidates=$providers[$pid]??[];
            if($ref['type']==='package') {
                $hits=$candidates;
            }else{
                $hash=(string)($ref['export_hash']??'');
                $hits=[];
                foreach($candidates as $p){
                    foreach($exportMatches[(int)$p['file_id']][$hash]??[] as $export){
                        $hits[]=$p+['export_index'=>(int)$export];
                    }
                }
            }
            if(count($hits)===1){
                $p=$hits[0];
                $result[$key]=[
                    'state'=>$ref['type']==='package'?'package_found':'resolved_export',
                    'file_id'=>(int)$p['file_id'],
                    'package_name'=>(string)$p['package_name'],
                    'export_index'=>isset($p['export_index'])?(int)$p['export_index']:null,
                    'source'=>'indexed Zen package',
                ];
            }elseif(count($hits)>1){
                $result[$key]=['state'=>'multiple_matches','match_count'=>count($hits)];
            }else{
                $result[$key]=['state'=>'not_found','source'=>'indexed Zen packages'];
            }
        }
        return $result;
    }

    /**
     * @param list<string> $packageIds
     * @return array<string,list<array<string,mixed>>>
     */
    private function publishedProviders(int $gameId,array $packageIds): array
    {
        $result=[];
        foreach(array_chunk($packageIds,160) as $batch){
            $in=implode(',',array_fill(0,count($batch),'?'));
            $q=$this->db->prepare('SELECT HEX(p.package_id) package_id,p.file_id,'
                .'f.package_name FROM ue_iostore_packages p '
                .'JOIN ue_iostore_containers c ON c.id=p.container_id AND c.game_id=? AND c.status="ready" '
                .'JOIN ue_files f ON f.id=p.file_id AND f.game_id=c.game_id AND f.scan_status="verified" '
                .'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
                .'AND v.format_version=5 AND v.package_family="zen-iostore" '
                .'JOIN ue_uedb5_zen_exam_files e ON e.file_id=v.file_id '
                .'AND e.source_sha256=v.payload_sha256 '
                .'WHERE p.chunk_index=0 AND p.publication_status="published" '
                .'AND p.package_id IN ('.$in.') ORDER BY p.file_id');
            $params=[$gameId];
            foreach($batch as $id)$params[]=hex2bin($id);
            $q->execute($params);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){
                $result[(string)$p['package_id']][]=$p;
            }
        }
        return $result;
    }

    /**
     * @param list<int> $providerIds
     * @param list<string> $hashes
     * @return array<int,array<string,list<int>>>
     */
    private function publishedExports(array $providerIds,array $hashes): array
    {
        $result=[];
        if($providerIds===[]||$hashes===[])return [];
        foreach(array_chunk($providerIds,100) as $ids){
            foreach(array_chunk($hashes,100) as $batch){
                $sql='SELECT o.file_id,o.object_index,HEX(o.public_export_hash) public_hash '
                    .'FROM ue_uedb5_compact_object_candidates o '
                    .'JOIN ue_uedb5_zen_exam_rows r ON r.file_id=o.file_id '
                    .'AND r.section="exports" AND r.row_index=o.object_index '
                    .'JOIN ue_uedb5_zen_exam_files e ON e.file_id=o.file_id '
                    .'JOIN ue_uedb5_files v ON v.file_id=e.file_id '
                    .'AND e.source_sha256=v.payload_sha256 AND v.format_version=5 '
                    .'WHERE o.object_kind=1 AND '
                    .'JSON_UNQUOTE(JSON_EXTRACT(r.row_json,"$.public_export_hash"))=HEX(o.public_export_hash) '
                    .'AND o.file_id IN ('
                    .implode(',',array_fill(0,count($ids),'?'))
                    .') AND o.public_export_hash IN ('
                    .implode(',',array_fill(0,count($batch),'?')).')';
                $q=$this->db->prepare($sql);
                $args=$ids;
                foreach($batch as $hash)$args[]=hex2bin($hash);
                $q->execute($args);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                    $result[(int)$row['file_id']][(string)$row['public_hash']][]=(int)$row['object_index'];
                }
            }
        }
        return $result;
    }
}
