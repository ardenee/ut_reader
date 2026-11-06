<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/**
 * V5-only runtime metadata adapter.
 *
 * Preserves the compact-reader call surface used by live package-table pages
 * while resolving identity exclusively through ue_uedb5_files and .uedb5.
 * There is no ue_file_metadata lookup and no UEDB4 fallback.
 */
final class Uedb5RuntimeMetadataReader
{
    private Uedb5MetadataReader $reader;
    private Uedb5ParityV5ReadService $dependencyReader;
    /** @var array<int,int> */
    private array $gameIds = [];

    public function __construct(private readonly PDO $db, string $storageRoot)
    {
        $this->reader = new Uedb5MetadataReader($storageRoot);
        $this->dependencyReader = new Uedb5ParityV5ReadService($db, ['storage_path' => $storageRoot]);
    }

    /** @return list<array<string,mixed>> */
    public function page(int $fileId, string $section, int $start, int $limit): array
    {
        $gameId = $this->gameId($fileId);
        $start = max(0, $start);
        $limit = max(1, min(5000, $limit));
        if ($section === 'dependencies') {
            return array_slice($this->dependencyRows($gameId, $fileId), $start, $limit);
        }
        $section = $this->sectionAlias($gameId, $fileId, $section);
        return $this->reader->page($gameId, $fileId, $section, $start, $limit);
    }

