<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads, validates and persists generated-package export settings.
 * Why: Format policy, numeric limits and per-game override persistence should not live in the settings page.
 * Role: Infrastructure/application service over the existing ModPackageBuilder settings contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;

final class CatalogPackageExportSettingsService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/ModPackageBuilder.php';
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return \modpkg_settings($this->db);
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,p.profile_name,p.engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'ORDER BY g.name'
        );
    }

    /** @return array<string,string> */
    public function formatLabels(): array
    {
        $labels = \modpkg_format_labels();
        unset($labels[MODPKG_FORMAT_DISABLED]);
        return $labels;
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        foreach ([
            'package_export_enabled',
            'package_export_dependency_zip_enabled',
            'package_export_umod_enabled',
            'package_export_ut3_zip_enabled',
            'package_export_ut4_pak_enabled',
            'package_export_include_transitive',
            'package_export_allow_incomplete',
        ] as $key) {
            \fed_set_setting($this->db, $key, isset($input[$key]) ? '1' : '0');
        }

        $maxFiles = max(1, min(10000, (int)($input['package_export_max_files'] ?? 1000)));
        $maxBytesMb = max(1, min(102400, (int)($input['package_export_max_bytes_mb'] ?? 2048)));
        $author = trim((string)($input['package_export_default_author'] ?? 'UnrealDB')) ?: 'UnrealDB';
        $mountPoint = \modpkg_normalize_mount_point(
            (string)($input['package_export_ut4_mount_point'] ?? '../../../UnrealTournament/Content/')
        );

        \fed_set_setting($this->db, 'package_export_max_files', (string)$maxFiles);
        \fed_set_setting($this->db, 'package_export_max_bytes_mb', (string)$maxBytesMb);
        \fed_set_setting($this->db, 'package_export_default_author', $author);
        \fed_set_setting($this->db, 'package_export_ut4_mount_point', $mountPoint);
        \fed_set_setting($this->db, 'package_export_ut4_pak_version', '3');

        $gameFormats = [];
        $allowed = [
            'auto',
            MODPKG_FORMAT_DEPENDENCY_ZIP,
            MODPKG_FORMAT_UMOD,
            MODPKG_FORMAT_UT2MOD,
            MODPKG_FORMAT_UT4MOD,
            MODPKG_FORMAT_UT3_ZIP,
            MODPKG_FORMAT_UT4_PAK,
        ];
        foreach ($this->games() as $game) {
            $value = strtolower(trim((string)($input['game_format_' . (int)$game['id']] ?? 'auto')));
            $gameFormats[(string)(int)$game['id']] = in_array($value, $allowed, true) ? $value : 'auto';
        }
        \fed_set_setting($this->db, 'package_export_game_formats_json', \modpkg_json($gameFormats));
    }
}
