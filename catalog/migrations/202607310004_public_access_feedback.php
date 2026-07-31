<?php
declare(strict_types=1);

return [
    'version' => '202607310004',
    'description' => 'Add public development, feedback, SMTP and abuse-control settings.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_federation_settings')) {
            return;
        }
        $defaults = [
            'site_development_mode' => '1',
            'site_development_title' => 'UnrealDB is under active development',
            'site_development_message' => 'Not every function is available yet. The site is public so visitors can explore the verified-file catalog and see what will be possible soon.',
            'feedback_enabled' => '1',
            'feedback_recipient' => 'info@unrealdb.com',
            'feedback_max_requests' => '5',
            'feedback_window_seconds' => '3600',
            'smtp_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'starttls',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from_email' => 'info@unrealdb.com',
            'smtp_from_name' => 'UnrealDB',
            'smtp_timeout_seconds' => '20',
            'public_download_max_files' => '10',
            'public_download_window_seconds' => '3600',
            'public_package_max_builds' => '10',
            'public_package_window_seconds' => '3600',
            'public_download_speed_kbps' => '0',
            'public_block_crawlers' => '1',
            'public_burst_max_requests' => '30',
            'public_burst_window_seconds' => '10',
            'public_burst_block_seconds' => '600',
        ];
        $statement = $db->prepare(
            'INSERT IGNORE INTO ue_federation_settings(setting_name,setting_value) VALUES(?,?)'
        );
        foreach ($defaults as $name => $value) {
            $statement->execute([$name, $value]);
        }
    },
];
