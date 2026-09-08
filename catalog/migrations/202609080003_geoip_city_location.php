<?php
/**
 * Add optional city/region coordinates to the existing local GeoIP range table.
 *
 * Country-only datasets remain valid: the new columns are nullable and the
 * resolver falls back to the existing country fields whenever detailed data is
 * unavailable.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609080003',
    'description' => 'Add city, region, coordinates and accuracy radius to local GeoIP ranges.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $columns = [
            'subdivision_code' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN subdivision_code VARCHAR(32) NULL',
            'subdivision_name' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN subdivision_name VARCHAR(120) NULL',
            'city_name' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN city_name VARCHAR(120) NULL',
            'postal_code' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN postal_code VARCHAR(32) NULL',
            'latitude' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN latitude DECIMAL(9,6) NULL',
            'longitude' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN longitude DECIMAL(9,6) NULL',
            'accuracy_radius_km' => 'ALTER TABLE ue_geoip_country_ranges ADD COLUMN accuracy_radius_km INT UNSIGNED NULL',
        ];

        foreach ($columns as $column => $sql) {
            $schema->ensureColumn('ue_geoip_country_ranges', $column, $sql);
        }
    },
];
