<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/BaseGameProtection.php';
require_once __DIR__ . '/GameProfiles.php';

const MODPKG_FORMAT_DEPENDENCY_ZIP = 'dependency_zip';
const MODPKG_FORMAT_UMOD = 'umod';
const MODPKG_FORMAT_UT2MOD = 'ut2mod';
const MODPKG_FORMAT_UT4MOD = 'ut4mod';
const MODPKG_FORMAT_UT3_ZIP = 'ut3_zip';
const MODPKG_FORMAT_UT4_PAK = 'ut4_pak';
const MODPKG_FORMAT_DISABLED = 'disabled';

function modpkg_bool_setting(array $settings, string $key, bool $default): bool
{
    if (!array_key_exists($key, $settings) || trim((string)$settings[$key]) === '') {
        return $default;
    }
    return in_array(strtolower(trim((string)$settings[$key])), ['1', 'true', 'yes', 'on'], true);
}

function modpkg_int_setting(array $settings, string $key, int $default, int $min, int $max): int
{
    $value = isset($settings[$key]) ? (int)$settings[$key] : $default;
    return max($min, min($max, $value));
}

function modpkg_settings(PDO $db): array
{
    $all = fed_all_settings($db);
    $gameFormats = json_decode((string)($all['package_export_game_formats_json'] ?? '{}'), true);
    if (!is_array($gameFormats)) {
        $gameFormats = [];
    }

    return [
        'enabled' => modpkg_bool_setting($all, 'package_export_enabled', true),
        'dependency_zip_enabled' => modpkg_bool_setting($all, 'package_export_dependency_zip_enabled', true),
        'umod_enabled' => modpkg_bool_setting($all, 'package_export_umod_enabled', true),
        'ut3_zip_enabled' => modpkg_bool_setting($all, 'package_export_ut3_zip_enabled', true),
        'ut4_pak_enabled' => modpkg_bool_setting($all, 'package_export_ut4_pak_enabled', true),
        'include_transitive' => modpkg_bool_setting($all, 'package_export_include_transitive', true),
        'allow_incomplete' => modpkg_bool_setting($all, 'package_export_allow_incomplete', false),
        'max_files' => modpkg_int_setting($all, 'package_export_max_files', 1000, 1, 10000),
        'max_bytes' => modpkg_int_setting($all, 'package_export_max_bytes_mb', 2048, 1, 102400) * 1024 * 1024,
        'default_author' => trim((string)($all['package_export_default_author'] ?? 'UnrealDB')) ?: 'UnrealDB',
        'ut4_mount_point' => modpkg_normalize_mount_point((string)($all['package_export_ut4_mount_point'] ?? '../../../UnrealTournament/Content/')),
        'ut4_pak_version' => 3,
        'game_formats' => $gameFormats,
    ];
}

function modpkg_format_labels(): array
{
    return [
        MODPKG_FORMAT_DEPENDENCY_ZIP => 'Dependency ZIP',
        MODPKG_FORMAT_UMOD => 'UT99 .umod',
        MODPKG_FORMAT_UT2MOD => 'UT2003 .ut2mod',
        MODPKG_FORMAT_UT4MOD => 'UT2004 .ut4mod',
        MODPKG_FORMAT_UT3_ZIP => 'UT3 structured ZIP',
        MODPKG_FORMAT_UT4_PAK => 'UT4 unencrypted .pak',
        MODPKG_FORMAT_DISABLED => 'Disabled',
    ];
}

function modpkg_supported_formats(): array
{
    return array_keys(modpkg_format_labels());
}

function modpkg_game_row(PDO $db, int $gameId): ?array
{
    return catalog_one(
        $db,
        'SELECT g.id, g.name, g.slug, g.description, g.profile_id, p.profile_name, p.engine_key
         FROM ue_games g
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         WHERE g.id=?',
        [$gameId]
    );
}

function modpkg_inferred_format(array $game): string
{
    $engine = strtoupper(trim((string)($game['engine_key'] ?? '')));
    $identity = strtolower(trim((string)($game['slug'] ?? '') . ' ' . (string)($game['name'] ?? '') . ' ' . (string)($game['profile_name'] ?? '')));

    if ($engine === 'UE1' && str_contains($identity, 'tournament')) {
        return MODPKG_FORMAT_UMOD;
    }
    if ($engine === 'UE2' && str_contains($identity, '2003')) {
        return MODPKG_FORMAT_UT2MOD;
    }
    if ($engine === 'UE2' && str_contains($identity, '2004')) {
        return MODPKG_FORMAT_UT4MOD;
    }
    if ($engine === 'UE3' && str_contains($identity, 'tournament')) {
        return MODPKG_FORMAT_UT3_ZIP;
    }
    if ($engine === 'UE4' && str_contains($identity, 'tournament')) {
        return MODPKG_FORMAT_UT4_PAK;
    }
    return MODPKG_FORMAT_DEPENDENCY_ZIP;
}

function modpkg_default_format(array $game, array $settings): string
{
    $override = strtolower(trim((string)($settings['game_formats'][(string)($game['id'] ?? 0)] ?? 'auto')));
    if ($override !== '' && $override !== 'auto' && in_array($override, modpkg_supported_formats(), true)) {
        return $override;
    }
    return modpkg_inferred_format($game);
}

function modpkg_format_is_enabled(string $format, array $settings): bool
{
    if (!$settings['enabled'] || $format === MODPKG_FORMAT_DISABLED) {
        return false;
    }
    return match ($format) {
        MODPKG_FORMAT_DEPENDENCY_ZIP => (bool)$settings['dependency_zip_enabled'],
        MODPKG_FORMAT_UMOD, MODPKG_FORMAT_UT2MOD, MODPKG_FORMAT_UT4MOD => (bool)$settings['umod_enabled'],
        MODPKG_FORMAT_UT3_ZIP => (bool)$settings['ut3_zip_enabled'],
        MODPKG_FORMAT_UT4_PAK => (bool)$settings['ut4_pak_enabled'],
        default => false,
    };
}

