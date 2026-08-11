<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the paginated read model for the Unverified Files admin page.
 * Why: Filtering SQL, summary counts, option discovery, dependency evidence and physical queue hydration are read-model concerns.
 * Role: Infrastructure query; Presentation supplies filters and renders the returned model.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class PdoUnverifiedFilesPageQuery
{
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly PdoUnverifiedReferenceMatchQuery $referenceMatches;
    private readonly PdoUnverifiedGameMatchQuery $gameMatches;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
        $this->referenceMatches = new PdoUnverifiedReferenceMatchQuery($db);
        $this->gameMatches = new PdoUnverifiedGameMatchQuery($db);
    }

    /**
     * @return array{
     *   games:list<array<string,mixed>>,
     *   total:int,pages:int,page:int,limit:int,
     *   items:list<array<string,mixed>>,
     *   reference_matches:array<string,list<array<string,mixed>>>,
     *   game_matches:array<int,list<array<string,mixed>>>,
     *   summary:array<string,mixed>,
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
            'SELECT f.id,f.package_name,f.original_name,f.stored_name,f.extension,f.md5,f.sha1,f.package_guid,'
            . 'f.file_size,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
            . 'f.name_count,f.import_count,f.export_count,f.unverified_queue_key,'
            . 'f.unverified_queue_game_id,f.unverified_queue_name,f.unverified_reason'
            . ' FROM ue_files f WHERE ' . $whereSql
            . ' ORDER BY f.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $args
        );

        $bucketGame = CatalogUnverifiedQueueStorage::bucketGame();
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
        }
        unset($item);

        $referenceMatches = $this->referenceMatches->fetch(
            array_values(array_unique(array_map(
                static fn(array $item): string => trim((string)($item['package_name'] ?? '')),
                $items
            )))
        );

        // The package-summary projection above is intentionally cheap and is
        // retained for compatibility callers. The page itself needs stronger
        // evidence before an administrator chooses a game, so also calculate
        // per-file profile compatibility and exact required-object/export hits.
        $gameMatches = $this->gameMatches->bulk(array_values(array_map(
            static fn(array $item): int => (int)($item['id'] ?? 0),
            $items
        )));

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

        return [
            'games' => $games,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'items' => $items,
            'reference_matches' => $referenceMatches,
            'game_matches' => $gameMatches,
            'summary' => $summary,
            'extension_options' => $extensionOptions,
            'engine_options' => $engineOptions,
        ];
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
}
