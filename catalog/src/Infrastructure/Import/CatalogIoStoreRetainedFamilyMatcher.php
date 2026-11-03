<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;

/**
 * Exact, physical comparison of a complete public IoStore TOC/partition family
 * against already-retained game containers. A single matching .utoc is never
 * sufficient: the full named/ordered .ucas family must match as well.
 */
final class CatalogIoStoreRetainedFamilyMatcher
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /**
     * @param list<array<string,mixed>> $components
     * @return array{container_id:int,game_id:int,game_name:string}|null
     */
    public function find(array $components): ?array
    {
        $toc = null;
        $byFilename = [];
        foreach ($components as $component) {
            $name = basename((string)($component['name'] ?? $component['original_name'] ?? ''));
            $nameKey = strtolower($name);
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['utoc', 'ucas'], true)
                || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(?:utoc|ucas)$/iD', $name) !== 1
                || isset($byFilename[$nameKey])
                || (int)($component['size'] ?? $component['file_size'] ?? 0) < 1
                || preg_match('/^[a-f0-9]{32}$/D', strtolower((string)($component['md5'] ?? $component['server_md5'] ?? ''))) !== 1
                || preg_match('/^[a-f0-9]{40}$/D', strtolower((string)($component['sha1'] ?? $component['server_sha1'] ?? ''))) !== 1) {
                return null;
            }
            $byFilename[$nameKey] = $component;
            if ($ext === 'utoc') {
                if ($toc !== null) return null;
                $toc = $component;
            }
        }
        if ($toc === null || count($byFilename) < 2) return null;

        $tocName = basename((string)($toc['name'] ?? $toc['original_name'] ?? ''));
        $base = substr($tocName, 0, -5);
        $root = realpath((string)($this->config['storage_path'] ?? ''));
        if ($root === false) return null;

        $q = $this->db->prepare(
            'SELECT c.id,c.game_id,g.name game_name,c.utoc_relative_path,c.utoc_sha256 '
            . 'FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id '
            . 'WHERE c.name=? AND c.status="ready" ORDER BY c.id DESC LIMIT 100'
        );
        $q->execute([$tocName]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            $id = (int)$candidate['id'];
            $path = $this->retainedPath($root, (string)$candidate['utoc_relative_path']);
            if ($path === null || !$this->sameBytes($path, $toc, (string)$candidate['utoc_sha256'])) continue;
            $partsQuery = $this->db->prepare(
                'SELECT part_index,relative_path,file_size,sha256 FROM ue_iostore_container_parts '
                . 'WHERE container_id=? ORDER BY part_index'
            );
            $partsQuery->execute([$id]);
            $parts = $partsQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($parts) !== count($byFilename) - 1) continue;
            $complete = true;
            foreach ($parts as $i => $part) {
                $expected = strtolower($base . ($i === 0 ? '' : '_s' . $i) . '.ucas');
                $partPath = $this->retainedPath($root, (string)$part['relative_path']);
                if ((int)$part['part_index'] !== $i
                    || !isset($byFilename[$expected])
                    || strtolower(basename((string)$part['relative_path'])) !== $expected
                    || (int)$part['file_size'] !== (int)($byFilename[$expected]['size'] ?? $byFilename[$expected]['file_size'] ?? 0)
                    || $partPath === null
                    || !$this->sameBytes($partPath, $byFilename[$expected], (string)$part['sha256'])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return ['container_id'=>$id,'game_id'=>(int)$candidate['game_id'],
                    'game_name'=>(string)$candidate['game_name']];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $component */
    private function sameBytes(string $path, array $component, string $storedSha256): bool
    {
        $size = (int)($component['size'] ?? $component['file_size'] ?? 0);
        if (filesize($path) !== $size
            || preg_match('/^[a-f0-9]{64}$/D', strtolower($storedSha256)) !== 1) return false;
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return false;
        $md5 = hash_init('md5');
        $sha1 = hash_init('sha1');
        $sha256 = hash_init('sha256');
        try {
            while (!feof($handle)) {
                $bytes = fread($handle, 1048576);
                if (!is_string($bytes) || ($bytes === '' && !feof($handle))) return false;
                if ($bytes === '') break;
                hash_update($md5, $bytes);
                hash_update($sha1, $bytes);
                hash_update($sha256, $bytes);
            }
        } finally {
            fclose($handle);
        }
        return hash_equals(strtolower($storedSha256), hash_final($sha256))
            && hash_equals(strtolower((string)($component['md5'] ?? $component['server_md5'] ?? '')), hash_final($md5))
            && hash_equals(strtolower((string)($component['sha1'] ?? $component['server_sha1'] ?? '')), hash_final($sha1));
    }

    private function retainedPath(string $root, string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if (!str_starts_with($relative, 'games/') || str_contains($relative, '..')
            || str_contains($relative, ':') || str_contains($relative, '//')) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $prefix = strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/');
        if ($path === false || !str_starts_with(strtolower(str_replace('\\', '/', $path)), $prefix)
            || !is_file($path) || is_link($path) || !is_readable($path)) return null;
        return $path;
    }
}
