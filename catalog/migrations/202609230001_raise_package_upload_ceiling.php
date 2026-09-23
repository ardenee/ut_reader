<?php
/**
 * Raise the historical 256 MiB package ingress defaults to 2 GiB.
 *
 * Only the old default value is migrated; explicitly configured non-default
 * administrator values are preserved.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609230001',
    'description' => 'Raise legacy 256 MiB package upload ceilings to 2 GiB.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_program_settings')) {
            return;
        }

        $old = (string)(256 * 1024 * 1024);
        $new = (string)(2 * 1024 * 1024 * 1024);
        $statement = $db->prepare(
            'UPDATE ue_program_settings SET setting_value=? '
            . 'WHERE setting_key=? AND setting_value=?'
        );

        foreach (['normal_upload_limit_bytes', 'public_upload_max_file_bytes'] as $key) {
            $statement->execute([$new, $key, $old]);
        }
    },
];
