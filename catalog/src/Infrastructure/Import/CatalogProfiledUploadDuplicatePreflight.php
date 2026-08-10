<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Checks whether an ordinary browser-selected file is already verified for the selected game before upload.
 * Why: A client-computed content hash can avoid unnecessary network transfer without moving authoritative hashing back
 *      into the synchronous HTTP staging path.
 * Role: Read-only import optimization; browser hashes are advisory and never become authoritative catalog metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use InvalidArgumentException;
use PDO;

final class CatalogProfiledUploadDuplicatePreflight
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{id:int,original_name:string,package_name:string,file_size:int,sha1:string}|null
     */
    public function findVerifiedDuplicate(int $gameId, string $sha1, int $fileSize): ?array
    {
        if ($gameId < 1) {
            throw new InvalidArgumentException('A valid target game is required for duplicate preflight.');
        }
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new InvalidArgumentException('Duplicate preflight requires a valid SHA-1 digest.');
        }
        if ($fileSize < 1) {
            throw new InvalidArgumentException('Duplicate preflight requires a positive file size.');
        }

        // sha1 already has a global index. Keep the selected game and exact byte
        // size in the predicate so this optimization mirrors the intended game
        // scope and cannot skip a different-sized row on hash text alone.
        $statement = $this->db->prepare(
            'SELECT id,original_name,package_name,file_size,sha1 FROM ue_files '
            . 'WHERE sha1=? AND game_id=? AND file_size=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$sha1, $gameId, $fileSize]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'original_name' => (string)$row['original_name'],
            'package_name' => (string)$row['package_name'],
            'file_size' => (int)$row['file_size'],
            'sha1' => strtolower((string)$row['sha1']),
        ];
    }
}
