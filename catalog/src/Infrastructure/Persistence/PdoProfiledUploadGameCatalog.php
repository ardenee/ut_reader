<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves the target game metadata required by profile-targeted uploads.
 * Why: Game lookup is a persistence concern and must not live in the Application upload use case.
 * Role: PDO implementation of the ProfiledUploadGameCatalog application port.
 * Audit: Keep this adapter read-only and limited to fields required by the upload workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Upload\Contract\ProfiledUploadGameCatalog;

final class PdoProfiledUploadGameCatalog implements ProfiledUploadGameCatalog
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function slug(int $gameId): ?string
    {
        if ($gameId < 1) {
            return null;
        }
        $statement = $this->db->prepare('SELECT slug FROM ue_games WHERE id=?');
        $statement->execute([$gameId]);
        $slug = $statement->fetchColumn();
        return is_string($slug) && trim($slug) !== '' ? (string)$slug : null;
    }
}
