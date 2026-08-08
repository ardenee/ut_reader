<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes federation inventory refresh, parent-pull queueing and child request submission.
 * Why: Network protocol calls and transfer-job persistence must not be embedded in the Inventories rendering page.
 * Role: Infrastructure orchestration over federation inventory/query compatibility services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoFederationInventoryListQuery;

final class CatalogFederationInventoryActions
{
    public const PARENT_PAGE_SIZE = 100;
    public const CHILD_PAGE_SIZE = 950;

    private readonly PdoFederationInventoryListQuery $inventory;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->inventory = new PdoFederationInventoryListQuery($db);
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';
        require_once $root . '/lib/FederationInventory.php';
        require_once $root . '/lib/FederationInventoryRefresh.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
        require_once $root . '/lib/FederationState.php';
    }

    /**
     * @param array<string,mixed> $input
     * @return array{flash:string,redirect:string}
     */
    public function handle(array $input, string $role, bool $ignoreBaseGame): array
    {
        $action = strtolower(trim((string)($input['action'] ?? '')));
        $peerId = (int)($input['peer_id'] ?? 0);
        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE id=? AND is_active=1',
            [$peerId]
        );
        if (!$peer) {
            throw new RuntimeException('Active federation peer not found.');
        }
        $cursorState = $this->postCursorState($input);

        if ($action === 'refresh') {
            $result = \federation_pull_inventory_from_peer($this->db, $peerId);
            $flash = '';
            if ($role === 'parent' && (string)$peer['peer_role'] === 'child') {
                $remote = \federation_request_child_refresh_parent_inventory($this->db, $peerId);
                $flash = 'Inventories refreshed: received ' . (int)($result['received'] ?? 0)
                    . ' child rows; child received ' . (int)($remote['received'] ?? 0) . ' parent rows.';
            } elseif ($role === 'child' && (string)$peer['peer_role'] === 'parent') {
                $push = \federation_push_inventory_to_parent($this->db, $peerId);
                $flash = 'Parent inventory refreshed and local inventory pushed: '
                    . (!empty($push['ok']) ? 'success' : 'failed') . '.';
            }
            return [
                'flash' => $flash,
                'redirect' => $role === 'parent'
                    ? $this->parentUrl($peerId, $this->tab($input['tab'] ?? 'required'))
                    : $this->childUrl($peerId),
            ];
        }

        if ($action === 'queue_parent_pull') {
            if ($role !== 'parent' || (string)$peer['peer_role'] !== 'child') {
                throw new RuntimeException('Only a Parent may download selected files from a child inventory.');
            }
            $ids = array_values(array_unique(array_filter(
                array_map('intval', is_array($input['peer_file_ids'] ?? null) ? $input['peer_file_ids'] : []),
                static fn(int $id): bool => $id > 0
            )));
            if (!$ids || count($ids) > self::PARENT_PAGE_SIZE) {
                throw new RuntimeException('Select between 1 and ' . self::PARENT_PAGE_SIZE . ' files.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = \catalog_all(
                $this->db,
                'SELECT pf.* FROM ue_federation_peer_files pf '
                . 'WHERE pf.peer_id=? AND pf.id IN (' . $placeholders . ') '
                . 'AND NOT (' . $this->localPresenceSql('pf') . ')'
                . ($ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : ''),
                array_merge([$peerId], $ids)
            );
            $insert = $this->db->prepare(
                'INSERT INTO ue_federation_transfer_jobs('
                . 'peer_id,direction,remote_file_id,status,speed_limit_kbps,wait_after_seconds,bytes_total'
                . ') VALUES(?,"parent_pull_from_child",?,"queued",?,?,?)'
            );
            $queued = 0;
            foreach ($rows as $row) {
                $remoteFileId = (int)($row['remote_file_id'] ?? 0);
                if ($remoteFileId <= 0 || \catalog_one(
                    $this->db,
                    'SELECT id FROM ue_federation_transfer_jobs '
                    . 'WHERE peer_id=? AND direction="parent_pull_from_child" AND remote_file_id=? '
                    . 'AND status IN ("queued","running","downloaded") LIMIT 1',
                    [$peerId, $remoteFileId]
                )) {
                    continue;
                }
                $insert->execute([
                    $peerId,
                    $remoteFileId,
                    (int)\fed_setting($this->db, 'max_download_kbps', '0'),
                    (int)\fed_setting($this->db, 'delay_between_downloads_seconds', '5'),
                    (int)$row['file_size'],
                ]);
                $queued++;
            }
            \fed_log(
                $this->db,
                $peerId,
                null,
                'INFO',
                'PARENT_PULL_QUEUE',
                'Queued ' . $queued . ' selected child inventory file(s).'
            );
            $tab = $this->tab($input['tab'] ?? 'required');
            return [
                'flash' => 'Queued ' . $queued . ' file(s) from this child.',
                'redirect' => $this->parentUrl($peerId, $tab, $this->cursorParams($cursorState)),
            ];
        }

        if ($action === 'submit_child_request') {
            return $this->submitChildRequest($input, $role, $peer, $cursorState);
        }

        throw new RuntimeException('Unknown inventory action.');
    }

    /** @param array<string,mixed> $parent @return array<string,array<string,mixed>> */
    public function childRequestStatuses(array $parent): array
    {
        $result = \fed_http_post_signed(
            rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
            (string)\fed_setting($this->db, 'site_id', ''),
            \federation_peer_stored_signing_secret($this->db, $parent),
            ['package_statuses' => true]
        );
        if (is_array($result['policy'] ?? null)) {
            \federation_cache_parent_base_game_policy($this->db, (int)$parent['id'], $result['policy']);
        }
        if (empty($result['ok']) || !is_array($result['packages'] ?? null)) {
            throw new RuntimeException(
                'Parent request status check failed: ' . (string)($result['error'] ?? 'invalid response')
            );
        }
        return $result['packages'];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $peer
     * @param array{cursor:string,cursor_move:string,cursor_page:int} $cursorState
     * @return array{flash:string,redirect:string}
     */
    private function submitChildRequest(array $input, string $role, array $peer, array $cursorState): array
    {
        $peerId = (int)$peer['id'];
        if ($role !== 'child' || (string)$peer['peer_role'] !== 'parent') {
            throw new RuntimeException('Only a Child may request missing dependency files from its Parent.');
        }

        $ignoreBaseGame = \federation_ignore_base_game_files($this->db, $peer);
        $context = $this->cursorContext('child', $peerId, 'required', $ignoreBaseGame, self::CHILD_PAGE_SIZE);
        $move = $cursorState['cursor_move'];
        $pageNumber = $cursorState['cursor_page'];
        $cursor = $this->decodeCursor($context, $cursorState['cursor'], $move, $pageNumber);
        $page = $this->inventory->childCursorPage(
            $peerId,
            $ignoreBaseGame,
            self::CHILD_PAGE_SIZE,
            $cursor,
            $move
        );
        $activeStatuses = $this->childRequestStatuses($peer);
        $byKey = [];
        foreach ($page['rows'] as $row) {
            $existing = $activeStatuses[strtolower(trim((string)$row['required_package']))] ?? null;
            if (is_array($existing) && in_array(
                (string)($existing['item_status'] ?? ''),
                ['requested', 'approved', 'queued', 'downloading', 'downloaded'],
                true
            )) {
                continue;
            }
            $byKey[$this->childKey($row)] = $row;
        }

        $keys = array_values(array_unique(array_filter(
            array_map('strval', is_array($input['item_keys'] ?? null) ? $input['item_keys'] : []),
            static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/', $key) === 1
        )));
        $items = [];
        foreach ($keys as $key) {
            $row = $byKey[$key] ?? null;
            if (!$row) {
                continue;
            }
            $items[] = [
                'required_package' => (string)$row['required_package'],
                'required_object_path' => (string)$row['required_object_path'],
                'wanted_guid' => '',
                'wanted_md5' => '',
                'game_name' => (string)$row['game_name'],
                'engine_key' => (string)$row['engine_key'],
                'use_count' => (int)$row['use_count'],
                'object_count' => (int)$row['object_count'],
                'is_base_game_dependency' => !empty($row['is_base_game']),
            ];
        }
        if (!$items) {
            throw new RuntimeException(
                'The selected packages are no longer eligible. The Parent policy, request status, '
                . 'or current cursor page changed; reload the list and select again.'
            );
        }

        $siteLabel = \fed_setting($this->db, 'site_name', '')
            ?: \fed_setting($this->db, 'site_url', '')
            ?: 'child';
        try {
            $result = \fed_http_post_signed(
                rtrim((string)$peer['site_url'], '/') . '/api/federation/request-submit.php',
                (string)\fed_setting($this->db, 'site_id', ''),
                \federation_peer_stored_signing_secret($this->db, $peer),
                [
                    'title' => 'Missing file request from ' . $siteLabel,
                    'notes' => 'Requested from the consolidated Parent Inventory page.',
                    'generated_at' => date('c'),
                    'items' => $items,
                ]
            );
        } catch (RuntimeException $requestError) {
            if (str_contains(
                $requestError->getMessage(),
                'Every selected package is excluded by the parent Ignore base-game files policy.'
            )) {
                return [
                    'flash' => 'The Parent base-game policy changed or was refreshed. '
                        . 'Excluded base-game packages were removed from the request list.',
                    'redirect' => $this->childUrl($peerId),
                ];
            }
            throw $requestError;
        }

        if (is_array($result['policy'] ?? null)) {
            \federation_cache_parent_base_game_policy($this->db, $peerId, $result['policy']);
        }
        if (empty($result['ok'])) {
            throw new RuntimeException('Request submission failed: ' . (string)($result['error'] ?? 'unknown error'));
        }
        \fed_log(
            $this->db,
            $peerId,
            null,
            'INFO',
            'REQUEST_SUBMIT_SEND',
            json_encode($result, JSON_UNESCAPED_SLASHES)
        );
        return [
            'flash' => '',
            'redirect' => 'requests.php?request_id=' . (int)($result['request_id'] ?? 0),
        ];
    }

    private function localPresenceSql(string $alias): string
    {
        return 'EXISTS ('
            . 'SELECT 1 FROM ue_files local WHERE local.scan_status="verified" '
            . 'AND ((COALESCE(' . $alias . '.package_guid,"")<>"" AND local.package_guid=' . $alias . '.package_guid) '
            . 'OR (COALESCE(' . $alias . '.md5,"")<>"" AND local.md5=' . $alias . '.md5))'
            . ')';
    }

    /** @param array<string,mixed> $input @return array{cursor:string,cursor_move:string,cursor_page:int} */
    private function postCursorState(array $input): array
    {
        return [
            'cursor' => trim((string)($input['cursor'] ?? '')),
            'cursor_move' => $this->cursorMove($input['cursor_move'] ?? 'first'),
            'cursor_page' => max(1, (int)($input['cursor_page'] ?? 1)),
        ];
    }

    /** @return array<string,mixed>|null */
    private function decodeCursor(string $context, string $token, string &$move, int &$page): ?array
    {
        if ($token === '') {
            return null;
        }
        $cursor = CatalogKeysetPaginator::decode($this->config, $context, $token);
        if ($cursor === null) {
            $move = 'first';
            $page = 1;
        }
        return $cursor;
    }

    private function cursorContext(string $view, int $peerId, string $tab, bool $ignoreBaseGame, int $limit): string
    {
        return json_encode([
            'page' => 'federation-inventories',
            'view' => $view,
            'peer_id' => $peerId,
            'tab' => $tab,
            'ignore_base_game' => $ignoreBaseGame,
            'limit' => $limit,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row */
    private function childKey(array $row): string
    {
        return hash('sha256', (int)$row['game_id'] . "\0" . strtolower(trim((string)$row['required_package'])));
    }

    private function cursorMove(mixed $value): string
    {
        $move = strtolower(trim((string)$value));
        return in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
    }

    private function tab(mixed $value): string
    {
        $tab = strtolower(trim((string)$value));
        return in_array($tab, ['required', 'missing'], true) ? $tab : 'required';
    }

    /** @param array{cursor:string,cursor_move:string,cursor_page:int} $state @return array<string,mixed> */
    private function cursorParams(array $state): array
    {
        return array_filter(
            $state,
            static fn(mixed $value): bool => $value !== '' && $value !== null && $value !== 1
        );
    }

    /** @param array<string,mixed> $params */
    private function parentUrl(int $peerId, string $tab, array $params = []): string
    {
        return 'inventories.php?' . http_build_query(array_merge(['peer_id' => $peerId, 'tab' => $tab], $params));
    }

    /** @param array<string,mixed> $params */
    private function childUrl(int $peerId, array $params = []): string
    {
        return 'inventories.php?' . http_build_query(array_merge(['peer_id' => $peerId], $params));
    }
}
