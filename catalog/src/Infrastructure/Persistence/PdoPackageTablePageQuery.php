<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides bounded Names/Imports/Exports paging and lookups from authoritative format-3 metadata.
 * Why: Verified package examination must never fall back to retired SQL metadata tables.
 * Role: Infrastructure compact metadata query used by file-examine pages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection;

final class PdoPackageTablePageQuery
{
    public const DEFAULT_PAGE_SIZE = 250;

    /** @var array<string,int> */
    private static array $formatCache = [];

    /** @var array<string,Uedb5RuntimeMetadataReader> */
    private static array $readerCache = [];

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
        $fileId = (int)$file['id'];
        $zen = strtolower((string)($file['extension'] ?? '')) === 'zenpkg';
        $sqlExam = $zen ? new PdoZenExamProjection($db) : null;
        $indexed = $sqlExam !== null && $sqlExam->isCurrent($fileId);
        // The SQL projection is source-SHA bound; do not reopen .uedb5 on
        // every page once its verified rows have been materialized.
        $rows = $indexed
            ? $sqlExam->page($fileId, $table, $start, $pageSize)
            : self::withRecovery($db, $fileId, static fn(Uedb5RuntimeMetadataReader $reader): array =>
                $reader->page($fileId, $table, $start, $pageSize)
            );