function modpkg_available_formats(array $game, array $settings): array
{
    if (!$settings['enabled']) {
        return [];
    }

    $formats = [];
    if ($settings['dependency_zip_enabled']) {
        $formats[] = MODPKG_FORMAT_DEPENDENCY_ZIP;
    }

    $native = modpkg_default_format($game, $settings);
    if ($native !== MODPKG_FORMAT_DEPENDENCY_ZIP && modpkg_format_is_enabled($native, $settings)) {
        array_unshift($formats, $native);
    }

    return array_values(array_unique($formats));
}

function modpkg_safe_component(string $value, string $fallback = 'package'): string
{
    $value = trim(str_replace(["\0", '/', '\\'], ['', '_', '_'], $value));
    $value = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $value) ?? '';
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value, " .\t\r\n");
    return $value !== '' ? $value : $fallback;
}

function modpkg_product_key(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_]+/', '', $value) ?? '';
    return $value !== '' ? substr($value, 0, 80) : 'UnrealDBPackage';
}

function modpkg_normalize_relative_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? '';
    $path = preg_replace('#^[A-Za-z]:/#', '', $path) ?? $path;
    $path = ltrim($path, '/');

    $parts = [];
    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts) {
                array_pop($parts);
            }
            continue;
        }
        $part = preg_replace('/[\x00-\x1F<>:"|?*]+/', '_', $part) ?? '_';
        $parts[] = trim($part, " .\t\r\n") ?: '_';
    }
    return implode('/', $parts);
}

function modpkg_normalize_mount_point(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? '';
    if ($path === '') {
        $path = '../../../UnrealTournament/Content/';
    }
    return rtrim($path, '/') . '/';
}

function modpkg_known_path_from_source(string $sourcePath, string $engine): ?string
{
    $path = modpkg_normalize_relative_path($sourcePath);
    if ($path === '') {
        return null;
    }
    $parts = explode('/', $path);
    $lower = array_map('strtolower', $parts);

    if ($engine === 'UE3') {
        $index = array_search('utgame', $lower, true);
        if ($index !== false) {
            return implode('/', array_slice($parts, (int)$index));
        }
        foreach (['published', 'cookedpc', 'localization', 'config'] as $root) {
            $index = array_search($root, $lower, true);
            if ($index !== false) {
                return 'UTGame/' . implode('/', array_slice($parts, (int)$index));
            }
        }
        return null;
    }

    if ($engine === 'UE4') {
        $contentIndex = array_search('content', $lower, true);
        if ($contentIndex !== false && isset($parts[(int)$contentIndex + 1])) {
            return implode('/', array_slice($parts, (int)$contentIndex + 1));
        }
        return null;
    }

    $roots = ['system', 'maps', 'textures', 'sounds', 'music', 'staticmeshes', 'animations', 'karmadata', 'web', 'help', 'benchmark', 'speech'];
    foreach ($roots as $root) {
        $index = array_search($root, $lower, true);
        if ($index !== false) {
            $slice = array_slice($parts, (int)$index);
            $slice[0] = match ($root) {
                'system' => 'System',
                'maps' => 'Maps',
                'textures' => 'Textures',
                'sounds' => 'Sounds',
                'music' => 'Music',
                'staticmeshes' => 'StaticMeshes',
                'animations' => 'Animations',
                'karmadata' => 'KarmaData',
                'web' => 'Web',
                'help' => 'Help',
                'benchmark' => 'Benchmark',
                'speech' => 'Speech',
                default => $slice[0],
            };
            return implode('/', $slice);
        }
    }
    return null;
}

function modpkg_fallback_install_path(array $file, string $engine, string $format): string
{
    $name = catalog_clean_unreal_filename((string)$file['original_name']);
    $ext = strtolower((string)($file['extension'] ?? pathinfo($name, PATHINFO_EXTENSION)));

    if ($format === MODPKG_FORMAT_UT3_ZIP || $engine === 'UE3') {
        if (in_array($ext, ['ini'], true)) {
            return 'UTGame/Config/' . $name;
        }
        if (in_array($ext, ['int', 'det', 'est', 'frt', 'itt', 'deu', 'fra', 'ita', 'jpn', 'kor', 'rus', 'spa'], true)) {
            return 'UTGame/Localization/INT/' . $name;
        }
        return 'UTGame/Published/CookedPC/CustomMaps/' . $name;
    }

    if ($format === MODPKG_FORMAT_UT4_PAK || $engine === 'UE4') {
        $stem = modpkg_safe_component((string)($file['package_name'] ?? pathinfo($name, PATHINFO_FILENAME)), 'Package');
        return 'UnrealDB/' . $stem . '/' . $name;
    }

    $folder = match ($ext) {
        'unr', 'ut2', 'un2' => 'Maps',
        'utx' => 'Textures',
        'uax', 'est_uax', 'frt_uax', 'itt_uax' => 'Sounds',
        'umx', 'ogg' => 'Music',
        'usx' => 'StaticMeshes',
        'ukx' => 'Animations',
        default => 'System',
    };
    return $folder . '/' . $name;
}

function modpkg_source_location(PDO $db, int $fileId): ?string
{
    $row = catalog_one(
        $db,
        'SELECT l.source_relative_path
         FROM ue_file_locations l
         JOIN ue_sources s ON s.id=l.source_id
         WHERE l.file_id=? AND l.exists_in_source=1
         ORDER BY (s.source_type="local_path") DESC, l.last_seen_at DESC, l.id ASC
         LIMIT 1',
        [$fileId]
    );
    return $row ? (string)$row['source_relative_path'] : null;
}

