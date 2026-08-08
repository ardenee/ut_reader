<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns inbound federation inventory validation, policy filtering and peer-file persistence.
 * Why: The inventory-push endpoint should authenticate/read/serialize; batch validation and upsert transactions belong to Infrastructure.
 * Role: Infrastructure federation inventory ingestion service preserving the existing inventory-push contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use Throwable;

final class CatalogFederationInventoryPushService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    public function maxPayloadBytes(): int
    {
        return max(
            1024 * 1024,
            min(
                (int)(\fed_setting(
                    $this->db,
                    'max_inventory_payload_bytes',
                    (string)(8 * 1024 * 1024)
                ) ?: 8 * 1024 * 1024),
                32 * 1024 * 1024
            )
        );
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function push(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') === 'parent' && is_array($payload['policy'] ?? null)) {
            \federation_cache_parent_base_game_policy($this->db, (int)$peer['id'], $payload['policy']);
            $peer = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_peers WHERE id=?',
                [(int)$peer['id']]
            ) ?: $peer;
        }

        $ignoreBaseGame = \federation_ignore_base_game_files(
            $this->db,
            (string)($peer['peer_role'] ?? '') === 'parent' ? $peer : null
        );

        $files = $payload['files'] ?? [];
        if (!is_array($files)) {
            throw new CatalogFederationApiException('Missing files array.', 400);
        }

        $maxRows = max(
            1,
            min((int)(\fed_setting($this->db, 'max_inventory_rows_per_push', '5000') ?: 5000), 20000)
        );
        if (count($files) > $maxRows) {
            throw new CatalogFederationApiException('Inventory batch exceeds the allowed row count.', 413);
        }

        $normalized = [];
        $excluded = 0;
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            if ($ignoreBaseGame && !empty($file['is_base_game'])) {
                $excluded++;
                continue;
            }

            $packageName = $this->text($file['package_name'] ?? '', 255);
            $originalName = $this->text($file['original_name'] ?? '', 255);
            $guid = strtoupper($this->text($file['package_guid'] ?? '', 64));
            $md5 = strtolower($this->text($file['md5'] ?? '', 32));
            $sha1 = strtolower($this->text($file['sha1'] ?? '', 40));
            if ($packageName === '' || $originalName === '' || ($guid === '' && $md5 === '')) {
                continue;
            }
            if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
                throw new CatalogFederationApiException('Inventory contains an invalid MD5 value.', 422);
            }
            if ($sha1 !== '' && preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
                throw new CatalogFederationApiException('Inventory contains an invalid SHA1 value.', 422);
            }
            if ($guid !== '' && preg_match('/^[A-F0-9-]{8,64}$/', $guid) !== 1) {
                throw new CatalogFederationApiException('Inventory contains an invalid package GUID.', 422);
            }

            $normalized[] = [
                (int)$peer['id'],
                isset($file['game_id']) ? max(0, (int)$file['game_id']) ?: null : null,
                $this->text($file['game_name'] ?? '', 160),
                $this->text($file['engine_key'] ?? '', 32),
                isset($file['file_id']) ? max(0, (int)$file['file_id']) ?: null : null,
                $packageName,
                $originalName,
                strtolower($this->text(
                    $file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION),
                    32
                )),
                max(0, (int)($file['file_size'] ?? 0)),
                $md5 !== '' ? $md5 : null,
                $sha1 !== '' ? $sha1 : null,
                $guid !== '' ? $guid : null,
                !empty($file['is_base_game']) ? 1 : 0,
                !empty($file['is_compressed']) ? 1 : 0,
                max(0, (int)($file['compression_flags'] ?? 0)),
                max(0, (int)($file['import_count'] ?? 0)),
                max(0, (int)($file['export_count'] ?? 0)),
            ];
        }

        if ($ignoreBaseGame) {
            $this->db->prepare(
                'DELETE FROM ue_federation_peer_files WHERE peer_id=? AND COALESCE(is_base_game,0)=1'
            )->execute([(int)$peer['id']]);
        }

        $sql = 'INSERT INTO ue_federation_peer_files('
            . 'peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,package_name,original_name,'
            . 'extension,file_size,md5,sha1,package_guid,is_base_game,is_compressed,compression_flags,'
            . 'import_count,export_count,last_seen_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),remote_game_name=VALUES(remote_game_name),'
            . 'remote_engine_key=VALUES(remote_engine_key),remote_file_id=VALUES(remote_file_id),'
            . 'package_name=VALUES(package_name),original_name=VALUES(original_name),extension=VALUES(extension),'
            . 'file_size=VALUES(file_size),md5=VALUES(md5),sha1=VALUES(sha1),package_guid=VALUES(package_guid),'
            . 'is_base_game=VALUES(is_base_game),is_compressed=VALUES(is_compressed),'
            . 'compression_flags=VALUES(compression_flags),import_count=VALUES(import_count),'
            . 'export_count=VALUES(export_count),last_seen_at=NOW()';

        $count = 0;
        foreach (array_chunk($normalized, 500) as $chunk) {
            $this->db->beginTransaction();
            try {
                $statement = $this->db->prepare($sql);
                foreach ($chunk as $values) {
                    $statement->execute($values);
                    $count++;
                }
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }

        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'INVENTORY_PUSH',
            'Received ' . $count . ' policy-visible inventory row(s); excluded '
            . $excluded . ' base-game row(s).'
        );

        return [
            'ok' => true,
            'received' => $count,
            'base_game_excluded' => $excluded,
            'policy' => strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone'))) === 'parent'
                ? \federation_parent_base_game_policy($this->db)
                : null,
        ];
    }

    private function text(mixed $value, int $maxLength): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new CatalogFederationApiException('Inventory field exceeds the allowed length.', 422);
        }
        return $value;
    }
}
