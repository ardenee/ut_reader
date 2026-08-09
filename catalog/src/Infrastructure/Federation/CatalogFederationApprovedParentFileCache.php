<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Caches metadata for a parent file approved to satisfy a child dependency request.
 * Why: The specialized peer-file upsert is persistence logic and should not live inside download queue orchestration.
 * Role: Infrastructure federation persistence service preserving the approved-parent cache representation and validation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationApprovedParentFileCache
{
    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /** @param array<string,mixed> $item */
    public function cache(int $peerId, array $item): void
    {
        $remoteFileId = (int)($item['local_file_id'] ?? 0);
        $packageName = trim((string)($item['package_name'] ?? $item['required_package'] ?? ''));
        $originalName = \catalog_clean_unreal_filename((string)($item['original_name'] ?? ''));
        $guid = strtoupper(trim((string)($item['package_guid'] ?? '')));
        $md5 = strtolower(trim((string)($item['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($item['sha1'] ?? '')));

        if ($remoteFileId <= 0 || $packageName === '' || $originalName === '' || $originalName === 'package') {
            throw new RuntimeException('Approved parent file metadata is incomplete.');
        }

        $this->db->prepare(
            'INSERT INTO ue_federation_peer_files(
                peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,
                package_name,original_name,extension,file_size,md5,sha1,package_guid,is_base_game,
                is_compressed,compression_flags,import_count,export_count,last_seen_at
             ) VALUES(?,NULL,"","",?,?,?,?,?,?,?,?,?,0,0,0,0,NOW())
             ON DUPLICATE KEY UPDATE
                package_name=VALUES(package_name), original_name=VALUES(original_name),
                extension=VALUES(extension), file_size=VALUES(file_size),
                md5=VALUES(md5), sha1=VALUES(sha1), package_guid=VALUES(package_guid),
                is_base_game=VALUES(is_base_game), last_seen_at=NOW()'
        )->execute([
            $peerId,
            $remoteFileId,
            mb_substr($packageName, 0, 255, 'UTF-8'),
            mb_substr($originalName, 0, 255, 'UTF-8'),
            mb_substr(strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)), 0, 32, 'UTF-8'),
            max(0, (int)($item['file_size'] ?? 0)),
            $md5 !== '' ? $md5 : null,
            $sha1 !== '' ? $sha1 : null,
            $guid !== '' ? $guid : null,
            !empty($item['is_base_game']) ? 1 : 0,
        ]);
    }
}
