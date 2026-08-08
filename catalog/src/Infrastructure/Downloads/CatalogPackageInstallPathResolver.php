<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves generated-package install paths and verified storage payload paths.
 * Why: Source-location lookup, engine-specific install-path mapping and storage containment are one infrastructure concern.
 * Role: Downloads infrastructure collaborator used by package planning.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;

final class CatalogPackageInstallPathResolver
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    public static function normalizeRelativePath(string $path): string
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

    public static function normalizeMountPoint(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        if ($path === '') {
            $path = '../../../UnrealTournament/Content/';
        }
        return rtrim($path, '/') . '/';
    }

    public static function safeComponent(string $value, string $fallback = 'package'): string
    {
        $value = trim(str_replace(["\0", '/', '\\'], ['', '_', '_'], $value));
        $value = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value, " .\t\r\n");
        return $value !== '' ? $value : $fallback;
    }

    public static function knownPathFromSource(string $sourcePath, string $engine): ?string
    {
        $path = self::normalizeRelativePath($sourcePath);
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

        $roots = [
            'system', 'maps', 'textures', 'sounds', 'music', 'staticmeshes',
            'animations', 'karmadata', 'web', 'help', 'benchmark', 'speech',
        ];
        foreach ($roots as $root) {
            $index = array_search($root, $lower, true);
            if ($index === false) {
                continue;
            }
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
        return null;
    }

    /** @param array<string,mixed> $file */
    public static function fallbackInstallPath(array $file, string $engine, string $format): string
    {
        $name = \catalog_clean_unreal_filename((string)$file['original_name']);
        $extension = strtolower((string)($file['extension'] ?? pathinfo($name, PATHINFO_EXTENSION)));

        if ($format === 'ut3_zip' || $engine === 'UE3') {
            if ($extension === 'ini') {
                return 'UTGame/Config/' . $name;
            }
            if (in_array(
                $extension,
                ['int', 'det', 'est', 'frt', 'itt', 'deu', 'fra', 'ita', 'jpn', 'kor', 'rus', 'spa'],
                true
            )) {
                return 'UTGame/Localization/INT/' . $name;
            }
            return 'UTGame/Published/CookedPC/CustomMaps/' . $name;
        }

        if ($format === 'ut4_pak' || $engine === 'UE4') {
            $stem = self::safeComponent(
                (string)($file['package_name'] ?? pathinfo($name, PATHINFO_FILENAME)),
                'Package'
            );
            return 'UnrealDB/' . $stem . '/' . $name;
        }

        $folder = match ($extension) {
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

    public function sourceLocation(int $fileId): ?string
    {
        $row = \catalog_one(
            $this->db,
            'SELECT l.source_relative_path '
            . 'FROM ue_file_locations l '
            . 'JOIN ue_sources s ON s.id=l.source_id '
            . 'WHERE l.file_id=? AND l.exists_in_source=1 '
            . 'ORDER BY (s.source_type="local_path") DESC,l.last_seen_at DESC,l.id ASC LIMIT 1',
            [$fileId]
        );
        return $row ? (string)$row['source_relative_path'] : null;
    }

    /** @param array<string,mixed> $file @param array<string,mixed> $game @return array{path:string,source_relative_path:?string,path_inferred:bool} */
    public function installPath(array $file, array $game, string $format): array
    {
        $engine = strtoupper((string)($game['engine_key'] ?? ''));
        $source = $this->sourceLocation((int)$file['id']);
        $path = $source !== null ? self::knownPathFromSource($source, $engine) : null;
        $inferred = false;
        if ($path === null || $path === '') {
            $path = self::fallbackInstallPath($file, $engine, $format);
            $inferred = true;
        }
        return [
            'path' => self::normalizeRelativePath($path),
            'source_relative_path' => $source,
            'path_inferred' => $inferred,
        ];
    }

    /** @param array<string,mixed> $file */
    public function storagePath(array $file): string
    {
        $relative = ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
        $catalogRoot = dirname(__DIR__, 3);
        $storageRoot = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
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
                if (!str_starts_with($normalizedReal . '/', $normalizedRoot)
                    && $normalizedReal !== rtrim($normalizedRoot, '/')) {
                    continue;
                }
            }
            return $real;
        }

        throw new RuntimeException(
            'Stored file missing for ' . \catalog_clean_unreal_filename((string)$file['original_name'])
        );
    }
}