function modpkg_install_path(PDO $db, array $file, array $game, string $format): array
{
    $engine = strtoupper((string)($game['engine_key'] ?? ''));
    $source = modpkg_source_location($db, (int)$file['id']);
    $path = $source !== null ? modpkg_known_path_from_source($source, $engine) : null;
    $inferred = false;
    if ($path === null || $path === '') {
        $path = modpkg_fallback_install_path($file, $engine, $format);
        $inferred = true;
    }

    return [
        'path' => modpkg_normalize_relative_path($path),
        'source_relative_path' => $source,
        'path_inferred' => $inferred,
    ];
}

function modpkg_storage_path(array $config, array $file): string
{
    $relative = ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
    $catalogRoot = dirname(__DIR__);
    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $candidates = [$catalogRoot . '/' . $relative];

    if ($storageRoot !== false) {
        $withoutStorage = preg_replace('#^storage/#i', '', $relative) ?? $relative;
        $candidates[] = $storageRoot . '/' . $withoutStorage;
        $candidates[] = $storageRoot . '/' . basename((string)$file['stored_name']);
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            continue;
        }
        if ($storageRoot !== false) {
            $normalizedRoot = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
            $normalizedReal = str_replace('\\', '/', $real);
            if (!str_starts_with($normalizedReal . '/', $normalizedRoot) && $normalizedReal !== rtrim($normalizedRoot, '/')) {
                continue;
            }
        }
        return $real;
    }

    throw new RuntimeException('Stored file missing for ' . catalog_clean_unreal_filename((string)$file['original_name']));
}

function modpkg_plan(PDO $db, array $config, int $rootFileId, string $format, bool $includeDependencies = true, ?array $settings = null): array
{
    $settings ??= modpkg_settings($db);
    if (!$settings['enabled']) {
        throw new RuntimeException('Package exports are disabled by the administrator.');
    }
    if (!modpkg_format_is_enabled($format, $settings)) {
        throw new RuntimeException('The selected package export format is disabled.');
    }

    base_game_ensure($db);
    $root = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status<>"failed"', [$rootFileId]);
    if (!$root) {
        throw new RuntimeException('File not found.');
    }
    if (base_game_file_is_protected($db, $root)) {
        throw new RuntimeException(base_game_block_message($root));
    }

    $game = modpkg_game_row($db, (int)$root['game_id']);
    if (!$game || trim((string)($game['engine_key'] ?? '')) === '') {
        throw new RuntimeException('The selected game has no active game profile.');
    }

    $queue = [$rootFileId];
    $visited = [];
    $files = [];
    $blocked = [];
    $missing = [];
    $packageOnly = [];
    $common = [];
    $totalBytes = 0;
    $transitive = (bool)$settings['include_transitive'];

    while ($queue) {
        $fileId = (int)array_shift($queue);
        if (isset($visited[$fileId])) {
            continue;
        }
        $visited[$fileId] = true;

        $file = $fileId === $rootFileId ? $root : catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND game_id=? AND scan_status<>"failed"', [$fileId, (int)$root['game_id']]);
        if (!$file) {
            continue;
        }

        if ($fileId !== $rootFileId && base_game_file_is_protected($db, $file)) {
            $blocked[$fileId] = [
                'file_id' => $fileId,
                'package_name' => (string)$file['package_name'],
                'original_name' => catalog_clean_unreal_filename((string)$file['original_name']),
                'package_guid' => (string)$file['package_guid'],
                'reason' => 'Official/base game package: dependency-index-only; not redistributed.',
            ];
            continue;
        }

        $placement = modpkg_install_path($db, $file, $game, $format);
        $file['install_path'] = $placement['path'];
        $file['source_relative_path'] = $placement['source_relative_path'];
        $file['install_path_inferred'] = $placement['path_inferred'];
        $file['storage_path'] = modpkg_storage_path($config, $file);

        $files[$fileId] = $file;
        $totalBytes += (int)$file['file_size'];
        if (count($files) > (int)$settings['max_files']) {
            throw new RuntimeException('Package exceeds the configured file limit of ' . (int)$settings['max_files'] . '.');
        }
        if ($totalBytes > (int)$settings['max_bytes']) {
            throw new RuntimeException('Package exceeds the configured size limit of ' . catalog_bytes((int)$settings['max_bytes']) . '.');
        }

        if (!$includeDependencies) {
            continue;
        }

        $dependencies = catalog_all(
            $db,
            'SELECT d.id, d.required_package, d.required_object_path, d.resolved_file_id, d.status
             FROM ue_dependencies d
             WHERE d.file_id=?
             ORDER BY d.required_package, d.required_object_path, d.id',
            [$fileId]
        );
        foreach ($dependencies as $dependency) {
            $status = (string)$dependency['status'];
            $resolvedId = $dependency['resolved_file_id'] !== null ? (int)$dependency['resolved_file_id'] : 0;
            $key = strtolower((string)$dependency['required_package'] . '|' . (string)$dependency['required_object_path']);
            $detail = [
                'from_file_id' => $fileId,
                'from_package' => (string)$file['package_name'],
                'required_package' => (string)$dependency['required_package'],
                'required_object_path' => (string)$dependency['required_object_path'],
                'resolved_file_id' => $resolvedId ?: null,
                'status' => $status,
            ];

            if ($status === 'common') {
                $common[$key] = $detail;
                continue;
            }
            if ($status === 'missing' || $resolvedId <= 0) {
                $missing[$key] = $detail;
                continue;
            }
            if ($status === 'package_only') {
                $packageOnly[$key] = $detail;
            }
            if (!isset($visited[$resolvedId]) && ($transitive || $fileId === $rootFileId)) {
                $queue[] = $resolvedId;
            }
        }
    }

    $paths = [];
    foreach ($files as $fileId => $file) {
        $pathKey = strtolower((string)$file['install_path']);
        if (isset($paths[$pathKey]) && $paths[$pathKey] !== $fileId) {
            throw new RuntimeException('Two catalog files map to the same package path: ' . $file['install_path'] . '. Correct their source locations before exporting.');
        }
        $paths[$pathKey] = $fileId;
    }

    uasort($files, static function (array $a, array $b) use ($rootFileId): int {
        $aRoot = (int)$a['id'] === $rootFileId;
        $bRoot = (int)$b['id'] === $rootFileId;
        if ($aRoot !== $bRoot) {
            return $aRoot ? -1 : 1;
        }
        return strcasecmp((string)$a['install_path'], (string)$b['install_path']);
    });

    return [
        'format' => $format,
        'root' => $root,
        'game' => $game,
        'files' => array_values($files),
        'file_count' => count($files),
        'total_bytes' => $totalBytes,
        'blocked' => array_values($blocked),
        'missing' => array_values($missing),
        'package_only' => array_values($packageOnly),
        'common' => array_values($common),
        'include_dependencies' => $includeDependencies,
        'transitive_dependencies' => $includeDependencies && $transitive,
    ];
}

