<?php
declare(strict_types=1);

return [
    'version' => '202607310005',
    'description' => 'Keep public feedback disabled until SMTP delivery is configured.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_federation_settings')) {
            return;
        }

        $smtpEnabled = $db->query(
            'SELECT setting_value FROM ue_federation_settings WHERE setting_name="smtp_enabled" LIMIT 1'
        )->fetchColumn();
        $smtpHost = $db->query(
            'SELECT setting_value FROM ue_federation_settings WHERE setting_name="smtp_host" LIMIT 1'
        )->fetchColumn();

        if (!in_array(strtolower(trim((string)$smtpEnabled)), ['1', 'true', 'yes', 'on'], true)
            || trim((string)$smtpHost) === '') {
            $statement = $db->prepare(
                'INSERT INTO ue_federation_settings(setting_name,setting_value) VALUES("feedback_enabled","0") '
                . 'ON DUPLICATE KEY UPDATE setting_value="0"'
            );
            $statement->execute();
        }
    },
];
