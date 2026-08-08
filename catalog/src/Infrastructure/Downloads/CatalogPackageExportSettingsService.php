<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads, validates and persists generated-package export settings.
 * Why: Format policy, numeric limits and per-game override persistence should not depend on archive-building helpers.
 * Role: Downloads infrastructure settings service shared by admin, preview and worker flows.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;

final class CatalogPackageExportSettingsService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $all = \fed_all_settings($this->db);
        $gameFormats = json_decode((string)($all['package_export_game_formats_json'] ?? '{}'), true);
        if (!is_array($gameFormats)) {
            $gameFormats = [];
        }

        return [
            'enabled' => $this->boolSetting($all, 'package_export_enabled', true),
            'dependency_zip_enabled' => $this->boolSetting(
                $all,
                'package_export_dependency_zip_enabled',
                true
            ),
            'umod_enabled' => $this->boolSetting($all, 'package_export_umod_enabled', true),
            'ut3_zip_enabled' => $this->boolSetting($all, 'package_export_ut3_zip_enabled', true),
            'ut4_pak_enabled' => $this->boolSetting($all, 'package_export_ut4_pak_enabled', true),
            'include_transitive' => $this->boolSetting($all, 'package_export_include_transitive', true),
            'allow_incomplete' => $this->boolSetting($all, 'package_export_allow_incomplete', false),
            'max_files' => $this->intSetting($all, 'package_export_max_files', 1000, 1, 10000),
            'max_bytes' => $this->intSetting(
                $all,
                'package_export_max_bytes_mb',
                2048,
                1,
                102400
            ) * 1024 * 1024,
            'default_author' => trim((string)($all['package_export_default_author'] ?? 'UnrealDB')) ?: 'UnrealDB',
            'ut4_mount_point' => CatalogPackageInstallPathResolver::normalizeMountPoint(
                (string)($all['package_export_ut4_mount_point'] ?? '../../../UnrealTournament/Content/')
            ),
            'ut4_pak_version' => 3,
            'game_formats' => $gameFormats,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.description,g.profile_id,p.profile_name,p.engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'ORDER BY g.name'
        );
    }

    /** @return array<string,mixed>|null */
    public function game(int $gameId): ?array
    {
        return \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.description,g.profile_id,p.profile_name,p.engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=?',
            [$gameId]
        );
    }

    /** @return array<string,string> */
    public function formatLabels(): array
    {
        $labels = CatalogPackageExportFormatPolicy::labels();
        unset($labels[CatalogPackageExportFormatPolicy::DISABLED]);
        return $labels;
    }

    /** @param array<string,mixed> $game @param array<string,mixed> $settings @return list<string> */
    public function availableFormats(array $game, array $settings): array
    {
        return CatalogPackageExportFormatPolicy::available($game, $settings);
    }

    /** @param array<string,mixed> $game @param array<string,mixed> $settings */
    public function defaultFormat(array $game, array $settings): string
    {
        return CatalogPackageExportFormatPolicy::defaultFormat($game, $settings);
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
        $mountPoint = CatalogPackageInstallPathResolver::normalizeMountPoint(
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
            CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP,
            CatalogPackageExportFormatPolicy::UMOD,
            CatalogPackageExportFormatPolicy::UT2MOD,
            CatalogPackageExportFormatPolicy::UT4MOD,
            CatalogPackageExportFormatPolicy::UT3_ZIP,
            CatalogPackageExportFormatPolicy::UT4_PAK,
        ];
        foreach ($this->games() as $game) {
            $value = strtolower(trim((string)($input['game_format_' . (int)$game['id']] ?? 'auto')));
            $gameFormats[(string)(int)$game['id']] = in_array($value, $allowed, true)
                ? $value
                : 'auto';
        }
        \fed_set_setting(
            $this->db,
            'package_export_game_formats_json',
            $this->json($gameFormats)
        );
    }

    /** @param array<string,mixed> $settings */
    private function boolSetting(array $settings, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $settings) || trim((string)$settings[$key]) === '') {
            return $default;
        }
        return in_array(strtolower(trim((string)$settings[$key])), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string,mixed> $settings */
    private function intSetting(array $settings, string $key, int $default, int $min, int $max): int
    {
        $value = isset($settings[$key]) ? (int)$settings[$key] : $default;
        return max($min, min($max, $value));
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Could not encode the package export settings.');
        }
        return $json . "\n";
    }
}
