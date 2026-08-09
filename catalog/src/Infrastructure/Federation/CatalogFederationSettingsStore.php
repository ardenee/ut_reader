<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads and writes persistent federation settings.
 * Why: Federation settings persistence is a database concern shared by identity, state and transport services and should not live in the legacy auth facade.
 * Role: Infrastructure federation settings store preserving the existing ue_federation_settings read/write contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationSettingsStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $statement = $this->db->prepare(
            'SELECT setting_value FROM ue_federation_settings WHERE setting_name=?'
        );
        $statement->execute([$name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (string)$row['setting_value'] : $default;
    }

    public function set(string $name, string $value): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO ue_federation_settings(setting_name, setting_value) VALUES(?,?) '
            . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
        );
        $statement->execute([$name, $value]);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT setting_name, setting_value FROM ue_federation_settings ORDER BY setting_name'
        );
        $settings = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $settings[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
        }
        return $settings;
    }
}
