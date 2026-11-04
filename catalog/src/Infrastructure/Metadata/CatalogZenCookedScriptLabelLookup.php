<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;

/**
 * Display-only cooked ScriptObjects labels for Zen FPackageObjectIndex hashes.
 * Cooked ScriptObjects are diagnostic data, NOT evidence of runtime registration,
 * loading, mount priority, or a resolved dependency.
 */
final class CatalogZenCookedScriptLabelLookup
{
    /** @var array<string,array<string,string>> */
    private static array $requestCache = [];

    /**
     * @param list<string> $rawIndices Full 64-bit serialized ScriptImport indexes
     * @return array<string,string> Exact raw index => source-backed cooked object path
     */
    public static function labels(PDO $db, string $storageRoot, int $gameId, array $rawIndices): array
    {
        $wanted = [];
        foreach ($rawIndices as $raw) {
            $raw = strtoupper((string)$raw);
            if (preg_match('/^[0-9A-F]{16}$/D', $raw) === 1) {
                $wanted[$raw] = true;
            }
        }
        if ($gameId < 1 || $wanted === []) return [];

        // A game with ambiguous global ScriptObjects sources must not quietly
        // select the first one. Do not infer runtime mount precedence.
        $q = $db->prepare('SELECT id,utoc_relative_path,utoc_sha256 FROM ue_iostore_containers '
            . 'WHERE game_id=? AND LOWER(name)=? AND status="ready" ORDER BY id LIMIT 2');
        $q->execute([$gameId, 'global.utoc']);
        $containers = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($containers) !== 1) return [];
        $container = $containers[0];
        $hash = strtolower((string)$container['utoc_sha256']);
        $relative = str_replace('\\', '/', (string)$container['utoc_relative_path']);
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || !str_starts_with($relative, 'games/')
            || str_contains($relative, '..')
            || str_contains($relative, ':')
            || str_contains($relative, '//')) return [];

        $root = realpath($storageRoot);
        if ($root === false) return [];
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $rootPrefix = strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/');
        if ($path === false || !str_starts_with(strtolower(str_replace('\\', '/', $path)), $rootPrefix)
            || is_link($path) || !is_file($path) || !is_readable($path)
            || !hash_equals($hash, strtolower((string)hash_file('sha256', $path)))) return [];

        $key = $gameId . ':' . (int)$container['id'] . ':' . $hash;
        if (!isset(self::$requestCache[$key])) {
            self::$requestCache[$key] = self::readOrBuild($root, $path, $key, $hash);
        }
        return array_intersect_key(self::$requestCache[$key], $wanted);
    }

    /** @return array<string,string> */
    private static function readOrBuild(string $root, string $path, string $key, string $sha): array
    {
        $cacheDirectory = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'zen-script-labels';
        $filename = $cacheDirectory . DIRECTORY_SEPARATOR . str_replace(':', '-', $key) . '.json';
        if (is_file($filename) && !is_link($filename) && filesize($filename) < 16777216) {
            $decoded = json_decode((string)file_get_contents($filename), true);
            if (is_array($decoded) && ($decoded['source_sha256'] ?? null) === $sha
                && is_array($decoded['labels'] ?? null)) {
                return (array)$decoded['labels'];
            }
        }
        $toc = new Uedb5IoStoreTocReader($path);
        if (!hash_equals($sha, strtolower($toc->tocSha256()))) return [];
        $parsed = Uedb5ZenScriptObjectsReader::fromToc($toc);
        if (empty($parsed['present'])) return [];
        $objects = (array)$parsed['objects'];
        $labels = [];
        foreach (array_keys($objects) as $index) {
            $pathLabel = self::scriptPath((string)$index, $objects);
            if ($pathLabel !== null) $labels[(string)$index] = $pathLabel;
        }
        // A small source-sha-keyed cache prevents reparsing 55k cooked objects
        // for every paged examiner view. Cache failure is safe and nonfatal.
        if ((is_dir($cacheDirectory) || @mkdir($cacheDirectory, 0700, true))
            && is_dir($cacheDirectory)) {
            $json = json_encode(['source_sha256'=>$sha,'labels'=>$labels], JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json) && strlen($json) < 16777216) {
                $temp = @tempnam($cacheDirectory, 'zen-labels-');
                if (is_string($temp)) {
                    if (@file_put_contents($temp, $json, LOCK_EX) === strlen($json)) {
                        @rename($temp, $filename);
                    }
                    if (is_file($temp)) @unlink($temp);
                }
            }
        }
        return $labels;
    }

    /** @var array<string,array<string,array<string,string>>> */
    private static array $metadataCache = [];

    /**
     * Display-only details taken directly from the retained Epic ScriptObjects
     * records. An outer relationship is serialized; the CDO class field is
     * populated only for class-default objects. Neither implies runtime loading.
     *
     * @param list<string> $rawIndices
     * @return array<string,array<string,string>>
     */
    public static function describe(PDO $db, string $storageRoot, int $gameId, array $rawIndices): array
    {
        $labels = self::labels($db, $storageRoot, $gameId, $rawIndices);
        if ($labels === []) return [];
        $q = $db->prepare('SELECT id,utoc_relative_path,utoc_sha256 FROM ue_iostore_containers '
            . 'WHERE game_id=? AND LOWER(name)=? AND status="ready" ORDER BY id LIMIT 2');
        $q->execute([$gameId, 'global.utoc']);
        $containers = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($containers) !== 1) return [];
        $container = $containers[0];
        $hash = strtolower((string)$container['utoc_sha256']);
        $key = $gameId . ':' . (int)$container['id'] . ':' . $hash;
        if (!isset(self::$requestCache[$key])) return [];

        if (!isset(self::$metadataCache[$key])) {
            $relative = str_replace('\\', '/', (string)$container['utoc_relative_path']);
            $root = realpath($storageRoot);
            if ($root === false || !str_starts_with($relative, 'games/')
                || str_contains($relative, '..') || str_contains($relative, ':')
                || str_contains($relative, '//')) return [];
            $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $prefix = strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/');
            if ($path === false || !str_starts_with(strtolower(str_replace('\\', '/', $path)), $prefix)
                || !is_file($path) || is_link($path)) return [];

            $dir = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'cache'
                . DIRECTORY_SEPARATOR . 'zen-script-labels';
            $cache = $dir . DIRECTORY_SEPARATOR . str_replace(':', '-', $key) . '-records.json';
            $records = null;
            if (is_file($cache) && !is_link($cache) && filesize($cache) < 16777216) {
                $cached = json_decode((string)file_get_contents($cache), true);
                if (is_array($cached) && ($cached['source_sha256'] ?? null) === $hash
                    && is_array($cached['records'] ?? null)) {
                    $records = $cached['records'];
                }
            }
            if ($records === null) {
                $toc = new Uedb5IoStoreTocReader($path);
                if (!hash_equals($hash, strtolower($toc->tocSha256()))) return [];
                $parsed = Uedb5ZenScriptObjectsReader::fromToc($toc);
                if (empty($parsed['present'])) return [];
                $records = [];
                foreach ((array)$parsed['objects'] as $index => $record) {
                    $records[(string)$index] = [
                        'name'=>(string)$record['name'],
                        'outer_index'=>(string)$record['outer_index'],
                        'cdo_class_index'=>(string)$record['cdo_class_index'],
                    ];
                }
                if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_dir($dir)) {
                    $json = json_encode(['source_sha256'=>$hash,'records'=>$records], JSON_INVALID_UTF8_SUBSTITUTE);
                    if (is_string($json) && strlen($json) < 16777216) {
                        $temp = @tempnam($dir, 'zen-records-');
                        if (is_string($temp)) {
                            if (@file_put_contents($temp, $json, LOCK_EX) === strlen($json)) {
                                @rename($temp, $cache);
                            }
                            if (is_file($temp)) @unlink($temp);
                        }
                    }
                }
            }
            self::$metadataCache[$key] = $records;
        }

        $allLabels = self::$requestCache[$key];
        $out = [];
        foreach ($labels as $index => $path) {
            $record = self::$metadataCache[$key][$index] ?? null;
            if (!is_array($record)) continue;
            $outer = strtoupper((string)($record['outer_index'] ?? ''));
            $class = strtoupper((string)($record['cdo_class_index'] ?? ''));
            $dot = strpos($path, '.');
            $module = $dot === false ? $path : substr($path, 0, $dot);
            if (!str_starts_with($module, '/Script/')) $module = '';
            $out[$index] = [
                'path'=>$path,
                'name'=>(string)$record['name'],
                'module'=>$module,
                'outer_index'=>$outer,
                'outer_path'=>$allLabels[$outer] ?? '',
                'cdo_class_index'=>$class,
                'cdo_class_path'=>$allLabels[$class] ?? '',
                'source'=>'cooked_script_objects_chunk',
            ];
        }
        return $out;
    }

    /** @param array<string,array<string,mixed>> $objects */
    private static function scriptPath(string $index, array $objects): ?string
    {
        $parts = [];
        $visited = [];
        for ($depth = 0; $depth < 128; $depth++) {
            if (isset($visited[$index]) || !isset($objects[$index])) return null;
            $visited[$index] = true;
            $row = $objects[$index];
            $name = (string)($row['name'] ?? '');
            if ($name === '') return null;
            $parts[] = $name;
            $outer = strtoupper((string)($row['outer_index'] ?? ''));
            if ($outer === 'FFFFFFFFFFFFFFFF') {
                return implode('.', array_reverse($parts));
            }
            if (preg_match('/^[0-9A-F]{16}$/D', $outer) !== 1) return null;
            $index = $outer;
        }
        return null;
    }
}
