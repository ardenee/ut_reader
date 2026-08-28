<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the paginated read model for the Unverified Files admin page.
 * Why: Filtering SQL, summary counts, cached dependency evidence and physical queue hydration are read-model concerns.
 * Role: Infrastructure query; Presentation supplies filters and renders the returned model.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class PdoUnverifiedFilesPageQuery
{
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly PdoUnverifiedGameMatchCache $gameMatchCache;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
        $this->gameMatchCache = new PdoUnverifiedGameMatchCache($db);
    }

    /**
     * @return array{
     *   games:list<array<string,mixed>>,
     *   total:int,pages:int,page:int,limit:int,
     *   items:list<array<string,mixed>>,
     *   game_matches:array<int,list<array<string,mixed>>>,
     *   game_match_states:array<int,array<string,mixed>>,
     *   match_cache_summary:array{ready:int,pending:int,failed:int,missing:int,total:int},
     *   summary:array<string,mixed>,
     *   public_uploads:list<array<string,mixed>>,
     *   extension_options:list<string>,engine_options:list<string>
     * }
     */
    public function fetch(
        int $sourceGameId,
        string $extension,
        string $engine,
        string $version,
        string $licensee,
        int $page,
        int $limit
    ): array {
        $this->staging->ensureSchema();
        $page = max(1, $page);
        $limit = max(50, min(1000, $limit));
        $extension = strtolower(trim($extension));
        $engine = strtoupper(trim($engine));
        $version = trim($version);
        $licensee = trim($licensee);

        $games = \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.profile_id,p.engine_key'
            . ' FROM ue_games g'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
            . ' ORDER BY g.name'
        );
        $gamesById = [];
        foreach ($games as $game) {
            $gamesById[(int)$game['id']] = $game;
        }

        [$whereSql, $args] = $this->filters(
            $sourceGameId,
            $extension,
            $engine,
            $version,
            $licensee
        );
        $countRow = \catalog_one(
            $this->db,
            'SELECT COUNT(*) c FROM ue_files f WHERE ' . $whereSql,
            $args
        );
        $total = (int)($countRow['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $items = \catalog_all(
            $this->db,
            'SELECT f.id,f.package_name,f.original_name,f.source_relative_path,f.stored_name,f.extension,f.md5,f.sha1,f.package_guid,'
            . 'f.file_size,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
            . 'f.name_count,f.import_count,f.export_count,f.scan_notes,f.unverified_queue_key,'
            . 'f.unverified_queue_game_id,f.unverified_queue_name,f.unverified_reason'
            . ' FROM ue_files f WHERE ' . $whereSql
            . ' ORDER BY f.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $args
        );

        $bucketGame = CatalogUnverifiedQueueStorage::bucketGame();
        $pakParentIds = [];
        foreach ($items as &$item) {
            $queueGameId = (int)($item['unverified_queue_game_id'] ?? 0);
            $queueName = basename(trim((string)($item['unverified_queue_name'] ?? '')));
            if ($queueName === '') {
                $queueName = basename((string)($item['stored_name'] ?? $item['original_name'] ?? ''));
            }
            $queueGame = $queueGameId === 0
                ? $bucketGame
                : ($gamesById[$queueGameId] ?? [
                    'id' => $queueGameId,
                    'name' => 'Unknown queue #' . $queueGameId,
                    'slug' => 'unknown-' . $queueGameId,
                    'profile_id' => null,
                    'engine_key' => '',
                ]);
            $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory(
                $this->config,
                $queueGame,
                false
            );
            $path = $directory . DIRECTORY_SEPARATOR . $queueName;
            $item['queue_game'] = $queueGame;
            $item['queue_name'] = $queueName;
            $item['physical_exists'] = $queueName !== ''
                && is_file($path)
                && !is_link($path)
                && CatalogUnverifiedQueueStorage::pathInside($path, $directory);
            $item['queue_token'] = CatalogUnverifiedQueueStorage::token($queueGameId, $queueName);
            $item['package_parse_error'] = $this->packageParseError((string)($item['scan_notes'] ?? ''));
            if (strtolower(trim((string)($item['extension'] ?? ''))) === 'pak') {
                $pakParentIds[] = (int)$item['id'];
                $item['package_guid'] = 'N/A (PAK container)';
                $item['package_parse_error'] = '';
                $item['pak_container'] = true;
            } else {
                $item['pak_container'] = false;
            }
        }
        unset($item);

        // Exact dependency/object-path matching is intentionally absent from the
        // page request path. The worker projection stores all evidence as one row
        // per unverified package, so rendering a page is one indexed cache lookup.
        $fileIds = array_values(array_map(
            static fn(array $item): int => (int)($item['id'] ?? 0),
            $items
        ));
        $cachedMatches = $this->gameMatchCache->read($fileIds);
        $gameMatches = $cachedMatches['matches'];
        $gameMatchStates = $cachedMatches['states'];

        if ($pakParentIds !== []) {
            $this->rollUpPakChildren($items, $pakParentIds, $gameMatches, $gameMatchStates);
        }

        $matchCacheSummary = $this->gameMatchCache->bucketSummary();

        $summary = \catalog_one(
            $this->db,
            'SELECT COUNT(*) indexed_count,COALESCE(SUM(file_size),0) indexed_bytes,'
            . 'SUM(CASE WHEN unverified_queue_game_id=0 THEN 1 ELSE 0 END) bucket_count'
            . ' FROM ue_files WHERE scan_status="unverified"'
        ) ?: [];

        $optionRows = \catalog_all(
            $this->db,
            'SELECT extension,detected_engine_key FROM ue_files'
            . ' WHERE scan_status="unverified" GROUP BY extension,detected_engine_key'
        );
        $extensionOptions = [];
        $engineOptions = [];
        foreach ($optionRows as $optionRow) {
            $value = strtolower(trim((string)($optionRow['extension'] ?? '')));
            if ($value !== '') {
                $extensionOptions[$value] = true;
            }
            $value = strtoupper(trim((string)($optionRow['detected_engine_key'] ?? '')));
            $engineOptions[$value !== '' ? $value : 'UNKNOWN'] = true;
        }
        $extensionOptions = array_keys($extensionOptions);
        $engineOptions = array_keys($engineOptions);
        sort($extensionOptions);
        sort($engineOptions);

        $publicUploads = [];
        try {
            $publicUploads = catalog_all(
                $this->db,
                'SELECT id,original_name,relative_path,status,background_job_id,unverified_file_id,'
                . 'result_message,updated_at FROM ue_public_uploads '
                . 'WHERE status IN ("uploaded","processing","failed","duplicate") '
                . 'ORDER BY id DESC LIMIT 20'
            );
        } catch (Throwable) {
            // Rolling deployment before the public-upload migration: the main
            // unverified queue remains readable.
            $publicUploads = [];
        }

        return [
            'games' => $games,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'items' => $items,
            'game_matches' => $gameMatches,
            'game_match_states' => $gameMatchStates,
            'match_cache_summary' => $matchCacheSummary,
            'summary' => $summary,
            'public_uploads' => $publicUploads,
            'extension_options' => $extensionOptions,
            'engine_options' => $engineOptions,
        ];
    }

    /**
     * Roll retained PAK child package metadata/evidence into the parent row for
     * display only. The PAK itself remains excluded from package matching.
     *
     * @param list<array<string,mixed>> $items
     * @param list<int> $parentIds
     * @param array<int,list<array<string,mixed>>> $gameMatches
     * @param array<int,array<string,mixed>> $gameMatchStates
     */
    private function rollUpPakChildren(
        array &$items,
        array $parentIds,
        array &$gameMatches,
        array &$gameMatchStates
    ): void {
        try {
            $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
            $statement = $this->db->prepare(
                'SELECT m.parent_file_id,m.child_file_id,m.status,'
                . 'c.name_count,c.import_count,c.export_count '
                . 'FROM ue_unverified_pak_members m '
                . 'LEFT JOIN ue_files c ON c.id=m.child_file_id '
                . 'WHERE m.parent_file_id IN (' . $placeholders . ') ORDER BY m.parent_file_id,m.entry_index'
            );
            $statement->execute($parentIds);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            // Rolling deployment before migration: PAK processing itself will
            // refuse to run, but the page remains readable.
            foreach ($parentIds as $parentId) {
                $gameMatches[$parentId] = [];
                $gameMatchStates[$parentId] = ['status' => 'ready', 'calculated_at' => null];
            }
            return;
        }

        $childrenByParent = [];
        $countsByParent = [];
        foreach ($rows as $row) {
            $parentId = (int)$row['parent_file_id'];
            $childId = (int)($row['child_file_id'] ?? 0);
            $countsByParent[$parentId]['entries'] = 1 + (int)($countsByParent[$parentId]['entries'] ?? 0);
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $countsByParent[$parentId][$status] = 1 + (int)($countsByParent[$parentId][$status] ?? 0);
            if ($childId < 1 || isset($childrenByParent[$parentId][$childId])) {
                continue;
            }
            $childrenByParent[$parentId][$childId] = true;
            $countsByParent[$parentId]['name_count'] = (int)($countsByParent[$parentId]['name_count'] ?? 0)
                + (int)($row['name_count'] ?? 0);
            $countsByParent[$parentId]['import_count'] = (int)($countsByParent[$parentId]['import_count'] ?? 0)
                + (int)($row['import_count'] ?? 0);
            $countsByParent[$parentId]['export_count'] = (int)($countsByParent[$parentId]['export_count'] ?? 0)
                + (int)($row['export_count'] ?? 0);
        }

        $childIds = [];
        foreach ($childrenByParent as $children) {
            foreach (array_keys($children) as $childId) {
                $childIds[(int)$childId] = true;
            }
        }
        $childCache = $this->gameMatchCache->read(array_keys($childIds));
        $childMatches = $childCache['matches'];
        $childStates = $childCache['states'];

        foreach ($items as &$item) {
            $parentId = (int)($item['id'] ?? 0);
            if (empty($item['pak_container']) || !in_array($parentId, $parentIds, true)) {
                continue;
            }
            $summary = $countsByParent[$parentId] ?? [];
            $item['name_count'] = (int)($summary['name_count'] ?? 0);
            $item['import_count'] = (int)($summary['import_count'] ?? 0);
            $item['export_count'] = (int)($summary['export_count'] ?? 0);
            $item['pak_entry_count'] = (int)($summary['entries'] ?? 0);
            $item['pak_indexed_count'] = (int)($summary['indexed'] ?? 0);
            $item['pak_duplicate_count'] = (int)($summary['duplicate'] ?? 0);
            $item['pak_skipped_count'] = (int)($summary['skipped'] ?? 0);
            $item['pak_rejected_count'] = (int)($summary['rejected'] ?? 0);

            $aggregated = [];
            $latestCalculated = null;
            foreach (array_keys($childrenByParent[$parentId] ?? []) as $childId) {
                $state = $childStates[(int)$childId] ?? [];
                $calculated = trim((string)($state['calculated_at'] ?? ''));
                if ($calculated !== '' && ($latestCalculated === null || strcmp($calculated, $latestCalculated) > 0)) {
                    $latestCalculated = $calculated;
                }
                foreach ($childMatches[(int)$childId] ?? [] as $match) {
                    $gameId = (int)($match['game_id'] ?? 0);
                    if ($gameId < 1) {
                        continue;
                    }
                    if (!isset($aggregated[$gameId])) {
                        $aggregated[$gameId] = $match;
                        $aggregated[$gameId]['import_count'] = 0;
                        $aggregated[$gameId]['owner_count'] = 0;
                        $aggregated[$gameId]['exact_object_matches'] = 0;
                    }
                    $aggregated[$gameId]['import_count'] += (int)($match['import_count'] ?? 0);
                    $aggregated[$gameId]['owner_count'] += (int)($match['owner_count'] ?? 0);
                    $aggregated[$gameId]['exact_object_matches'] += (int)($match['exact_object_matches'] ?? 0);
                    $aggregated[$gameId]['compatible'] = !empty($aggregated[$gameId]['compatible']) || !empty($match['compatible']);
                    $aggregated[$gameId]['rank'] = min(
                        (int)($aggregated[$gameId]['rank'] ?? 99),
                        (int)($match['rank'] ?? 99)
                    );
                }
            }
            foreach ($aggregated as &$match) {
                $imports = max(0, (int)$match['import_count']);
                $exact = max(0, (int)$match['exact_object_matches']);
                $match['unmatched_object_count'] = max(0, $imports - $exact);
                $match['match_percent'] = $imports > 0 ? round(($exact / $imports) * 100, 1) : null;
            }
            unset($match);
            $values = array_values($aggregated);
            usort($values, static function (array $left, array $right): int {
                return ((int)($left['rank'] ?? 99) <=> (int)($right['rank'] ?? 99))
                    ?: ((int)($right['exact_object_matches'] ?? 0) <=> (int)($left['exact_object_matches'] ?? 0))
                    ?: strcasecmp((string)($left['game_name'] ?? ''), (string)($right['game_name'] ?? ''));
            });
            $gameMatches[$parentId] = $values;
            $gameMatchStates[$parentId] = [
                'status' => 'ready',
                'calculated_at' => $latestCalculated,
                'updated_at' => $latestCalculated,
                'last_error' => null,
                'match_count' => count($values),
                'exact_compatible_game_count' => count(array_filter(
                    $values,
                    static fn(array $match): bool => !empty($match['compatible'])
                        && (int)($match['exact_object_matches'] ?? 0) > 0
                )),
                'cache_version' => PdoUnverifiedGameMatchCache::VERSION,
            ];
        }
        unset($item);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filters(
        int $sourceGameId,
        string $extension,
        string $engine,
        string $version,
        string $licensee
    ): array {
        $where = ['f.scan_status="unverified"'];
        $args = [];
        if ($sourceGameId === -1) {
            $where[] = 'f.unverified_queue_game_id=0';
        } elseif ($sourceGameId > 0) {
            $where[] = 'f.unverified_queue_game_id=?';
            $args[] = $sourceGameId;
        }
        if ($extension !== '') {
            $where[] = 'f.extension=?';
            $args[] = $extension;
        }
        if ($engine !== '') {
            if ($engine === 'UNKNOWN') {
                $where[] = '(f.detected_engine_key IS NULL OR f.detected_engine_key="")';
            } else {
                $where[] = 'f.detected_engine_key=?';
                $args[] = $engine;
            }
        }
        if ($version !== '') {
            if (strtolower($version) === 'unknown') {
                $where[] = 'f.detected_package_version IS NULL';
            } elseif (preg_match('/^-?\d+$/', $version) === 1) {
                $where[] = 'f.detected_package_version=?';
                $args[] = (int)$version;
            } else {
                $where[] = '1=0';
            }
        }
        if ($licensee !== '') {
            if (strtolower($licensee) === 'unknown') {
                $where[] = 'f.detected_licensee_version IS NULL';
            } elseif (preg_match('/^-?\d+$/', $licensee) === 1) {
                $where[] = 'f.detected_licensee_version=?';
                $args[] = (int)$licensee;
            } else {
                $where[] = '1=0';
            }
        }
        return [implode(' AND ', $where), $args];
    }

    private function packageParseError(string $notes): string
    {
        $notes = str_replace(["\r\n", "\r"], "\n", $notes);
        $marker = 'Unverified table parse failed:';
        $position = strpos($notes, $marker);
        if ($position === false) {
            return '';
        }
        $error = trim(substr($notes, $position + strlen($marker)));
        $parts = preg_split('/\n(?:Queue reason:|Metadata repair attempted:)/', $error, 2);
        return trim((string)($parts[0] ?? $error));
    }
}
