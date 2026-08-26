<?php
/**
 * Persist GeoIP country snapshots on download/generation audit rows and provide
 * a local country-range lookup table. Rendering the audit page must never need
 * to resolve historical IP addresses over the network.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608260001',
    'description' => 'Add local GeoIP country ranges and persisted country snapshots to download audit records.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_geoip_country_ranges',
            'CREATE TABLE ue_geoip_country_ranges ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'ip_version TINYINT UNSIGNED NOT NULL,'
            . 'range_start VARBINARY(16) NOT NULL,'
            . 'range_end VARBINARY(16) NOT NULL,'
            . 'country_code CHAR(2) NOT NULL,'
            . 'country_name VARCHAR(120) NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_geoip_country_start (ip_version,range_start),'
            . 'KEY idx_geoip_country_lookup (ip_version,range_start)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach (['ue_download_audit', 'ue_generated_package_audit'] as $table) {
            $schema->ensureColumn(
                $table,
                'country_code',
                'ALTER TABLE ' . $table . ' ADD COLUMN country_code CHAR(2) NULL'
            );
            $schema->ensureColumn(
                $table,
                'country_name',
                'ALTER TABLE ' . $table . ' ADD COLUMN country_name VARCHAR(120) NULL'
            );
        }

        $schema->ensureIndex(
            'ue_download_audit',
            'idx_ue_download_audit_country',
            'CREATE INDEX idx_ue_download_audit_country ON ue_download_audit(country_name,started_at,id)'
        );
        $schema->ensureIndex(
            'ue_generated_package_audit',
            'idx_ue_generated_package_audit_country',
            'CREATE INDEX idx_ue_generated_package_audit_country ON ue_generated_package_audit(country_name,queued_at,id)'
        );
    },
];
