<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Authorizes federation file transfers and resolves managed storage paths before HTTP streaming.
 * Why: Signed download endpoints should own response headers/streaming only; policy, lifecycle state and storage validation belong to Infrastructure.
 * Role: Infrastructure federation download authorization service preserving existing transfer rules and messages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationDownloadAuthorizationService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/BaseGameProtection.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array{file:array<string,mixed>,path:string,is_base_game:bool,item_id:int} */
    public function approvedDependency(array $peer, array $payload): array
    {
        \base_game_ensure($this->db);
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException('Only a paired child may download approved files.', 403);
        }

        $itemId = (int)($payload['request_item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new CatalogFederationApiException('request_item_id is required', 400);
        }

        $item = \catalog_one(
            $this->db,
            'SELECT i.*,r.peer_id,f.* '
            . 'FROM ue_federation_request_items i '
            . 'JOIN ue_federation_requests r ON r.id=i.request_id '
            . 'JOIN ue_files f ON f.id=i.local_file_id '
            . 'WHERE i.id=? AND r.peer_id=? AND r.direction="child_to_parent" '
            . 'AND i.status IN ("approved","queued","downloading")',
            [$itemId, (int)$peer['id']]
        );
        if (!$item) {
            throw new CatalogFederationApiException('Approved dependency request item not found', 404);
        }

        $isBaseGame = \base_game_file_is_protected($this->db, $item);
        if ($isBaseGame && \federation_ignore_base_game_files($this->db)) {
            throw new CatalogFederationApiException(
                'The parent policy excludes base-game files from all federation requests and transfers.',
                403
            );
        }

        $path = $this->managedPath((string)$item['relative_path'], 'Stored file missing');
        $message = $isBaseGame
            ? 'Child started approved base-game download while base-game federation participation is enabled.'
            : 'Child started approved dependency download.';
        $this->db->prepare(
            'UPDATE ue_federation_request_items SET status="downloading",status_message=? WHERE id=?'
        )->execute([$message, $itemId]);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            $isBaseGame ? 'CHILD_APPROVED_BASE_GAME_DOWNLOAD' : 'CHILD_APPROVED_DOWNLOAD',
            'Serving approved request item ' . $itemId . '.'
        );

        return [
            'file' => $item,
            'path' => $path,
            'is_base_game' => $isBaseGame,
            'item_id' => $itemId,
        ];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array{file:array<string,mixed>,path:string,is_base_game:bool} */
    public function parentPull(array $peer, array $payload): array
    {
        \base_game_ensure($this->db);
        if ((string)($peer['peer_role'] ?? '') !== 'parent') {
            throw new CatalogFederationApiException('Only the paired parent may pull files from this child.', 403);
        }

        $fileId = (int)($payload['remote_file_id'] ?? 0);
        if ($fileId <= 0) {
            throw new CatalogFederationApiException('remote_file_id is required.', 400);
        }

        $file = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if (!$file) {
            throw new CatalogFederationApiException('File not found or not verified.', 404);
        }

        $isBaseGame = \base_game_file_is_protected($this->db, $file);
        $ignoreBaseGame = \federation_policy_bool($payload['ignore_base_game_files'] ?? true, true);
        if ($isBaseGame && $ignoreBaseGame) {
            throw new CatalogFederationApiException(
                'The parent policy excludes base-game files from all federation lists, requests and transfers.',
                403
            );
        }

        $path = $this->managedPath((string)$file['relative_path'], 'Stored file missing.');
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            $isBaseGame ? 'PARENT_PULL_BASE_GAME_POLICY_ALLOWED' : 'PARENT_PULL_DOWNLOAD',
            'Serving file ID ' . $fileId . ' to parent peer. ignore_base_game='
            . ($ignoreBaseGame ? '1' : '0') . '.'
        );

        return ['file' => $file, 'path' => $path, 'is_base_game' => $isBaseGame];
    }

    private function managedPath(string $relativePath, string $missingMessage): string
    {
        $storageRoot = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        $catalogRoot = dirname(__DIR__, 3);
        $path = realpath($catalogRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));
        if (!$storageRoot || !$path
            || !str_starts_with(
                str_replace('\\', '/', $path) . '/',
                rtrim(str_replace('\\', '/', $storageRoot), '/') . '/'
            )
            || !is_file($path)
            || is_link($path)) {
            throw new CatalogFederationApiException($missingMessage, 404);
        }
        return $path;
    }
}