function modpkg_manifest_files(array $plan): array
{
    $out = [];
    foreach ($plan['files'] as $file) {
        $out[] = [
            'file_id' => (int)$file['id'],
            'install_path' => (string)$file['install_path'],
            'install_path_inferred' => (bool)$file['install_path_inferred'],
            'source_relative_path' => $file['source_relative_path'],
            'package_name' => (string)$file['package_name'],
            'original_name' => catalog_clean_unreal_filename((string)$file['original_name']),
            'md5' => (string)$file['md5'],
            'sha1' => (string)$file['sha1'],
            'package_guid' => (string)$file['package_guid'],
            'size' => (int)$file['file_size'],
        ];
    }
    return $out;
}

function modpkg_metadata(array $plan, array $options): array
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
        'files' => modpkg_manifest_files($plan),
        'base_game_files_excluded' => $plan['blocked'],
        'missing_dependencies' => $plan['missing'],
        'package_only_dependencies' => $plan['package_only'],
    ];
}

function modpkg_readme(array $plan, array $options): string
{
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
        'Total size: ' . catalog_bytes((int)$plan['total_bytes']),
    ];

    if ($plan['format'] === MODPKG_FORMAT_UT3_ZIP) {
        $lines[] = '';
        $lines[] = 'Installation';
        $lines[] = '------------';
        $lines[] = 'Copy the UTGame folder into your Unreal Tournament 3 user-data folder and merge the folders.';
    } elseif ($plan['format'] === MODPKG_FORMAT_DEPENDENCY_ZIP) {
        $lines[] = '';
        $lines[] = 'Installation';
        $lines[] = '------------';
        $lines[] = 'Copy each included top-level game folder into the matching game installation folder.';
    }

    if ($plan['blocked']) {
        $lines[] = '';
        $lines[] = 'Official/base-game dependencies were not included. Install the original game content from your own copy.';
    }
    if ($plan['missing'] || $plan['package_only']) {
        $lines[] = '';
        $lines[] = 'Warning: this package has unresolved or package-only dependencies. Review UnrealDB-Mod.json before distributing it.';
    }

    return implode("\r\n", $lines) . "\r\n";
}

function modpkg_json(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode the package manifest.');
    }
    return $json . "\n";
}

function modpkg_write_zip(string $outputPath, array $plan, array $options): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required for ZIP package exports.');
    }

    $zip = new ZipArchive();
    if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the package ZIP.');
    }

    try {
        foreach ($plan['files'] as $file) {
            if (!$zip->addFile((string)$file['storage_path'], (string)$file['install_path'])) {
                throw new RuntimeException('Could not add ' . $file['original_name'] . ' to the ZIP.');
            }
        }
        $zip->addFromString('UnrealDB-Mod.json', modpkg_json(modpkg_metadata($plan, $options)));
        $zip->addFromString('Readme.txt', modpkg_readme($plan, $options));
    } finally {
        $zip->close();
    }

    $validation = modpkg_validate_zip($outputPath, $plan);
    if (!$validation['ok']) {
        throw new RuntimeException('Generated ZIP validation failed: ' . implode('; ', $validation['errors']));
    }
    return $validation;
}

function modpkg_validate_zip(string $path, array $plan): array
{
    $errors = [];
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'errors' => ['ZipArchive unavailable']];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'errors' => ['Could not reopen generated ZIP']];
    }
    try {
        foreach ($plan['files'] as $file) {
            $stat = $zip->statName((string)$file['install_path']);
            if ($stat === false) {
                $errors[] = 'Missing entry ' . $file['install_path'];
            } elseif ((int)$stat['size'] !== (int)$file['file_size']) {
                $errors[] = 'Size mismatch for ' . $file['install_path'];
            }
        }
        if ($zip->locateName('UnrealDB-Mod.json') === false) {
            $errors[] = 'Missing UnrealDB-Mod.json';
        }
    } finally {
        $zip->close();
    }
    return ['ok' => !$errors, 'errors' => $errors, 'file_count' => count($plan['files'])];
}

