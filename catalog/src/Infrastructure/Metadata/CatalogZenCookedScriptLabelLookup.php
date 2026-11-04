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
