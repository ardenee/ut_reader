<?php
/**
 * Public catalogue files must never stream from local verified storage to
 * anonymous users. Legacy local/fallback modes become external-provider mode.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609080001',
    'description' => 'Make public individual-file downloads external-provider only.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_federation_settings')) {
            return;
        }

        $statement = $db->prepare(
            'UPDATE ue_federation_settings SET setting_value="external_mirror" '
            . 'WHERE setting_name="public_download_mode" '
            . 'AND setting_value IN ("local_direct","external_mirror_preferred")'
        );
        $statement->execute();
    },
];