function modpkg_compact_index(int $value): string
{
    $negative = $value < 0;
    $magnitude = abs($value);
    $first = $magnitude & 0x3F;
    $magnitude >>= 6;
    if ($negative) {
        $first |= 0x80;
    }
    if ($magnitude > 0) {
        $first |= 0x40;
    }
    $out = chr($first);
    while ($magnitude > 0) {
        $next = $magnitude & 0x7F;
        $magnitude >>= 7;
        if ($magnitude > 0) {
            $next |= 0x80;
        }
        $out .= chr($next);
    }
    return $out;
}

function modpkg_read_compact_index(string $data, int &$offset): int
{
    if ($offset >= strlen($data)) {
        throw new RuntimeException('Unexpected end of compact index.');
    }
    $first = ord($data[$offset++]);
    $negative = ($first & 0x80) !== 0;
    $continuation = ($first & 0x40) !== 0;
    $value = $first & 0x3F;
    $shift = 6;
    $count = 1;
    while ($continuation) {
        if ($offset >= strlen($data) || $count >= 5) {
            throw new RuntimeException('Invalid compact index.');
        }
        $byte = ord($data[$offset++]);
        $continuation = ($byte & 0x80) !== 0;
        $value |= ($byte & 0x7F) << $shift;
        $shift += 7;
        $count++;
    }
    return $negative ? -$value : $value;
}

function modpkg_ue1_string(string $value): string
{
    if (str_contains($value, "\0")) {
        throw new RuntimeException('Archive filenames may not contain NUL bytes.');
    }
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        throw new RuntimeException('UMOD archive paths must use ASCII characters: ' . $value);
    }
    $bytes = $value . "\0";
    return modpkg_compact_index(strlen($bytes)) . $bytes;
}

function modpkg_read_ue1_string(string $data, int &$offset): string
{
    $length = modpkg_read_compact_index($data, $offset);
    if ($length < 0) {
        $bytes = -$length * 2;
        if ($offset + $bytes > strlen($data)) {
            throw new RuntimeException('Unexpected end of Unicode archive string.');
        }
        $raw = substr($data, $offset, $bytes);
        $offset += $bytes;
        $decoded = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : $raw;
        return rtrim($decoded, "\0");
    }
    if ($offset + $length > strlen($data)) {
        throw new RuntimeException('Unexpected end of archive string.');
    }
    $raw = substr($data, $offset, $length);
    $offset += $length;
    return rtrim($raw, "\0");
}

function modpkg_pack_u32(int $value): string
{
    return pack('V', $value & 0xFFFFFFFF);
}

function modpkg_pack_i64(int $value): string
{
    $low = $value & 0xFFFFFFFF;
    $high = ($value >> 32) & 0xFFFFFFFF;
    return pack('V2', $low, $high);
}

function modpkg_unpack_i64(string $data, int $offset): int
{
    $parts = unpack('Vlow/Vhigh', substr($data, $offset, 8));
    return ((int)$parts['high'] << 32) | (int)$parts['low'];
}

function modpkg_umod_requirement(array $game, string $format): array
{
    return match ($format) {
        MODPKG_FORMAT_UMOD => ['section' => 'UnrealTournamentRequirement', 'product' => 'UnrealTournament', 'version' => '0'],
        MODPKG_FORMAT_UT2MOD => ['section' => 'UT2003Requirement', 'product' => 'UT2003', 'version' => '0'],
        MODPKG_FORMAT_UT4MOD => ['section' => 'UT2004Requirement', 'product' => 'UT2004', 'version' => '0'],
        default => ['section' => 'GameRequirement', 'product' => modpkg_product_key((string)$game['name']), 'version' => '0'],
    };
}

function modpkg_umod_manifest(array $plan, array $options): array
{
    $productKey = modpkg_product_key((string)$options['name']);
    $requirement = modpkg_umod_requirement($plan['game'], (string)$plan['format']);
    $setup = [
        '[Setup]',
        'Product=' . $productKey,
        'Version=' . ((preg_replace('/[^0-9]+/', '', (string)$options['version']) ?: '1')),
        'Requires=' . $requirement['section'],
        'Group=ModFiles',
        '',
        '[' . $requirement['section'] . ']',
        'Product=' . $requirement['product'],
        'Version=' . $requirement['version'],
        '',
        '[ModFiles]',
    ];
    foreach ($plan['files'] as $file) {
        $path = str_replace('/', '\\', (string)$file['install_path']);
        $setup[] = 'File=(Src=' . $path . ',Size=' . (int)$file['file_size'] . ')';
    }
    $setup[] = 'File=(Src=System\\UnrealDB-Mod.json)';
    $setup[] = 'File=(Src=System\\Readme-' . modpkg_safe_component((string)$options['name']) . '.txt)';
    $setup[] = '';

    $localized = [
        '[Setup]',
        'LocalProduct=' . (string)$options['name'],
        'Developer=' . (string)$options['author'],
        'SetupWindowTitle=' . (string)$options['name'] . ' Setup',
        '',
        '[ModFiles]',
        'Caption=' . (string)$options['name'],
        'Description=Installs ' . (string)$options['name'] . ' for ' . (string)$plan['game']['name'],
        '',
    ];

    return [
        'System/Manifest.ini' => implode("\r\n", $setup),
        'System/Manifest.int' => implode("\r\n", $localized),
        'System/UnrealDB-Mod.json' => modpkg_json(modpkg_metadata($plan, $options)),
        'System/Readme-' . modpkg_safe_component((string)$options['name']) . '.txt' => modpkg_readme($plan, $options),
    ];
}