        $rows = self::presentV5Rows($rows, $table);
        if (($table === 'imports' || $table === 'exports')
            && strtolower((string)($file['extension'] ?? '')) !== 'zenpkg') {
            // Zen FPackageObjectIndex is NOT a signed classic FPackageIndex.
            // The generic outer-chain walker would invent false paths here.
            $rows = self::withSerializedObjectPaths($db, $fileId, $table, $rows, (string)($file['package_name'] ?? ''));
        }
        return [
            'rows' => $rows,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'page_size' => $pageSize,
            'start' => $total > 0 ? $start + 1 : 0,
            'end' => min($total, $start + count($rows)),
        ];
    }

    /**
     * UE5 Zen name reference navigation. Ordinary text search and classic
     * import/export readers remain on their existing isolated paths.
     * @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int,page_size:int,start:int,end:int}
     */
    public static function fetchZenExportsByNameIndex(
        PDO $db,array $file,int $nameIndex,int $requestedPage,int $pageSize
    ):array {
        if(strtolower((string)($file['extension']??''))!=='zenpkg'||$nameIndex<0){
            throw new RuntimeException('Name-index filtering requires a Zen package.');
        }
        $fileId=(int)$file['id'];
        $size=self::normalizePageSize($pageSize);
        $exam=new PdoZenExamProjection($db);
        if(!$exam->isCurrent($fileId)){
            throw new RuntimeException('Zen source-bound SQL projection is unavailable.');
        }
        $page=max(1,$requestedPage);
        $result=$exam->exportPageByNameIndex($fileId,$nameIndex,($page-1)*$size,$size);
        $total=(int)$result['total'];
        $pages=max(1,(int)ceil($total/$size));
        if($page>$pages){
            $page=$pages;
            $result=$exam->exportPageByNameIndex($fileId,$nameIndex,($page-1)*$size,$size);
        }
        $rows=self::presentV5Rows($result['rows'],'exports');
        $start=($page-1)*$size;
        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,
            'page_size'=>$size,'start'=>$total?$start+1:0,'end'=>$start+count($rows)];
    }

    /**
     * Search the entire V5 section, then paginate the matches while keeping
     * serialized row indexes for links and outer-reference navigation.
     *
     * @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int,page_size:int,start:int,end:int}
     */
    public static function fetchFilteredPage(PDO $db, array $file, string $table, string $query, int $page, int $pageSize): array
    {
        $table = self::normalizeTable($table);
        $query = trim($query);
        if ($query === '') return self::fetchPage($db, $file, $table, $page, $pageSize);
        $pageSize = self::normalizePageSize($pageSize);
        $requestedPage = max(1, $page);
        $fileId = (int)$file['id'];
        $sqlExam = strtolower((string)($file['extension'] ?? '')) === 'zenpkg'
            ? new PdoZenExamProjection($db) : null;
        $indexed = $sqlExam !== null && $sqlExam->isCurrent($fileId);
        $search = $indexed
            ? $sqlExam->searchPage($fileId, $table, $query, ($requestedPage-1)*$pageSize, $pageSize)
            : self::withRecovery($db, $fileId, static fn(Uedb5RuntimeMetadataReader $reader): array =>
                $reader->searchPage($fileId, $table, $query, ($requestedPage-1)*$pageSize, $pageSize)
            );
        $total = (int)$search['total'];
        $pages = max(1, (int)ceil($total/$pageSize));
        if ($requestedPage > $pages) {
            $requestedPage = $pages;
            $search = $indexed
                ? $sqlExam->searchPage($fileId, $table, $query, ($requestedPage-1)*$pageSize, $pageSize)
                : self::withRecovery($db, $fileId, static fn(Uedb5RuntimeMetadataReader $reader): array =>
                    $reader->searchPage($fileId, $table, $query, ($requestedPage-1)*$pageSize, $pageSize)
                );
        }
        $rows = self::presentV5Rows((array)$search['rows'], $table);
        if ($table !== 'names' && strtolower((string)($file['extension'] ?? '')) !== 'zenpkg') {
            $rows = self::withSerializedObjectPaths($db, $fileId, $table, $rows, (string)($file['package_name'] ?? ''));
        }
        $start = ($requestedPage-1)*$pageSize;
        return ['rows'=>$rows,'page'=>$requestedPage,'pages'=>$pages,'total'=>$total,
            'page_size'=>$pageSize,'start'=>$total ? $start+1 : 0,'end'=>$start+count($rows)];
    }

    /**
     * Normalize V5 source-shaped rows for the existing bounded examiner.
     * Do not invent absent derived paths or class identities.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function presentV5Rows(array $rows, string $table): array
    {
        $fname = static fn(mixed $value): string => is_array($value)
            ? (string)($value['text'] ?? '')
            : (string)($value ?? '');
        foreach ($rows as &$row) {
            // Keep the original source-shaped record *before* flattening
            // FName composites for convenient hyperlinks. The examiner must
            // never lose a serialized FName Number or version-gated field.
            $row['__serialized_source_fields'] = $row;
            if ($table === 'names') {
                $row['name_index'] = (int)($row['name_index'] ?? $row['index'] ?? 0);
                $row['name_text'] = (string)($row['name_text'] ?? $row['text'] ?? '');
            } elseif ($table === 'imports') {
                $row['import_index'] = (int)($row['import_index'] ?? $row['index'] ?? 0);
                foreach (['class_package', 'class_name', 'object_name'] as $field) {
                    $value = $row[$field] ?? null;
                    if (is_array($value) && array_key_exists('name_index', $value)) {
                        $row[$field . '_name_index'] = (int)$value['name_index'];
                    }
                    $row[$field] = $fname($value);
                }
            } elseif ($table === 'exports') {
                $row['export_index'] = (int)($row['export_index'] ?? $row['index'] ?? 0);
                $value = $row['object_name'] ?? null;
                // Preserve the original two uint32 FMappedName words for
                // Zen raw-table display; decoded text is secondary metadata.
                if (is_array($value) && isset($value['raw_index'])) {
                    $row['object_name_mapped'] = $value;
                }
                if (is_array($value) && array_key_exists('name_index', $value)) {
                    $row['object_name_name_index'] = (int)$value['name_index'];
                }
                $row['object_name'] = $fname($value);
                $row['class_name'] = $fname($row['class_name'] ?? null);
                $row['local_path'] = (string)($row['local_path'] ?? '');
                $row['full_path'] = (string)($row['full_path'] ?? '');
            }
        }
        unset($row);
        return $rows;
    }
    /**
     * Exact original ExportMap rows, by physical provider and zero-based index.
     * Used exclusively for bounded read-only navigational candidate validation.
     * Every returned path was reconstructed from this actual provider's
     * serialized parent references, rather than assumed from its leaf name.
     *
     * @param list<int> $indexes
     * @return array<int,array<string,mixed>>
     */
    public static function exportRowsByOriginalIndex(
        PDO $db, int $fileId, array $indexes, string $packageName
    ): array {
        if ($fileId < 1 || count($indexes) > 2500) return [];
        $reader = self::reader($db, $fileId);
        $raw = [];
        foreach (array_values(array_unique(array_map('intval', $indexes))) as $index) {
            if ($index < 0) continue;
            $page = $reader->page($fileId, 'exports', $index, 1);
            $entry = $page[0] ?? null;
            if (!is_array($entry) || (int)($entry['index'] ?? -1) !== $index) {
                continue;
            }
            $raw[] = $entry;
        }
        if ($raw === []) return [];
        $rows = self::presentV5Rows($raw, 'exports');
        $rows = self::withSerializedObjectPaths(
            $db, $fileId, 'exports', $rows, $packageName
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['export_index']] = $row;
        }
        return $result;
    }

    /**
     * Build display paths from the serialized Unreal FObjectImport/FObjectExport
     * object names and signed PackageIndex outer references. UEDB5 stores the
     * original records, not derived full-path strings.
     *
     * A null/invalid outer is never guessed into a path; loops and broken
     * references leave the derived path blank.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withSerializedObjectPaths(PDO $db, int $fileId, string $table, array $rows, string $packageName): array
    {
        $lookup = [];
        $reader = self::reader($db, $fileId);
        foreach ($rows as $row) {
            $index = (int)$row[$table === 'imports' ? 'import_index' : 'export_index'];
            $ref = $table === 'imports' ? -($index + 1) : $index + 1;
            $lookup[$ref] = $row;
        }

        // Fetch only missing ancestors; never scan the complete exports table.
        $find = static function (int $ref) use (&$lookup, $reader, $fileId): ?array {
            if ($ref === 0) return null;
            if (array_key_exists($ref, $lookup)) return $lookup[$ref];
            $section = $ref < 0 ? 'imports' : 'exports';
            $index = abs($ref) - 1;
            $raw = $reader->page($fileId, $section, $index, 1);
            $normalized = self::presentV5Rows($raw, $section);
            $row = $normalized[0] ?? null;
            if (!is_array($row)
                || (int)($row[$section === 'imports' ? 'import_index' : 'export_index'] ?? -1) !== $index) {
                return $lookup[$ref] = null;
            }
            return $lookup[$ref] = $row;
        };

        foreach ($rows as &$row) {
            $chain = [];
            $seen = [];
            $ownIndex = (int)$row[$table === 'imports' ? 'import_index' : 'export_index'];
            $ref = $table === 'imports' ? -($ownIndex + 1) : $ownIndex + 1;
            $complete = true;
            $externalRoot = false;
            $hasNumberedName = false;
            $immediateOuterRef = (int)($row['outer_index'] ?? 0);
            $immediateOuterName = '';
            for ($depth = 0; $ref !== 0; $depth++) {
                if ($depth >= 128 || isset($seen[$ref])) {
                    $complete = false;
                    break;
                }
                $seen[$ref] = true;
                $record = $find($ref);
                if ($record === null) {
                    $complete = false;
                    break;
                }
                $name = trim((string)($record['object_name'] ?? ''));
                if ($name === '') {
                    $complete = false;
                    break;
                }
                $chain[] = $name;
                $sourceName = $record['__serialized_source_fields']['object_name']
                    ?? $record['object_name'] ?? null;
                if (is_array($sourceName) && (int)($sourceName['number'] ?? 0) !== 0) {
                    $hasNumberedName = true;
                }
                if ($depth === 1 && $ref === $immediateOuterRef) {
                    // The name comes from the indexed source record *at* the
                    // actual OuterIndex, not from splitting a guessed path.
                    $immediateOuterName = $name;
                }
                if ($ref < 0) $externalRoot = true;
                $ref = (int)($record['outer_index'] ?? 0);
            }
            if (!$complete || !$chain) {
                $row['full_path'] = '';
                if ($table === 'imports') {
                    $row['root_package'] = '';
                    $row['outer_name'] = '';
                } else $row['local_path'] = '';
                continue;
            }
            $chain = array_reverse($chain);
            $local = implode('.', $chain);
            // A textual path omits the numeric FName suffix in this reader.
            // Do not equate such paths to exact FName identities for export
            // links when any source-chain element has Number != 0.
            $row['derived_path_has_numbered_fname'] = $hasNumberedName;
            if ($table === 'imports') {
                $row['root_package'] = $chain[0];
                $row['outer_name'] = $immediateOuterName;
                $row['full_path'] = $local;
            } else {
                $row['local_path'] = $local;
                $row['full_path'] = !$externalRoot && $packageName !== ''
                    ? $packageName . '.' . $local
                    : $local;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Find verified same-game catalog files with exact package names.
     * A package name is an identity, not proof of an import/export match.
     * Return all IDs so callers never silently choose among duplicates.
     *
     * @param list<string> $packageNames
     * @return array<string,list<int>>
     */
    public static function packageFiles(PDO $db, int $gameId, array $packageNames): array
    {
        $names = self::uniqueValues($packageNames);
        if ($gameId < 1 || $names === []) return [];
        $result = [];
        foreach (array_chunk($names, 250) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $db->prepare(
                'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified"'
                . ' AND package_name IN ('.$placeholders.') ORDER BY id'
            );
            $statement->execute(array_merge([$gameId], $chunk));
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $key = strtolower((string)$row['package_name']);
                $result[$key][] = (int)$row['id'];
            }
        }
        return $result;
    }

    /** @param list<int> $ids */
    public static function packageReferenceHtml(string $package, int $gameId, array $ids): string
    {
        $name = \catalog_h($package);
        if ($package === '') return '—';
        if (count($ids) === 1) {
            return '<a href="file-info.php?id='.(int)$ids[0].'">'.$name.'</a>';
        }
        if (count($ids) > 1) {
            return '<a href="game-files.php?id='.$gameId.'&file_filter='.rawurlencode($package).'">'
                .$name.'</a> <span class="muted">('.count($ids).' files)</span>';
        }
        return $name;
    }

    /** @return array<int,array<string,mixed>> */
    public static function dependencyPage(PDO $db, int $fileId, int $start, int $limit): array
    {
        $rows = self::withRecovery($db, $fileId, static fn(Uedb5RuntimeMetadataReader $reader): array =>
            $reader->page($fileId, 'dependencies', max(0, $start), max(1, min(5000, $limit)))
        );
        $byIndex = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $byIndex[(int)($row['import_index'] ?? -1)] = $row;
            }
        }
        return $byIndex;
    }

    /** @param list<string> $values @return array<string,int> */
    public static function nameLookup(PDO $db, int $fileId, array $values): array
    {
        $values = self::uniqueValues($values);
        return $values === [] ? [] : self::withRecovery(
            $db,
            $fileId,
            static fn(Uedb5RuntimeMetadataReader $reader): array => $reader->findNameIndexes($fileId, $values)
        );
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public static function nameUsage(PDO $db, int $fileId, array $names): array
    {
        $names = self::uniqueValues($names);
        return self::withRecovery(
            $db,
            $fileId,
            static fn(Uedb5RuntimeMetadataReader $reader): array => $reader->nameUsage($fileId, $names)
        );
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    public static function dependencyMap(PDO $db, int $fileId, array $imports): array
    {
        $indexes = array_values(array_map(
            static fn(array $row): int => (int)($row['import_index'] ?? -1),
            $imports
        ));
        $byIndex = self::withRecovery(
            $db,
            $fileId,
            static fn(Uedb5RuntimeMetadataReader $reader): array =>
                $reader->dependenciesForImportIndexes($fileId, $indexes)
        );
        $map = [];
        foreach ($imports as $row) {
            $index = (int)($row['import_index'] ?? -1);
            if (isset($byIndex[$index])) {
                $map[(int)($row['id'] ?? ($index + 1))] = $byIndex[$index];
            }
        }
        return $map;
    }

    private static function withRecovery(PDO $db, int $fileId, callable $operation): mixed
    {
        return $operation(self::reader($db, $fileId));
    }

    private static function reader(PDO $db, int $fileId): Uedb5RuntimeMetadataReader
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive verified file ID is required for compact metadata reads.');
        }
        $format = self::metadataFormat($db, $fileId);
        if ($format !== \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' is missing supported compact metadata; runtime legacy reads are disabled.'
            );
        }
        $storageRoot = self::storageRoot();
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact package table reads.');
        }
        $key = spl_object_id($db) . ':' . $storageRoot;
        return self::$readerCache[$key] ??= new Uedb5RuntimeMetadataReader($db, $storageRoot);
    }

    private static function metadataFormat(PDO $db, int $fileId): int
    {
        $key = spl_object_id($db) . ':' . $fileId;
        if (array_key_exists($key, self::$formatCache)) {
            return self::$formatCache[$key];
        }
        $statement = $db->prepare(
            'SELECT m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files m ON m.file_id=f.id AND m.game_id=f.game_id '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$fileId]);
        return self::$formatCache[$key] = (int)($statement->fetchColumn() ?: 0);
    }

    private static function storageRoot(): string
    {
        if (!function_exists('catalog_config')) {
            return '';
        }
        try {
            $config = \catalog_config();
        } catch (\Throwable) {
            return '';
        }
        return is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
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
