<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds generated-package names, options and human/machine-readable metadata.
 * Why: Package descriptors are shared across ZIP, UMOD and PAK outputs and should not depend on binary writer code.
 * Role: Downloads infrastructure descriptor policy used by generated package writers and jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use RuntimeException;

final class CatalogGeneratedPackageDescriptor
{
    public static function safeComponent(string $value, string $fallback = 'package'): string
    {
        $value = trim(str_replace(["\0", '/', '\\'], ['', '_', '_'], $value));
        $value = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value, " .\t\r\n");
        return $value !== '' ? $value : $fallback;
    }

    public static function productKey(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]+/', '', $value) ?? '';
        return $value !== '' ? substr($value, 0, 80) : 'UnrealDBPackage';
    }

    public static function generatedVersion(mixed $value): string
    {
        $value = trim(str_replace(["\0", "\r", "\n"], '', (string)$value));
        $value = preg_replace('/[^A-Za-z0-9._+-]+/', '', $value) ?? '';
        return $value !== '' ? substr($value, 0, 80) : '1.0';
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $settings @param array<string,mixed> $input */
    public static function defaultOptions(array $plan, array $settings, array $input = []): array
    {
        self::support();
        $rootStem = \catalog_clean_unreal_package_stem((string)$plan['root']['package_name']);
        $name = self::safeComponent((string)($input['name'] ?? $rootStem), $rootStem);
        $version = trim((string)($input['version'] ?? '1.0')) ?: '1.0';
        $author = trim((string)($input['author'] ?? $settings['default_author']))
            ?: (string)$settings['default_author'];
        return ['name' => $name, 'version' => $version, 'author' => $author];
    }

    public static function extension(string $format): string
    {
        return match ($format) {
            CatalogPackageExportFormatPolicy::UMOD => 'umod',
            CatalogPackageExportFormatPolicy::UT2MOD => 'ut2mod',
            CatalogPackageExportFormatPolicy::UT4MOD => 'ut4mod',
            CatalogPackageExportFormatPolicy::UT4_PAK => 'pak',
            default => 'zip',
        };
    }

    /** @param array<string,mixed> $options */
    public static function downloadName(string $format, array $options): string
    {
        $suffix = match ($format) {
            CatalogPackageExportFormatPolicy::UT3_ZIP => '-UT3',
            CatalogPackageExportFormatPolicy::UT4_PAK => '-UT4',
            CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP => '-with-dependencies',
            default => '',
        };
        return self::safeComponent((string)$options['name']) . $suffix . '.' . self::extension($format);
    }

    /** @param array<string,mixed> $game @return array{section:string,product:string,version:string} */
    public static function umodRequirement(array $game, string $format): array
    {
        return match ($format) {
            CatalogPackageExportFormatPolicy::UMOD => ['section' => 'UnrealTournamentRequirement','product' => 'UnrealTournament','version' => '0'],
            CatalogPackageExportFormatPolicy::UT2MOD => ['section' => 'UT2003Requirement','product' => 'UT2003','version' => '0'],
            CatalogPackageExportFormatPolicy::UT4MOD => ['section' => 'UT2004Requirement','product' => 'UT2004','version' => '0'],
            default => ['section' => 'GameRequirement','product' => self::productKey((string)$game['name']),'version' => '0'],
        };
    }

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    public static function manifestFiles(array $plan): array
    {
        self::support();
        $out = [];
        foreach ($plan['files'] as $file) {
            $out[] = [
                'file_id' => (int)$file['id'],
                'install_path' => (string)$file['install_path'],
                'install_path_inferred' => (bool)$file['install_path_inferred'],
                'source_relative_path' => $file['source_relative_path'],
                'package_name' => (string)$file['package_name'],
                'original_name' => \catalog_clean_unreal_filename((string)$file['original_name']),
                'md5' => (string)$file['md5'],
                'sha1' => (string)$file['sha1'],
                'package_guid' => (string)$file['package_guid'],
                'size' => (int)$file['file_size'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $options */
    public static function metadata(array $plan, array $options): array
    {
        return [
            'schema' => 'unrealdb-mod-package/v1',
            'generated_at' => date('c'),
            'format' => (string)$plan['format'],
            'name' => (string)$options['name'],
            'version' => (string)$options['version'],
            'author' => (string)$options['author'],
            'selected_file_id' => (int)$plan['root']['id'],
            'selected_package' => (string)$plan['root']['package_name'],
            'game' => [
                'id' => (int)$plan['game']['id'],
                'name' => (string)$plan['game']['name'],
                'slug' => (string)$plan['game']['slug'],
                'engine' => (string)$plan['game']['engine_key'],
                'profile' => (string)$plan['game']['profile_name'],
            ],
            'include_dependencies' => (bool)$plan['include_dependencies'],
            'transitive_dependencies' => (bool)$plan['transitive_dependencies'],
            'file_count' => (int)$plan['file_count'],
            'total_bytes' => (int)$plan['total_bytes'],
            'base_game_files_excluded_count' => count($plan['blocked']),
            'missing_dependency_count' => count($plan['missing']),
            'package_only_dependency_count' => count($plan['package_only']),
            'files' => self::manifestFiles($plan),
            'base_game_files_excluded' => $plan['blocked'],
            'missing_dependencies' => $plan['missing'],
            'package_only_dependencies' => $plan['package_only'],
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $options */
    public static function readme(array $plan, array $options): string
    {
        self::support();
        $lines = [
            (string)$options['name'],
            str_repeat('=', max(3, strlen((string)$options['name']))),
            '',
            'Version: ' . (string)$options['version'],
            'Author: ' . (string)$options['author'],
            'Game: ' . (string)$plan['game']['name'],
            'Generated by UnrealDB: ' . date('c'),
            '',
            'Files included: ' . (int)$plan['file_count'],
            'Total size: ' . \catalog_bytes((int)$plan['total_bytes']),
        ];
        if ($plan['format'] === CatalogPackageExportFormatPolicy::UT3_ZIP) {
            $lines[] = '';$lines[] = 'Installation';$lines[] = '------------';$lines[] = 'Copy the UTGame folder into your Unreal Tournament 3 user-data folder and merge the folders.';
        } elseif ($plan['format'] === CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP) {
            $lines[] = '';$lines[] = 'Installation';$lines[] = '------------';$lines[] = 'Copy each included top-level game folder into the matching game installation folder.';
        }
        if ($plan['blocked']) {
            $lines[] = '';$lines[] = 'Official/base-game dependencies were not included. Install the original game content from your own copy.';
        }
        if ($plan['missing'] || $plan['package_only']) {
            $lines[] = '';$lines[] = 'Warning: this package has unresolved or package-only dependencies. Review UnrealDB-Mod.json before distributing it.';
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode the package manifest.');
        }
        return $json . "\n";
    }

    private static function support(): void
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }
}