function modpkg_write_umod(string $outputPath, array $plan, array $options): array
{
    $entries = [];
    $handle = fopen($outputPath, 'w+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create the UMOD-family package.');
    }

    try {
        foreach ($plan['files'] as $file) {
            $path = (string)$file['install_path'];
            $offset = ftell($handle);
            $input = fopen((string)$file['storage_path'], 'rb');
            if ($input === false) {
                throw new RuntimeException('Could not open ' . $file['original_name'] . ' for packaging.');
            }
            try {
                if (stream_copy_to_stream($input, $handle) === false) {
                    throw new RuntimeException('Could not copy ' . $file['original_name'] . ' into the package.');
                }
            } finally {
                fclose($input);
            }
            $entries[] = ['filename' => $path, 'offset' => (int)$offset, 'size' => (int)$file['file_size'], 'flags' => 0];
        }

        foreach (modpkg_umod_manifest($plan, $options) as $path => $content) {
            $offset = ftell($handle);
            $written = fwrite($handle, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException('Could not write ' . $path . ' into the package.');
            }
            $entries[] = ['filename' => $path, 'offset' => (int)$offset, 'size' => strlen($content), 'flags' => 0];
        }

        $tableOffset = ftell($handle);
        $table = modpkg_compact_index(count($entries));
        foreach ($entries as $entry) {
            $table .= modpkg_ue1_string((string)$entry['filename']);
            $table .= modpkg_pack_u32((int)$entry['offset']);
            $table .= modpkg_pack_u32((int)$entry['size']);
            $table .= modpkg_pack_u32((int)$entry['flags']);
        }
        if (fwrite($handle, $table) !== strlen($table)) {
            throw new RuntimeException('Could not write the UMOD file table.');
        }
        fflush($handle);

        $beforeFooterSize = ftell($handle);
        rewind($handle);
        $hash = hash_init('crc32b');
        $remaining = $beforeFooterSize;
        while ($remaining > 0) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Could not calculate the UMOD CRC.');
            }
            hash_update($hash, $chunk);
            $remaining -= strlen($chunk);
        }
        $crc = (int)hexdec(hash_final($hash));
        $fileSize = $beforeFooterSize + 20;
        fseek($handle, 0, SEEK_END);
        $footer = modpkg_pack_u32(0x9FE3C5A3)
            . modpkg_pack_u32((int)$tableOffset)
            . modpkg_pack_u32((int)$fileSize)
            . modpkg_pack_u32(1)
            . modpkg_pack_u32($crc);
        if (fwrite($handle, $footer) !== 20) {
            throw new RuntimeException('Could not write the UMOD footer.');
        }
    } finally {
        fclose($handle);
    }

    $validation = modpkg_validate_umod($outputPath);
    if (!$validation['ok']) {
        throw new RuntimeException('Generated UMOD validation failed: ' . implode('; ', $validation['errors']));
    }
    return $validation;
}

function modpkg_validate_umod(string $path): array
{
    $errors = [];
    $data = file_get_contents($path);
    if ($data === false || strlen($data) < 20) {
        return ['ok' => false, 'errors' => ['Package is too small']];
    }

    $fileSize = strlen($data);
    $footer = unpack('Vmagic/Vtable/Vsize/Vversion/Vcrc', substr($data, -20));
    if ((int)$footer['magic'] !== 0x9FE3C5A3) {
        $errors[] = 'Bad archive magic';
    }
    if ((int)$footer['version'] !== 1) {
        $errors[] = 'Unsupported archive version';
    }
    if ((int)$footer['size'] !== $fileSize) {
        $errors[] = 'Archive size footer mismatch';
    }
    if ((int)$footer['table'] < 0 || (int)$footer['table'] >= $fileSize - 20) {
        $errors[] = 'Bad archive table offset';
    }
    $actualCrc = (int)hexdec(hash('crc32b', substr($data, 0, -20)));
    if (($actualCrc & 0xFFFFFFFF) !== ((int)$footer['crc'] & 0xFFFFFFFF)) {
        $errors[] = 'Archive CRC mismatch';
    }

    $entries = [];
    if (!$errors) {
        try {
            $offset = (int)$footer['table'];
            $count = modpkg_read_compact_index($data, $offset);
            if ($count < 0 || $count > 100000) {
                throw new RuntimeException('Invalid archive item count.');
            }
            for ($i = 0; $i < $count; $i++) {
                $filename = modpkg_read_ue1_string($data, $offset);
                if ($offset + 12 > $fileSize - 20) {
                    throw new RuntimeException('Truncated archive item.');
                }
                $item = unpack('Voffset/Vsize/Vflags', substr($data, $offset, 12));
                $offset += 12;
                if ((int)$item['offset'] < 0 || (int)$item['size'] < 0 || (int)$item['offset'] + (int)$item['size'] > (int)$footer['table']) {
                    throw new RuntimeException('Archive item points outside the payload: ' . $filename);
                }
                $entries[] = ['filename' => $filename, 'offset' => (int)$item['offset'], 'size' => (int)$item['size'], 'flags' => (int)$item['flags']];
            }
            if (!in_array('System/Manifest.ini', array_column($entries, 'filename'), true)) {
                $errors[] = 'Manifest.ini is missing';
            }
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }

    return ['ok' => !$errors, 'errors' => $errors, 'entries' => $entries, 'file_count' => count($entries)];
}

function modpkg_ue4_string(string $value): string
{
    if (str_contains($value, "\0")) {
        throw new RuntimeException('PAK strings may not contain NUL bytes.');
    }
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        $utf16 = function_exists('mb_convert_encoding') ? mb_convert_encoding($value . "\0", 'UTF-16LE', 'UTF-8') : false;
        if ($utf16 === false) {
            throw new RuntimeException('PAK paths must be ASCII when mbstring is unavailable.');
        }
        $characters = intdiv(strlen($utf16), 2);
        return pack('V', (-$characters) & 0xFFFFFFFF) . $utf16;
    }
    $bytes = $value . "\0";
    return pack('V', strlen($bytes)) . $bytes;
}

