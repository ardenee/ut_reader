<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;

/**
 * The verified .uedb5 snapshot remains canonical. This is its disposable,
 * SHA-bound SQL projection for zero-source-file browser queries.
 *
 * Source reads belong to the importer or explicit backfill, not HTTP pages.
 */
final class PdoZenExamProjection
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $snapshot */
    public function publish(array $snapshot, string $payloadSha256): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Zen examination projection requires the caller-owned SQL transaction.');
        }
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1 || (string)($snapshot['package_family'] ?? '') !== 'zen-iostore'
            || strlen($payloadSha256) !== 32) {
            throw new RuntimeException('Invalid Zen source identity for SQL examination projection.');
        }
        $sections = (array)($snapshot['sections'] ?? []);
        $exportPaths = self::exportPaths((array)($sections['exports'] ?? []));
        $nameCount = count((array)($sections['name_map'] ?? []));
        $importCount = count((array)($sections['imports'] ?? []));
        $exportCount = count((array)($sections['exports'] ?? []));

        $delete = $this->db->prepare('DELETE FROM ue_uedb5_zen_exam_rows WHERE file_id=?');
        $delete->execute([$fileId]);
        foreach (['names'=>'name_map','imports'=>'imports','exports'=>'exports'] as $name=>$section) {
            $batched = [];
            foreach (array_values((array)($sections[$section] ?? [])) as $index=>$record) {
                $row = (array)$record;
                $row['index'] = $index;
                if ($name === 'exports') {
                    $row['local_path'] = (string)($exportPaths[$index] ?? '');
                    $row['full_path'] = self::fullPath(
                        (string)($file['package_name'] ?? ''), $row['local_path']
                    );
                }
                $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $search = self::searchValue($row, $name);
                $raw = (string)($row['raw_type_and_id'] ?? '');
                $reference = $name === 'imports' && preg_match('/^[0-9A-F]{16}$/Di',$raw)
                    ? hex2bin($raw) : null;
                $batched[] = [$fileId,$name,$index,$reference,$json,$search];
                if (count($batched) === 100) {
                    $this->insertBatch($batched);
                    $batched = [];
                }
            }
            if ($batched !== []) $this->insertBatch($batched);
        }
        $header = (array)($sections['package_summary'][0] ?? []);
        if ($header !== []) {
            $this->insertHeader($fileId, $header);
        }
        $q = $this->db->prepare('INSERT INTO ue_uedb5_zen_exam_files '
            . '(file_id,source_sha256,name_count,import_count,export_count) '
            . 'VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE '
            . 'source_sha256=VALUES(source_sha256),name_count=VALUES(name_count),'
            . 'import_count=VALUES(import_count),export_count=VALUES(export_count),indexed_at=CURRENT_TIMESTAMP');
        $q->execute([$fileId,$payloadSha256,$nameCount,$importCount,$exportCount]);
    }

    /**
     * A separate source-SHA-bound row holds the genuine cooked FZenPackageSummary.
     * Browser requests only read this indexed record. Backfill and importer may
     * populate it without rebuilding any already-correct name/import/export row.
     * @param array<string,mixed> $snapshot
     */
    public function publishHeaderOnly(int $fileId, array $snapshot, string $payloadSha256): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Zen header projection needs a caller-owned transaction.');
        }
        $q = $this->db->prepare('SELECT e.file_id FROM ue_uedb5_zen_exam_files e '
            . 'JOIN ue_uedb5_files v ON v.file_id=e.file_id '
            . 'WHERE e.file_id=? AND e.source_sha256=? AND v.payload_sha256=? '
            . 'AND v.package_family="zen-iostore" AND v.format_version=5 LIMIT 1');
        $q->execute([$fileId,$payloadSha256,$payloadSha256]);
        if ($q->fetchColumn() === false) {
            throw new RuntimeException('Zen header source is not the current verified projection.');
        }
        $header = (array)($snapshot['sections']['package_summary'][0] ?? []);
        if ($header === []) {
            throw new RuntimeException('Source-backed Zen package summary is absent.');
        }
        $this->insertHeader($fileId,$header);
    }

    /** @param array<string,mixed> $header */
    private function insertHeader(int $fileId, array $header): void
    {
        if (!isset($header['summary']) || !is_array($header['summary'])) {
            throw new RuntimeException('Zen header record has no serialized summary.');
        }
        $json = json_encode($header, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $q = $this->db->prepare('INSERT INTO ue_uedb5_zen_exam_rows '
            . '(file_id,section,row_index,reference_index,row_json,search_text) '
            . 'VALUES (?,"header",0,NULL,?,"") '
            . 'ON DUPLICATE KEY UPDATE row_json=VALUES(row_json),search_text=""');
        $q->execute([$fileId,$json]);
    }

    /** @return array<string,mixed>|null */
    public function header(int $fileId): ?array
    {
        if (!$this->isCurrent($fileId)) return null;
        $q = $this->db->prepare('SELECT row_json FROM ue_uedb5_zen_exam_rows '
            . 'WHERE file_id=? AND section="header" AND row_index=0');
        $q->execute([$fileId]);
        $json = $q->fetchColumn();
        if (!is_string($json)) return null;
        $header = json_decode($json,true,512,JSON_THROW_ON_ERROR);
        return is_array($header) ? $header : null;
    }

    /** @param list<array{int,string,int,?string,string,string}> $batch */
    private function insertBatch(array $batch): void
    {
        $values = implode(',', array_fill(0,count($batch),'(?,?,?,?,?,?)'));
        $q = $this->db->prepare('INSERT INTO ue_uedb5_zen_exam_rows '
            . '(file_id,section,row_index,reference_index,row_json,search_text) VALUES '.$values);
        $params = [];
        foreach ($batch as $row) array_push($params,...$row);
        $q->execute($params);
    }

    /** Checks both source SHA and exact total row counts, without reading .uedb5. */
    public function isCurrent(int $fileId): bool
    {
        $q = $this->db->prepare(
            'SELECT e.file_id FROM ue_uedb5_zen_exam_files e '
            . 'JOIN ue_uedb5_files v ON v.file_id=e.file_id '
            . 'JOIN ue_files f ON f.id=e.file_id '
            . 'WHERE e.file_id=? AND v.format_version=5 AND v.package_family="zen-iostore" '
            . 'AND v.payload_sha256=e.source_sha256 '
            . 'AND e.name_count=f.name_count AND e.import_count=f.import_count '
            . 'AND e.export_count=f.export_count LIMIT 1'
        );
        $q->execute([$fileId]);
        return $q->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function page(int $fileId, string $section, int $start, int $limit): array
    {
        self::requireSection($section);
        $q = $this->db->prepare('SELECT row_json FROM ue_uedb5_zen_exam_rows '
            . 'WHERE file_id=? AND section=? AND row_index>=? '
            . 'ORDER BY row_index LIMIT '.max(1,min(5000,$limit)));
        $q->execute([$fileId,$section,max(0,$start)]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($row)) throw new RuntimeException('Invalid Zen SQL examination row.');
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array{total:int,rows:list<array<string,mixed>>} */
    public function searchPage(int $fileId, string $section, string $query, int $start, int $limit): array
    {
        self::requireSection($section);
        $pattern = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
        $filter = 'file_id=? AND section=? AND search_text LIKE ? ESCAPE \'!\'';
        $total = $this->db->prepare('SELECT COUNT(*) FROM ue_uedb5_zen_exam_rows WHERE '.$filter);
        $total->execute([$fileId,$section,$pattern]);
        $count = (int)$total->fetchColumn();
        $q = $this->db->prepare('SELECT row_json FROM ue_uedb5_zen_exam_rows WHERE '.$filter
            . ' ORDER BY row_index LIMIT '.max(1,min(5000,$limit))
            . ' OFFSET '.max(0,$start));
        $q->execute([$fileId,$section,$pattern]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $rows[] = json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
        }
        return ['total'=>$count,'rows'=>$rows];
    }

    /**
     * Reverse FExportMapEntry::ObjectName.NameIndex references to NameMap.
     * A Zen ImportMap consists of FPackageObjectIndex values and has no
     * serialized local NameMap references; do not guess them by text matching.
     * This query only touches SQL rows for this file and never opens .uedb5.
     *
     * @param list<int> $names
     * @return array<int,array{count:int,indices:list<int>}>
     */
    public function exportReferencesByName(int $fileId, array $names): array
    {
        if (!$this->isCurrent($fileId)) return [];
        $wanted=[];
        foreach($names as $index) if($index>=0)$wanted[$index]=true;
        if($wanted===[])return [];
        $q=$this->db->prepare('SELECT row_index, '
            . 'JSON_UNQUOTE(JSON_EXTRACT(row_json,"$.object_name.name_index")) name_index '
            . 'FROM ue_uedb5_zen_exam_rows WHERE file_id=? AND section="exports" '
            . 'ORDER BY row_index');
        $q->execute([$fileId]);
        $links=[];
        while(($record=$q->fetch(PDO::FETCH_ASSOC))!==false){
            $nameIndex=(string)($record['name_index']??'');
            if(!ctype_digit($nameIndex)||!isset($wanted[(int)$nameIndex]))continue;
            $idx=(int)$nameIndex;
            if(!isset($links[$idx]))$links[$idx]=['count'=>0,'indices'=>[]];
            $links[$idx]['count']++;
            if(count($links[$idx]['indices'])<12)$links[$idx]['indices'][]=(int)$record['row_index'];
        }
        return $links;
    }

    /** Bounded SQL-only ExportMap search by the actual serialized FMappedName index.
     * @return array{total:int,rows:list<array<string,mixed>>}
     */
    public function exportPageByNameIndex(int $fileId,int $nameIndex,int $offset,int $limit):array
    {
        if($fileId<1||$nameIndex<0||!$this->isCurrent($fileId)){
            return ['total'=>0,'rows'=>[]];
        }
        $where='file_id=? AND section="exports" '
            .'AND JSON_UNQUOTE(JSON_EXTRACT(row_json,"$.object_name.name_index"))=?';
        $params=[$fileId,(string)$nameIndex];
        $total=$this->db->prepare('SELECT COUNT(*) FROM ue_uedb5_zen_exam_rows WHERE '.$where);
        $total->execute($params);
        $count=(int)$total->fetchColumn();
        $q=$this->db->prepare('SELECT row_json FROM ue_uedb5_zen_exam_rows WHERE '.$where
            .' ORDER BY row_index LIMIT '.max(1,min(1000,$limit)).' OFFSET '.max(0,$offset));
        $q->execute($params);
        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json){
            $row=json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($row))throw new RuntimeException('Invalid named export projection row.');
            $rows[]=$row;
        }
        return ['total'=>$count,'rows'=>$rows];
    }

    /**
     * @param list<string> $rawIndices
     * @return array<string,int> serialized raw Zen index => import table position
     */
    public function importIndexLookup(int $fileId, array $rawIndices): array
    {
        $wanted = [];
        foreach ($rawIndices as $raw) {
            $hex = strtoupper((string)$raw);
            if (preg_match('/^[A-F0-9]{16}$/D', $hex) === 1) $wanted[$hex] = true;
        }
        $result = [];
        foreach (array_chunk(array_keys($wanted), 200) as $batch) {
            $q = $this->db->prepare('SELECT HEX(reference_index) raw_index,row_index '
                . 'FROM ue_uedb5_zen_exam_rows WHERE file_id=? AND section="imports" '
                . 'AND reference_index IN ('.implode(',', array_fill(0,count($batch),'?')).')');
            $params = [$fileId];
            foreach ($batch as $hex) $params[] = hex2bin($hex);
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string)$row['raw_index']] = (int)$row['row_index'];
            }
        }
        return $result;
    }

    private static function requireSection(string $section): void
    {
        if (!in_array($section,['names','imports','exports'],true)) {
            throw new RuntimeException('Invalid Zen SQL examination section.');
        }
    }

    /** @param array<string,mixed> $row */
    private static function searchValue(array $row, string $section): string
    {
        $fields = match($section) {
            'names'=>[(string)($row['text']??$row['name_text']??''),(string)$row['index']],
            'imports'=>[(string)($row['type']??''),(string)($row['raw_type_and_id']??''),
                (string)($row['script_import_hash']??''),(string)($row['provider_package_id']??''),
                (string)($row['provider_public_export_hash']??''),(string)$row['index']],
            default=>[(string)($row['object_name']['text']??$row['object_name']??''),
                (string)($row['public_export_hash']??''),(string)$row['index']],
        };
        return implode(' ', $fields);
    }

    /** @param list<array<string,mixed>> $exports @return array<int,string> */
    public static function exportPaths(array $exports): array
    {
        $lookup = [];
        foreach ($exports as $row) {
            if (isset($row['index'])) $lookup[(int)$row['index']] = $row;
        }
        $paths = [];
        foreach (array_keys($lookup) as $idx) {
            $parts=[]; $visited=[]; $cursor=$idx;
            for($depth=0;$depth<128;$depth++) {
                if(isset($visited[$cursor])||!isset($lookup[$cursor])) break;
                $visited[$cursor]=true;
                $row=(array)$lookup[$cursor];
                $name=$row['object_name']??null;
                $text=is_array($name)?(string)($name['text']??''):(string)$name;
                if($text==='') break;
                $parts[]=$text;
                $outer=(array)($row['outer_index']??[]);
                if((string)($outer['type']??'')==='Null') {
                    $paths[$idx]=implode('.',array_reverse($parts));
                    break;
                }
                if((string)($outer['type']??'')!=='Export'
                    || !isset($outer['local_export_index'])) break;
                $cursor=(int)$outer['local_export_index'];
            }
        }
        return $paths;
    }

    public static function fullPath(string $packageName, string $localPath): string
    {
        if ($packageName===''||$localPath==='') return '';
        $root=substr($packageName,strrpos($packageName,'/')!==false?strrpos($packageName,'/')+1:0);
        if($localPath===$root)return $packageName;
        if(str_starts_with($localPath,$root.'.')) {
            return $packageName.substr($localPath,strlen($root));
        }
        return $packageName.'.'.$localPath;
    }
}