    /**
     * Search every serialized row using a streaming scan, not the visible page.
     * Collect only the requested match page; return the full match count.
     * Does not modify or persist package metadata.
     *
     * @return array{total:int,rows:list<array<string,mixed>>}
     */
    public function searchPage(int $fileId, string $section, string $needle, int $offset, int $limit): array
    {
        if (!in_array($section, ['names','imports','exports'], true)) {
            throw new RuntimeException('Unsupported package search section.');
        }
        $gameId = $this->gameId($fileId);
        $needle = trim($needle);
        $offset = max(0, $offset);
        $limit = max(1, min(1000, $limit));
        $rows = [];
        $total = 0;
        $memo = [];

        // Compact source rows contain the original outer references, not
        // flattened root/full paths. Resolve those fields only when a search
        // has not already matched a directly serialized column.
        $fileQuery = $this->db->prepare('SELECT package_name FROM ue_files WHERE id=?');
        $fileQuery->execute([$fileId]);
        $packageName = (string)($fileQuery->fetchColumn() ?: '');

        $scalar = static function (mixed $value): string {
            if (is_array($value)) {
                $pieces = [];
                array_walk_recursive($value, static function (mixed $part) use (&$pieces): void {
                    if (is_scalar($part)) $pieces[] = (string)$part;
                });
                return implode(' ', $pieces);
            }
            return is_scalar($value) ? (string)$value : '';
        };
        $derived = function (array $row, int $position) use (
            $section, $fileId, $packageName, $scalar, &$memo
        ): array {
            $index = (int)($row['index']
                ?? $row[$section === 'imports' ? 'import_index' : 'export_index'] ?? $position);
            $originRef = $section === 'imports' ? -($index + 1) : $index + 1;
            $ref = $originRef;
            $chain = [];
            $seen = [];
            $external = false;
            for ($depth = 0; $ref !== 0; $depth++) {
                if ($depth >= 128 || isset($seen[$ref])) return [];
                $seen[$ref] = true;
                if ($ref === $originRef) {
                    $ancestor = $row;
                } elseif (array_key_exists($ref, $memo)) {
                    $ancestor = $memo[$ref];
                } else {
                    $kind = $ref < 0 ? 'imports' : 'exports';
                    $ancestorRows = $this->page($fileId, $kind, abs($ref) - 1, 1);
                    $ancestor = $ancestorRows[0] ?? null;
                    $memo[$ref] = is_array($ancestor) ? $ancestor : null;
                }
                if (!is_array($ancestor)) return [];
                $object = $scalar($ancestor['object_name'] ?? '');
                if ($object === '') return [];
                $chain[] = $object;
                if ($ref < 0) $external = true;
                $ref = (int)($ancestor['outer_index'] ?? 0);
            }
            if (!$chain) return [];
            $chain = array_reverse($chain);
            $relative = implode('.', $chain);
            if ($section === 'imports') {
                return [$chain[0], $relative]; // Root, Full Path
            }
            return [$relative, !$external && $packageName !== ''
                ? $packageName . '.' . $relative : $relative]; // Local, Full
        };

        foreach ($this->reader->scan($gameId, $fileId,
            $this->sectionAlias($gameId, $fileId, $section)) as $position => $row) {
            $index = (int)($row['index'] ?? $row[$section === 'names'
                ? 'name_index' : ($section === 'imports' ? 'import_index' : 'export_index')] ?? $position);
            // Include every directly serialized field appearing in the UI.
            // Numeric reference/size/offset values are matched as displayed.
            $fields = match ($section) {
                'names' => [
                    $row['name_text'] ?? $row['text'] ?? '', $row['flags'] ?? '',
                    $row['hash_version'] ?? '', $row['name_hash'] ?? '', $index,
                ],
                'imports' => [
                    $row['object_name'] ?? '', $row['class_name'] ?? '',
                    $row['class_package'] ?? '', $row['type'] ?? '',
                    $row['root_package'] ?? '', $row['full_path'] ?? '',
                    $row['outer_index'] ?? 0, -($index + 1), $index,
                    $row['script_import_hash'] ?? '', $row['provider_package_id'] ?? '',
                    $row['provider_public_export_hash'] ?? '',
                    $row['raw_type_and_id'] ?? '',
                ],
                'exports' => [
                    $row['object_name'] ?? '', $row['class_name'] ?? '',
                    $row['class_index'] ?? 0, $row['outer_index'] ?? 0,
                    $row['local_path'] ?? '', $row['full_path'] ?? '',
                    $row['object_flags'] ?? '', $row['serial_size'] ?? '',
                    $row['serial_offset'] ?? '', $row['public_export_hash'] ?? '',
                    $index, $index + 1,
                ],
            };
            $found = false;
            foreach ($fields as $field) {
                if (stripos($scalar($field), $needle) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $section !== 'names') {
                foreach ($derived($row, (int)$position) as $pathValue) {
                    if (stripos($pathValue, $needle) !== false) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) continue;
            if ($total >= $offset && count($rows) < $limit) $rows[] = $row;
            $total++;
        }
        return ['total'=>$total,'rows'=>$rows];
    }

    /** @param list<string> $values @return array<string,int> */
    public function findNameIndexes(int $fileId, array $values): array
    {
        $wanted = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $wanted[$this->key($value)] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $gameId = $this->gameId($fileId);
        $section = $this->sectionAlias($gameId, $fileId, 'names');
        $found = [];
        foreach ($this->reader->scan($gameId, $fileId, $section) as $position => $row) {
            $text = (string)($row['name_text'] ?? $row['text'] ?? $row['name'] ?? '');
            $key = $this->key($text);
            if (isset($wanted[$key]) && !isset($found[$key])) {
                $found[$key] = (int)($row['name_index'] ?? $row['index'] ?? $position);
                if (count($found) === count($wanted)) {
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public function nameUsage(int $fileId, array $names): array
    {
        $usage = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $usage[$this->key($name)] = [
                'imports_count' => 0,
                'imports_target' => '',
                'exports_count' => 0,
                'exports_target' => '',
            ];
        }
        if ($usage === []) {
            return [];
        }

        $gameId = $this->gameId($fileId);
        foreach ($this->reader->scan($gameId, $fileId, 'imports') as $row) {
            $matched = [];
            foreach (['class_package','class_name','object_name'] as $column) {
                $key = $this->key((string)($row[$column] ?? ''));
                if (isset($usage[$key])) {
                    $matched[$key] = true;
                }
            }
            foreach (array_keys($matched) as $key) {
                $usage[$key]['imports_count']++;
                if ($usage[$key]['imports_target'] === '') {
                    $usage[$key]['imports_target'] = 'import-' . (int)($row['import_index'] ?? 0);
                }
            }
        }
        foreach ($this->reader->scan($gameId, $fileId, 'exports') as $row) {
            $matched = [];
            foreach (['class_name','object_name'] as $column) {
                $key = $this->key((string)($row[$column] ?? ''));
                if (isset($usage[$key])) {
                    $matched[$key] = true;
                }
            }
            foreach (array_keys($matched) as $key) {
                $usage[$key]['exports_count']++;
                if ($usage[$key]['exports_target'] === '') {
                    $usage[$key]['exports_target'] = 'export-' . (int)($row['export_index'] ?? 0);
                }
            }
        }
        return $usage;
    }

    /** @param list<int> $importIndexes @return array<int,array<string,mixed>> */
    public function dependenciesForImportIndexes(int $fileId, array $importIndexes): array
    {
        $wanted = array_fill_keys(array_map('intval', $importIndexes), true);
        if ($wanted === []) {
            return [];
        }
        $found = [];
        foreach ($this->dependencyRows($this->gameId($fileId), $fileId) as $row) {
            $index = (int)$row['import_index'];
            if (isset($wanted[$index])) {
                $found[$index] = $row;
            }
        }
        return $found;
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $gameId = $this->gameId($fileId);
        $statement = $this->db->prepare(
            'SELECT payload_sha256 FROM ue_uedb5_files WHERE file_id=? AND game_id=? AND format_version=5'
        );
        $statement->execute([$fileId, $gameId]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('File #' . $fileId . ' has no current UEDB5 registration.');
        }
        // Registration SHA-256 is BINARY(32); the container verifier compares raw digest bytes.
        $verified = $this->reader->verify($gameId, $fileId, $hash);
        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => (string)$verified['path'],
            'format_version' => Uedb5MetadataContainer::FORMAT_VERSION,
        ];
    }


    /** @return list<array<string,mixed>> */
    private function dependencyRows(int $gameId, int $fileId): array
    {
        $rows = [];
        foreach ($this->dependencyReader->dependencies($gameId, $fileId) as $row) {
            $rows[] = [
                'import_index' => (int)($row['source_index'] ?? -1),
                'required_package' => (string)($row['required_package'] ?? ''),
                'required_object_path' => (string)($row['required_object_path'] ?? ''),
                'class_package' => (string)($row['class_package'] ?? ''),
                'class_name' => (string)($row['class_name'] ?? ''),
                'outcome' => (string)($row['outcome'] ?? ''),
                'resolved_file_id' => $row['resolved_file_id'] ?? null,
                'resolved_export_index' => $row['resolved_object_index'] ?? null,
                'reason_code' => (string)($row['reason_code'] ?? ''),
                'source_policy' => (string)($row['source_policy'] ?? ''),
            ];
        }
        return $rows;
    }

    private function sectionAlias(int $gameId, int $fileId, string $section): string
    {
        $sections = array_fill_keys($this->reader->sectionNames($gameId, $fileId), true);
        if (isset($sections[$section])) {
            return $section;
        }
        if ($section === 'names' && isset($sections['name_map'])) {
            return 'name_map';
        }
        throw new RuntimeException('UEDB5 file #' . $fileId . ' has no runtime section ' . $section . '.');
    }

    private function gameId(int $fileId): int
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive verified file ID is required for UEDB5 runtime metadata.');
        }
        if (isset($this->gameIds[$fileId])) {
            return $this->gameIds[$fileId];
        }
        $statement = $this->db->prepare(
            'SELECT v.game_id FROM ue_uedb5_files v '
            . 'JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
            . 'WHERE v.file_id=? AND v.format_version=5 AND f.scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $gameId = (int)($statement->fetchColumn() ?: 0);
        if ($gameId < 1) {
            throw new RuntimeException('Verified file #' . $fileId . ' is missing current UEDB5 metadata.');
        }
        return $this->gameIds[$fileId] = $gameId;
    }

    private function key(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