function modpkg_read_ue4_string(string $data, int &$offset): string
{
    if ($offset + 4 > strlen($data)) {
        throw new RuntimeException('Truncated PAK string length.');
    }
    $rawLength = unpack('V', substr($data, $offset, 4))[1];
    $offset += 4;
    $length = $rawLength >= 0x80000000 ? $rawLength - 0x100000000 : $rawLength;
    if ($length < 0) {
        $bytes = -$length * 2;
        if ($offset + $bytes > strlen($data)) {
            throw new RuntimeException('Truncated PAK Unicode string.');
        }
        $raw = substr($data, $offset, $bytes);
        $offset += $bytes;
        $decoded = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : $raw;
        return rtrim($decoded, "\0");
    }
    if ($offset + $length > strlen($data)) {
        throw new RuntimeException('Truncated PAK string.');
    }
    $raw = substr($data, $offset, $length);
    $offset += $length;
    return rtrim($raw, "\0");
}

function modpkg_pak_entry_bytes(int $offset, int $size, string $sha1, int $version): string
{
    if (strlen($sha1) !== 20) {
        throw new RuntimeException('PAK entry SHA1 must be 20 bytes.');
    }
    $entry = modpkg_pack_i64($offset)
        . modpkg_pack_i64($size)
        . modpkg_pack_i64($size)
        . pack('V', 0)
        . $sha1;
    if ($version >= 3) {
        $entry .= chr(0);
        $entry .= pack('V', 0);
    }
    return $entry;
}

function modpkg_pak_entry_size(int $version): int
{
    return $version >= 3 ? 53 : 48;
}

function modpkg_write_pak(string $outputPath, array $plan, array $options, array $settings): array
{
    $inferredPaths = array_filter($plan['files'], static fn(array $file): bool => !empty($file['install_path_inferred']));
    if ($inferredPaths) {
        throw new RuntimeException('UT4 PAK export requires recorded game-relative source paths for every asset. Re-scan the source folders before exporting.');
    }

    $version = 3;
    $mountPoint = modpkg_normalize_mount_point((string)$settings['ut4_mount_point']);
    $handle = fopen($outputPath, 'w+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create the PAK file.');
    }

    $entries = [];
    try {
        foreach ($plan['files'] as $file) {
            $path = modpkg_normalize_relative_path((string)$file['install_path']);
            if (str_starts_with(strtolower($path), 'unrealtournament/content/')) {
                $path = substr($path, strlen('UnrealTournament/Content/'));
            }
            if ($path === '') {
                throw new RuntimeException('Could not determine a PAK path for ' . $file['original_name']);
            }

            $entryOffset = ftell($handle);
            $size = (int)$file['file_size'];
            $sha1Binary = hash_file('sha1', (string)$file['storage_path'], true);
            if ($sha1Binary === false) {
                throw new RuntimeException('Could not hash ' . $file['original_name'] . ' for the PAK.');
            }
            $entryHeader = modpkg_pak_entry_bytes((int)$entryOffset, $size, $sha1Binary, $version);
            if (fwrite($handle, $entryHeader) !== strlen($entryHeader)) {
                throw new RuntimeException('Could not write a PAK entry header.');
            }
            $input = fopen((string)$file['storage_path'], 'rb');
            if ($input === false) {
                throw new RuntimeException('Could not open ' . $file['original_name'] . ' for packaging.');
            }
            try {
                if (stream_copy_to_stream($input, $handle) === false) {
                    throw new RuntimeException('Could not copy ' . $file['original_name'] . ' into the PAK.');
                }
            } finally {
                fclose($input);
            }
            $entries[] = ['filename' => $path, 'offset' => (int)$entryOffset, 'size' => $size, 'sha1' => $sha1Binary];
        }

        $manifest = modpkg_json(modpkg_metadata($plan, $options));
        $manifestPath = 'UnrealDB/' . modpkg_safe_component((string)$options['name']) . '/UnrealDB-Mod.json';
        $manifestOffset = ftell($handle);
        $manifestHash = hash('sha1', $manifest, true);
        $manifestHeader = modpkg_pak_entry_bytes((int)$manifestOffset, strlen($manifest), $manifestHash, $version);
        if (fwrite($handle, $manifestHeader) !== strlen($manifestHeader) || fwrite($handle, $manifest) !== strlen($manifest)) {
            throw new RuntimeException('Could not write the PAK manifest.');
        }
        $entries[] = ['filename' => $manifestPath, 'offset' => (int)$manifestOffset, 'size' => strlen($manifest), 'sha1' => $manifestHash];

        usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['filename'], (string)$b['filename']));
        $indexOffset = ftell($handle);
        $index = modpkg_ue4_string($mountPoint) . pack('V', count($entries));
        foreach ($entries as $entry) {
            $index .= modpkg_ue4_string((string)$entry['filename']);
            $index .= modpkg_pak_entry_bytes((int)$entry['offset'], (int)$entry['size'], (string)$entry['sha1'], $version);
        }
        if (fwrite($handle, $index) !== strlen($index)) {
            throw new RuntimeException('Could not write the PAK index.');
        }
        $indexSize = strlen($index);
        $indexHash = hash('sha1', $index, true);
        $footer = pack('V', 0x5A6F12E1)
            . pack('V', $version)
            . modpkg_pack_i64((int)$indexOffset)
            . modpkg_pack_i64($indexSize)
            . $indexHash;
        if (fwrite($handle, $footer) !== 44) {
            throw new RuntimeException('Could not write the PAK footer.');
        }
    } finally {
        fclose($handle);
    }

    $validation = modpkg_validate_pak($outputPath);
    if (!$validation['ok']) {
        throw new RuntimeException('Generated PAK validation failed: ' . implode('; ', $validation['errors']));
    }
    return $validation;
}

