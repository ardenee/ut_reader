<?php
/**
 * Current-policy file-level dependency totals for the public Games page.
 *
 * One file contributes at most one to either count. A file with zero source
 * references is never "resolved". The owning game must already have passed
 * the exact per-engine v14/payload freshness gate before this reader is used.
 *
 * Aggregate results are cached by game and source statistics epoch; any new
 * dependency publication/statistics rebuild changes that epoch. Cache misses
 * are SELECT-only and cannot promote unknown provider mounts to resolved.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoGameDependencyFileCountQuery
{
    private const CACHE_VERSION = 3;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
    }

    /** @return array{unresolved_files:int,resolved_files:int,dependency_count?:int,unresolved_references?:int}|null */
    public function forCurrentGame(int $gameId, string $engine, string $statsUpdatedAt): ?array
    {
        $engine = strtoupper(trim($engine));
        if ($gameId < 1 || !in_array($engine, ['UE1','UE2','UE3','UE4','UE5'], true)
            || trim($statsUpdatedAt) === '') {
            return null;
        }

        // A later IoStore/container or ScriptObjects import can change
        // catalogue coverage without changing a prior dependency publication.
        $zenContext = $engine === 'UE5' ? $this->zenContext($gameId) : null;
        $signature = hash('sha256', implode('|', [
            (string)self::CACHE_VERSION, (string)$gameId, $engine, $statsUpdatedAt,
            (string)($zenContext['epoch'] ?? ''),
        ]));
        $directory = $this->cacheDirectory();
        if ($directory === null) {
            return $this->aggregate($gameId, $engine, $zenContext);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'game-' . $gameId . '.json';
        $cached = $this->readCache($path, $signature);
        if ($cached !== null) {
            return $cached;
        }

        $lock = @fopen($path . '.lock', 'c+b');
        if (!is_resource($lock)) {
            return $this->aggregate($gameId, $engine, $zenContext);
        }
        try {
            if (!@flock($lock, LOCK_EX)) {
                return $this->aggregate($gameId, $engine, $zenContext);
            }
            $cached = $this->readCache($path, $signature);
            if ($cached !== null) {
                return $cached;
            }
            $counts = $this->aggregate($gameId, $engine, $zenContext);
            $this->writeCache($path, $signature, $counts);
            return $counts;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * @return array{unresolved_files:int,resolved_files:int,dependency_count?:int,unresolved_references?:int}
     * @param array{global_id:int,global_sha:string,epoch:string}|null $zenContext
     */
    private function aggregate(int $gameId, string $engine, ?array $zenContext): array
    {
        // Current source-only provider selection is verified in the caller.
        // For classic packages, common/package-only references are satisfied
        // for catalogue completion, even without a unique mounted provider.
        $sql = 'SELECT COALESCE(SUM(x.unresolved>0),0) unresolved_files,'
            . 'COALESCE(SUM(x.unresolved=0 AND x.missing=0),0) resolved_files '
            . 'FROM (SELECT p.file_id,SUM(p.unresolved_count) unresolved,'
            . 'SUM(p.missing_count) missing '
            . 'FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'AND f.scan_status="verified" AND f.extension<>"zenpkg" '
            . 'WHERE p.game_id=? AND p.dependency_count>0 '
            . 'GROUP BY p.file_id) x';
        $statement = $this->db->prepare($sql);
        $statement->execute([$gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = [
            'unresolved_files' => max(0, (int)($row['unresolved_files'] ?? 0)),
            'resolved_files' => max(0, (int)($row['resolved_files'] ?? 0)),
        ];
        if ($engine !== 'UE5') {
            return $counts;
        }

        // Unlike classic files, Zen's original source v14 publication does not
        // know the running game's actual mounted package store or live
        // ScriptObjects registration. For the *catalogue* count, verify exact
        // available identities in current indexed IoStore and global.utoc.
        // This does NOT change the source-parity projections or assert a
        // runtime mount. A target not found in this catalogue remains
        // unresolved: it may be in a not-yet-imported or base-game container.
        $context = $zenContext ?? $this->zenContext($gameId);
        $scriptContainerId = (int)$context['global_id'];
        $scriptSha = (string)$context['global_sha'];

        $sql = 'SELECT COALESCE(SUM(x.deps),0) dependency_count,'
            . 'COALESCE(SUM(x.unresolved),0) unresolved_references,'
            . 'COALESCE(SUM(x.unresolved>0),0) unresolved_files,'
            . 'COALESCE(SUM(x.unresolved=0 AND x.deps>0),0) resolved_files '
            . 'FROM (SELECT e.file_id,COUNT(*) deps,'
            . 'SUM(CASE WHEN e.outcome=0 THEN 1 WHEN e.outcome<>4 THEN 0 '
            . 'WHEN e.required_object_key_kind=3 '
            . ' AND so.object_index IS NOT NULL THEN 0 '
            . 'WHEN e.required_package_key_kind=2 '
            . ' AND z.file_id IS NOT NULL '
            . ' AND e.required_object_key_kind IS NULL THEN 0 '
            . 'WHEN e.required_package_key_kind=2 '
            . ' AND z.file_id IS NOT NULL '
            . ' AND e.required_object_key_kind=2 AND o.file_id IS NOT NULL THEN 0 '
            . 'ELSE 1 END) unresolved '
            . 'FROM ue_files f '
            . 'JOIN ue_uedb5_dependency_edges e ON e.file_id=f.id '
            . 'LEFT JOIN ue_iostore_packages p ON p.package_id=e.required_package_key '
            . 'AND e.required_package_key_kind=2 AND p.chunk_index=0 '
            . 'AND p.publication_status="published" '
            . 'LEFT JOIN ue_iostore_containers c ON c.id=p.container_id '
            . 'AND c.game_id=f.game_id AND c.status="ready" '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=p.file_id '
            . 'AND v.game_id=f.game_id AND v.format_version=5 '
            . 'AND v.package_family="zen-iostore" AND c.id IS NOT NULL '
            . 'LEFT JOIN ue_uedb5_zen_exam_files z ON z.file_id=v.file_id '
            . 'AND z.source_sha256=v.payload_sha256 '
            . 'LEFT JOIN ue_uedb5_compact_object_candidates o '
            . 'ON o.file_id=p.file_id AND o.object_kind=1 '
            . 'AND o.public_export_hash=e.required_object_key '
            . 'AND e.required_object_key_kind=2 AND z.file_id IS NOT NULL '
            . 'LEFT JOIN ue_iostore_zen_script_objects so '
            . 'ON so.container_id=? AND so.toc_sha256=? '
            . 'AND e.required_object_key_kind=3 '
            . 'AND so.object_index=CONCAT('
            . 'CHAR(ORD(SUBSTRING(e.required_object_key,1,1))+64 USING binary),'
            . 'SUBSTRING(e.required_object_key,2)) '
            . 'WHERE f.game_id=? AND f.scan_status="verified" '
            . 'AND f.extension="zenpkg" GROUP BY e.file_id) x';
        $statement = $this->db->prepare($sql);
        $statement->execute([$scriptContainerId, hex2bin($scriptSha), $gameId]);
        $zen = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        // Duplicate provider or export identities would multiply joined rows.
        // Fail closed instead of counting those as legitimate extra references.
        $source = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files f ON f.id=e.file_id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" '
            . 'AND f.extension="zenpkg"'
        );
        $source->execute([$gameId]);
        $expected = (int)$source->fetchColumn();
        if ((int)($zen['dependency_count'] ?? -1) !== $expected) {
            throw new \RuntimeException(
                'Zen catalogue coverage is ambiguous: indexed provider joins '
                . 'do not preserve the published source-reference cardinality.'
            );
        }

        $counts['unresolved_files'] += (int)($zen['unresolved_files'] ?? 0);
        $counts['resolved_files'] += (int)($zen['resolved_files'] ?? 0);
        // This branch is accurate for games with Zen package members. The
        // existing Satisfactory import is entirely Zen; classic UE5 mixed
        // packages require an additional reference aggregate before displaying
        // complete game-wide reference totals.
        $classic = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" AND extension<>"zenpkg"'
        );
        $classic->execute([$gameId]);
        if ((int)$classic->fetchColumn() !== 0) {
            // Count files safely but do not claim a partial Zen-only reference
            // total represents an entire mixed-format game's dependencies.
            return $counts;
        }
        $counts['dependency_count'] = $expected;
        $counts['unresolved_references'] = max(0, (int)($zen['unresolved_references'] ?? 0));
        return $counts;
    }

    /**
     * Cache catalogue results using container/index changes too, not only the
     * original per-game v14 source policy epoch.
     * @return array{global_id:int,global_sha:string,epoch:string}
     */
    private function zenContext(int $gameId): array
    {
        $q = $this->db->prepare(
            'SELECT id,name,utoc_sha256,status,updated_at,package_count '
            . 'FROM ue_iostore_containers WHERE game_id=? ORDER BY id'
        );
        $q->execute([$gameId]);
        $containers = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $globals = array_values(array_filter(
            $containers,
            static fn(array $c): bool => strtolower((string)$c['name']) === 'global.utoc'
                && (string)$c['status'] === 'ready'
        ));
        $global = count($globals) === 1 ? $globals[0] : null;
        $globalId = (int)($global['id'] ?? 0);
        $globalSha = strtolower((string)($global['utoc_sha256'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/D', $globalSha) !== 1) {
            $globalId = 0;
            $globalSha = str_repeat('0', 64);
        }

        $packages = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_iostore_packages p '
            . 'JOIN ue_iostore_containers c ON c.id=p.container_id '
            . 'WHERE c.game_id=? AND p.publication_status="published"'
        );
        $packages->execute([$gameId]);
        $published = (int)$packages->fetchColumn();
        $scripts = 0;
        if ($globalId > 0) {
            $q = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_iostore_zen_script_objects '
                . 'WHERE container_id=? AND toc_sha256=?'
            );
            $q->execute([$globalId, hex2bin($globalSha)]);
            $scripts = (int)$q->fetchColumn();
        }
        return [
            'global_id' => $globalId,
            'global_sha' => $globalSha,
            'epoch' => hash('sha256', json_encode(
                [$containers, $published, $scripts],
                JSON_THROW_ON_ERROR
            )),
        ];
    }

    private function cacheDirectory(): ?string
    {
        $cache = is_array($this->config['cache'] ?? null) ? $this->config['cache'] : [];
        $base = trim((string)($cache['path'] ?? ''));
        if ($base === '') {
            $storage = trim((string)($this->config['storage_path'] ?? ''));
            if ($storage === '') {
                return null;
            }
            $base = rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        }
        $directory = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'game-dependency-files';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        return is_writable($directory) ? $directory : null;
    }

    /** @return array{unresolved_files:int,resolved_files:int,dependency_count?:int,unresolved_references?:int}|null */
    private function readCache(string $path, string $signature): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['signature'] ?? null) !== $signature
            || !is_array($decoded['counts'] ?? null)) {
            return null;
        }
        $counts = $decoded['counts'];
        if (!isset($counts['unresolved_files'], $counts['resolved_files'])
            || !is_int($counts['unresolved_files']) || !is_int($counts['resolved_files'])
            || $counts['unresolved_files'] < 0 || $counts['resolved_files'] < 0) {
            return null;
        }
        $result = [
            'unresolved_files' => $counts['unresolved_files'],
            'resolved_files' => $counts['resolved_files'],
        ];
        // Preserve additional UE5 edge/reference counters on cache hits.
        // Previously the cached read discarded these keys, incorrectly
        // rendering Unresolved and Resolved as Unavailable after a warm load.
        if (isset($counts['dependency_count'], $counts['unresolved_references'])
            && is_int($counts['dependency_count'])
            && is_int($counts['unresolved_references'])
            && $counts['dependency_count'] >= 0
            && $counts['unresolved_references'] >= 0
            && $counts['unresolved_references'] <= $counts['dependency_count']) {
            $result['dependency_count'] = $counts['dependency_count'];
            $result['unresolved_references'] = $counts['unresolved_references'];
        }
        return $result;
    }

    /** @param array{unresolved_files:int,resolved_files:int,dependency_count?:int,unresolved_references?:int} $counts */
    private function writeCache(string $path, string $signature, array $counts): void
    {
        try {
            $json = json_encode(['signature' => $signature, 'counts' => $counts], JSON_THROW_ON_ERROR);
            $temp = @tempnam(dirname($path), 'gamecounts-');
            if ($temp === false) {
                return;
            }
            try {
                if (@file_put_contents($temp, $json) === false) {
                    return;
                }
                // Windows rename cannot replace an existing destination.
                if (is_file($path)) {
                    @unlink($path);
                }
                @rename($temp, $path);
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        } catch (Throwable) {
            // Cache failure must not alter the freshly computed SQL counts.
        }
    }
}
