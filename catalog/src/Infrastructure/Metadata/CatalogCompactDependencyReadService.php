<?php
/**
 * V5-only runtime dependency read service.
 *
 * UEDB5 dependency_results remain the authoritative source-shaped detail;
 * ue_uedb5_dependency_edges is the indexed accelerator for reverse lookups.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class CatalogCompactDependencyReadService
{
    /** @var array<int,bool> */
    private static array $availabilityCache = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config = []) {}

    public function available(): bool
    {
        $key = spl_object_id($this->db);
        if (array_key_exists($key, self::$availabilityCache)) {
            return self::$availabilityCache[$key];
        }
        $tables = ['ue_uedb5_files','ue_uedb5_dependency_edges'];
        $s = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $s->execute($tables);
        return self::$availabilityCache[$key] = ((int)$s->fetchColumn() === count($tables));
    }

    public function metadataVersion(int $fileId): int
    {
        if ($fileId < 1 || !$this->available()) {
            return 0;
        }
        $s = $this->db->prepare(
            'SELECT v.format_version FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $s->execute([$fileId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    public static function statusLabel(int $status): string
    {
        return match($status) {
            Uedb5SqlProjectionContract::OUTCOME_RESOLVED => 'resolved',
            Uedb5SqlProjectionContract::OUTCOME_PACKAGE_ONLY => 'package_only',
            Uedb5SqlProjectionContract::OUTCOME_COMMON => 'common',
            Uedb5SqlProjectionContract::OUTCOME_UNRESOLVED => 'unresolved',
            default => 'missing',
        };
    }

    /** @return list<array<string,mixed>> */
    public function compactRows(int $fileId): array
    {
        $gameId = $this->requireCurrentFile($fileId);
        $reader = new Uedb5ParityV5ReadService($this->db, $this->runtimeConfig());
        $dependencies = $reader->dependencies($gameId, $fileId);

        $resolvedIds = [];
        foreach ($dependencies as $row) {
            $id = $row['resolved_file_id'] ?? null;
            if ($id !== null) {
                $resolvedIds[(int)$id] = true;
            }
        }
        $resolved = $this->resolvedFiles(array_map('intval', array_keys($resolvedIds)));

        $rows = [];
        foreach ($dependencies as $row) {
            $i = (int)($row['source_index'] ?? -1);
            $resolvedFileId = $row['resolved_file_id'] ?? null;
            $resolvedRow = $resolvedFileId !== null ? ($resolved[(int)$resolvedFileId] ?? []) : [];
            $detail = (array)($row['resolver_detail'] ?? []);
            $rows[] = [
                'id' => $i + 1,
                'file_id' => $fileId,
                'import_id' => null,
                'import_index' => $i,
                'required_package' => (string)($row['required_package'] ?? ''),
                'required_object_path' => (string)($row['required_object_path'] ?? ''),
                'import_object_name' => (string)($row['required_object'] ?? ''),
                'import_class_name' => (string)($row['class_name'] ?? ''),
                'import_class_package' => (string)($row['class_package'] ?? ''),
                'import_outer_index' => 0,
                'resolved_file_id' => $resolvedFileId !== null ? (int)$resolvedFileId : null,
                'resolved_export_id' => null,
                'resolved_export_index' => $row['resolved_object_index'] !== null ? (int)$row['resolved_object_index'] : null,
                'status' => (string)($row['outcome'] ?? 'missing'),
                'resolution_source' => (string)($detail['resolution_source'] ?? $detail['source'] ?? 'uedb5'),
                'resolution_confidence' => (string)($detail['resolution_confidence'] ?? $detail['confidence'] ?? 'source-exact'),
                'resolved_id' => $resolvedRow !== [] ? (int)$resolvedRow['id'] : null,
                'resolved_package' => (string)($resolvedRow['package_name'] ?? ''),
                'resolved_file' => (string)($resolvedRow['original_name'] ?? ''),
                'resolved_guid' => (string)($resolvedRow['package_guid'] ?? ''),
                'resolved_md5' => (string)($resolvedRow['md5'] ?? ''),
                'resolved_sha1' => (string)($resolvedRow['sha1'] ?? ''),
                'resolved_size' => isset($resolvedRow['file_size']) ? (int)$resolvedRow['file_size'] : 0,
                '_metadata_source' => 'uedb5',
            ];
        }

        $weights = ['missing'=>0,'unresolved'=>1,'package_only'=>2,'resolved'=>3,'common'=>4];
        usort($rows, static function(array $a, array $b) use ($weights): int {
            $cmp = ($weights[(string)($a['status'] ?? 'missing')] ?? 9)
                <=> ($weights[(string)($b['status'] ?? 'missing')] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            foreach (['resolution_confidence','resolution_source','required_package','required_object_path'] as $field) {
                $cmp = strnatcasecmp((string)($a[$field] ?? ''), (string)($b[$field] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return (int)$a['import_index'] <=> (int)$b['import_index'];
        });
        return $rows;
    }

    public function rows(int $fileId): array
    {
        return $this->compactRows($fileId);
    }

    /** @return list<array<string,mixed>> */
    public function usedByRows(int $targetFileId, int $limit = 200): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Current UEDB5 dependency projections are unavailable.');
        }
        $limit = max(1, min(5000, $limit));
        $s = $this->db->prepare(
            'SELECT DISTINCT src.id,src.package_name,src.original_name,src.package_guid,src.md5,src.sha1,src.file_size '
            . 'FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files src ON src.id=e.file_id AND src.scan_status="verified" '
            . 'WHERE e.resolved_file_id=? ORDER BY src.package_name,src.original_name LIMIT ' . $limit
        );
        $s->execute([$targetFileId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function uniqueStrings(array $values): array
    {
        $out=[];$seen=[];
        foreach($values as $value){
            $value=trim((string)$value);
            if($value==='')continue;
            $key=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
            if(!isset($seen[$key])){$seen[$key]=true;$out[]=$value;}
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function reverseRows(int $gameId, int $targetFileId, array $identityNames): array
    {
        if (!$this->available()) {
            throw new RuntimeException('Current UEDB5 dependency projections are unavailable.');
        }
        $identityNames = self::uniqueStrings($identityNames);
        $conditions = ['e.resolved_file_id=?'];
        $params = [$gameId, $targetFileId, $targetFileId];
        foreach ($identityNames as $name) {
            $conditions[] = '(e.required_package_key_kind=' . Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME
                . ' AND e.required_package_key=?)';
            $params[] = Uedb5SqlProjectionContract::classicPackageKeyBinary(
                $name,
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME
            );
        }
        $sql = 'SELECT DISTINCT e.file_id source_file_id,e.source_index import_index,e.resolved_file_id,e.outcome '
            . 'FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files src ON src.id=e.file_id AND src.game_id=? AND src.scan_status="verified" '
            . 'WHERE src.id<>? AND e.source_kind=' . Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT
            . ' AND (' . implode(' OR ', $conditions) . ') ORDER BY e.file_id,e.source_index';
        $s = $this->db->prepare($sql);
        $s->execute($params);
        $candidates = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($candidates === []) {
            return [];
        }
        $reader = new Uedb5ParityV5ReadService($this->db, $this->runtimeConfig());
        $byFile = [];
        foreach ($candidates as $row) {
            $byFile[(int)$row['source_file_id']][(int)$row['import_index']] = $row;
        }
        $sourceFiles = $this->resolvedFiles(array_map('intval', array_keys($byFile)));
        $rows = [];
        foreach ($byFile as $fileId => $indexes) {
            $details = $reader->dependenciesByIndex($gameId, $fileId);
            $source = $sourceFiles[$fileId] ?? [];
            foreach ($indexes as $index => $candidate) {
                $detail = $details[$index] ?? null;
                if (!is_array($detail)) {
                    throw new RuntimeException('UEDB5 dependency detail is missing for file #' . $fileId . ', import #' . $index . '.');
                }
                $rows[] = [
                    'source_file_id' => $fileId,
                    'import_index' => $index,
                    'resolved_file_id' => $candidate['resolved_file_id'] !== null ? (int)$candidate['resolved_file_id'] : null,
                    'status' => (string)($detail['outcome'] ?? self::statusLabel((int)$candidate['outcome'])),
                    'required_package' => (string)($detail['required_package'] ?? ''),
                    'required_object_path' => (string)($detail['required_object_path'] ?? ''),
                    'dependency_id' => $index + 1,
                    'id' => (int)($source['id'] ?? $fileId),
                    'package_name' => (string)($source['package_name'] ?? ''),
                    'original_name' => (string)($source['original_name'] ?? ''),
                    'package_guid' => (string)($source['package_guid'] ?? ''),
                    'md5' => (string)($source['md5'] ?? ''),
                    'sha1' => (string)($source['sha1'] ?? ''),
                    'file_size' => (int)($source['file_size'] ?? 0),
                ];
            }
        }
        usort($rows, static function(array $a,array $b):int {
            $cmp=strnatcasecmp((string)$a['original_name'],(string)$b['original_name']);
            if($cmp!==0)return $cmp;
            $cmp=(int)$a['source_file_id']<=>(int)$b['source_file_id'];
            return $cmp!==0?$cmp:(int)$a['import_index']<=>(int)$b['import_index'];
        });
        return $rows;
    }

    private function requireCurrentFile(int $fileId): int
    {
        if ($this->metadataVersion($fileId) !== Uedb5MetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' is missing current format-'
                . Uedb5MetadataContainer::FORMAT_VERSION . ' dependency metadata.'
            );
        }
        $s = $this->db->prepare(
            'SELECT game_id FROM ue_uedb5_files WHERE file_id=? AND format_version=?'
        );
        $s->execute([$fileId, Uedb5MetadataContainer::FORMAT_VERSION]);
        $gameId = (int)($s->fetchColumn() ?: 0);
        if ($gameId < 1) {
            throw new RuntimeException('Verified file #' . $fileId . ' has no UEDB5 game identity.');
        }
        return $gameId;
    }

    /** @param list<int> $fileIds @return array<int,array<string,mixed>> */
    private function resolvedFiles(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fileIds), '?'));
        $s = $this->db->prepare(
            'SELECT id,package_name,original_name,package_guid,md5,sha1,file_size FROM ue_files WHERE id IN (' . $in . ')'
        );
        $s->execute($fileIds);
        $out=[];
        while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false)$out[(int)$row['id']]=$row;
        return $out;
    }

    /** @return array<string,mixed> */
    private function runtimeConfig(): array
    {
        $config = $this->config;
        $path = trim((string)($config['storage_path'] ?? ''));
        if ($path === '') {
            throw new RuntimeException('Catalog storage_path is not configured.');
        }
        return $config;
    }
}