function modpkg_validate_pak(string $path): array
{
    $errors = [];
    $data = file_get_contents($path);
    if ($data === false || strlen($data) < 44) {
        return ['ok' => false, 'errors' => ['PAK is too small']];
    }
    $footerOffset = strlen($data) - 44;
    $magic = unpack('V', substr($data, $footerOffset, 4))[1];
    $version = unpack('V', substr($data, $footerOffset + 4, 4))[1];
    $indexOffset = modpkg_unpack_i64($data, $footerOffset + 8);
    $indexSize = modpkg_unpack_i64($data, $footerOffset + 16);
    $indexHash = substr($data, $footerOffset + 24, 20);
    if ($magic !== 0x5A6F12E1) {
        $errors[] = 'Bad PAK magic';
    }
    if ($version !== 3) {
        $errors[] = 'Unsupported PAK version';
    }
    if ($indexOffset < 0 || $indexSize < 0 || $indexOffset + $indexSize !== $footerOffset) {
        $errors[] = 'Invalid PAK index bounds';
    }

    $entries = [];
    $mountPoint = '';
    if (!$errors) {
        $index = substr($data, $indexOffset, $indexSize);
        if (!hash_equals($indexHash, hash('sha1', $index, true))) {
            $errors[] = 'PAK index SHA1 mismatch';
        } else {
            try {
                $offset = 0;
                $mountPoint = modpkg_read_ue4_string($index, $offset);
                if ($offset + 4 > strlen($index)) {
                    throw new RuntimeException('Truncated PAK entry count.');
                }
                $count = unpack('V', substr($index, $offset, 4))[1];
                $offset += 4;
                if ($count > 1000000) {
                    throw new RuntimeException('Invalid PAK entry count.');
                }
                $entrySize = modpkg_pak_entry_size((int)$version);
                for ($i = 0; $i < $count; $i++) {
                    $filename = modpkg_read_ue4_string($index, $offset);
                    if ($offset + $entrySize > strlen($index)) {
                        throw new RuntimeException('Truncated PAK entry.');
                    }
                    $entryOffset = modpkg_unpack_i64($index, $offset);
                    $size = modpkg_unpack_i64($index, $offset + 8);
                    $uncompressed = modpkg_unpack_i64($index, $offset + 16);
                    $compression = unpack('V', substr($index, $offset + 24, 4))[1];
                    $sha1 = substr($index, $offset + 28, 20);
                    $offset += $entrySize;
                    if ($compression !== 0 || $size !== $uncompressed) {
                        throw new RuntimeException('Generated PAK contains an unexpected compressed entry.');
                    }
                    if ($entryOffset < 0 || $entryOffset + $entrySize + $size > $indexOffset) {
                        throw new RuntimeException('PAK entry points outside the data area: ' . $filename);
                    }
                    $payload = substr($data, $entryOffset + $entrySize, $size);
                    if (!hash_equals($sha1, hash('sha1', $payload, true))) {
                        throw new RuntimeException('PAK entry SHA1 mismatch: ' . $filename);
                    }
                    $entries[] = ['filename' => $filename, 'offset' => $entryOffset, 'size' => $size];
                }
            } catch (Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
    }

    return ['ok' => !$errors, 'errors' => $errors, 'version' => $version, 'mount_point' => $mountPoint, 'entries' => $entries, 'file_count' => count($entries)];
}

function modpkg_default_options(array $plan, array $settings, array $input = []): array
{
    $rootStem = catalog_clean_unreal_package_stem((string)$plan['root']['package_name']);
    $name = modpkg_safe_component((string)($input['name'] ?? $rootStem), $rootStem);
    $version = trim((string)($input['version'] ?? '1.0')) ?: '1.0';
    $author = trim((string)($input['author'] ?? $settings['default_author'])) ?: (string)$settings['default_author'];
    return ['name' => $name, 'version' => $version, 'author' => $author];
}

function modpkg_extension(string $format): string
{
    return match ($format) {
        MODPKG_FORMAT_UMOD => 'umod',
        MODPKG_FORMAT_UT2MOD => 'ut2mod',
        MODPKG_FORMAT_UT4MOD => 'ut4mod',
        MODPKG_FORMAT_UT4_PAK => 'pak',
        default => 'zip',
    };
}

function modpkg_download_name(string $format, array $options): string
{
    $suffix = match ($format) {
        MODPKG_FORMAT_UT3_ZIP => '-UT3',
        MODPKG_FORMAT_UT4_PAK => '-UT4',
        MODPKG_FORMAT_DEPENDENCY_ZIP => '-with-dependencies',
        default => '',
    };
    return modpkg_safe_component((string)$options['name']) . $suffix . '.' . modpkg_extension($format);
}

function modpkg_build(string $outputPath, array $plan, array $options, array $settings): array
{
    return match ((string)$plan['format']) {
        MODPKG_FORMAT_DEPENDENCY_ZIP, MODPKG_FORMAT_UT3_ZIP => modpkg_write_zip($outputPath, $plan, $options),
        MODPKG_FORMAT_UMOD, MODPKG_FORMAT_UT2MOD, MODPKG_FORMAT_UT4MOD => modpkg_write_umod($outputPath, $plan, $options),
        MODPKG_FORMAT_UT4_PAK => modpkg_write_pak($outputPath, $plan, $options, $settings),
        default => throw new RuntimeException('Unsupported package format.'),
    };
}
