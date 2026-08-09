<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the database schema required by federation base-game policy runtime.
 * Why: Runtime policy resolution needs a single read-only schema readiness boundary instead of repeated procedural checks.
 * Role: Infrastructure federation schema guard; migrations and install.sql remain the only schema owners.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationPolicySchemaGuard
{
    /** @var array<int,bool> */
    private static array $readyConnections = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function ensure(): void
    {
        $connectionId = spl_object_id($this->db);
        if (isset(self::$readyConnections[$connectionId])) {
            return;
        }

        $statement = $this->db->query(
            'SELECT '
            . 'EXISTS(SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_settings") AS settings_table, '
            . 'EXISTS(SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files") AS peer_files_table, '
            . 'EXISTS(SELECT 1 FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" '
            . 'AND column_name="is_base_game") AS base_game_column, '
            . 'EXISTS(SELECT 1 FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" '
            . 'AND index_name="idx_ue_federation_peer_files_base_game") AS base_game_index'
        );
        $schema = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($schema['settings_table'])
            || empty($schema['peer_files_table'])
            || empty($schema['base_game_column'])
            || empty($schema['base_game_index'])) {
            throw new RuntimeException(
                'Federation base-game policy schema is incomplete. Run php catalog/bin/migrate.php migrate.'
            );
        }

        self::$readyConnections[$connectionId] = true;
    }
}
