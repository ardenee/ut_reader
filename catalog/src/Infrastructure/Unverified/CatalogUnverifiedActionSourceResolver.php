<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves an unverified-action token to one validated staged file and its existing database row.
 * Why: Token decoding, queue-path validation and staged-row lookup are infrastructure concerns, not HTTP concerns.
 * Role: Infrastructure compatibility adapter for the established unverified queue storage contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class CatalogUnverifiedActionSourceResolver
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogUnverifiedIndex.php';
    }

    /** @return array<string,mixed> */
    public function resolve(string $token): array
    {
        $decoded = \uvf_base64url_decode($token);
        $payload = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid unverified file reference.');
        }

        $gameId = (int)($payload['game_id'] ?? -1);
        $queueName = basename((string)($payload['name'] ?? ''));
        if ($gameId < 0
            || $queueName === ''
            || $queueName !== (string)($payload['name'] ?? '')
            || str_ends_with(strtolower($queueName), '.txt')) {
            throw new \RuntimeException('Invalid unverified file reference.');
        }

        if ($gameId === 0) {
            $game = \uvf_bucket_game();
        } else {
            $game = \catalog_one($this->db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$gameId]);
            if (!$game) {
                throw new \RuntimeException('The source game no longer exists.');
            }
        }

        $dir = \uvf_unverified_dir($this->config, $game);
        $path = $dir . DIRECTORY_SEPARATOR . $queueName;
        if (!is_file($path) || !\uvf_path_inside($path, $dir)) {
            throw new \RuntimeException('The selected unverified file is no longer available.');
        }

        $row = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1',
            [\catalog_unverified_queue_key($gameId, $queueName)]
        );
        $originalName = trim((string)($row['original_name'] ?? ''));
        if ($originalName === '') {
            $originalName = \uvf_original_name_from_queue_name($queueName);
        }

        $reasonPath = $path . '.txt';
        $reason = trim((string)($row['unverified_reason'] ?? ''));
        if ($reason === '' && is_file($reasonPath) && \uvf_path_inside($reasonPath, $dir)) {
            $reason = trim((string)@file_get_contents($reasonPath, false, null, 0, 65535));
        }

        return [
            'token' => $token,
            'game' => $game,
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'reason_path' => $reasonPath,
            'reason' => $reason,
            'size' => (int)(filesize($path) ?: 0),
            'modified_at' => (int)(filemtime($path) ?: 0),
            'extension' => (string)($row['extension'] ?? \catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION))),
            'package_name' => (string)($row['package_name'] ?? \scanner_logical_package_name($originalName)),
            'md5' => strtolower(trim((string)($row['md5'] ?? ''))),
            'sha1' => strtolower(trim((string)($row['sha1'] ?? ''))),
            'package_guid' => trim((string)($row['package_guid'] ?? '')),
            'source_relative_path' => (string)($row['source_relative_path'] ?? ''),
            'file_id' => (int)($row['id'] ?? 0),
            'row' => is_array($row) ? $row : null,
        ];
    }
}
