<?php
/**
 * Plans the resolved transitive dependency closure for a cross-game package copy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class CatalogCrossGameDependencyClosurePlanner
{
    private const BATCH_SIZE = 250;
    private const MAX_DEPENDENCY_FILES = 10000;

    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /**
     * Return dependency files in dependency-first queue order. The selected root
     * is deliberately excluded and must be queued after these files.
     *
     * @return array{file_ids:list<int>,missing_count:int,common_count:int,package_only_count:int}
     */
    public function plan(int $rootFileId, int $targetGameId): array
    {
        if ($rootFileId < 1 || $targetGameId < 1) {
            throw new \RuntimeException('Cross-game dependency planning requires source and target IDs.');
        }

        $root = \catalog_one(
            $this->db,
            'SELECT f.id,f.game_id,f.scan_status,m.format_version,g.profile_id,'
            . 'COALESCE(p.engine_key,"") source_engine '
            . 'FROM ue_files f '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1',
            [$rootFileId]
        );
        if (!$root) {
            throw new \RuntimeException('The selected cross-game source package is no longer verified.');
        }

        $target = \catalog_one(
            $this->db,
            'SELECT g.id,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=? LIMIT 1',
            [$targetGameId]
        );
        if (!$target) {
            throw new \RuntimeException('Cross-game copy destination no longer exists.');
        }
        if ((int)$root['game_id'] === $targetGameId) {
            throw new \RuntimeException('Cross-game dependency planning requires a sibling source game.');
        }
        if (strcasecmp(trim((string)$root['source_engine']), trim((string)$target['engine_key'])) !== 0) {
            throw new \RuntimeException('Cross-game dependency closure is limited to the same engine profile family.');
        }
        if ((int)($root['format_version'] ?? 0) !== 2) {
            throw new \RuntimeException('The selected source package has no current format-2 dependency metadata.');
        }

        $sourceGameId = (int)$root['game_id'];
        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $queue = [$rootFileId];
        $scheduled = [$rootFileId => true];
        $visited = [];
        $dependencies = [];
        $missingKeys = [];
        $commonKeys = [];
        $packageOnlyKeys = [];

        while ($queue !== []) {
            $chunk = array_splice($queue, 0, self::BATCH_SIZE);
            foreach ($chunk as $fileId) {
                $visited[(int)$fileId] = true;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = \catalog_all(
                $this->db,
                'SELECT d.id,d.file_id,d.required_package,d.required_object_path,d.resolved_file_id,d.status,'
                . 'provider.game_id provider_game_id,provider_meta.file_id provider_metadata_file_id '
                . 'FROM ' . $dependencySource . ' d '
                . 'LEFT JOIN ue_files provider ON provider.id=d.resolved_file_id AND provider.scan_status="verified" '
                . 'LEFT JOIN ue_file_metadata provider_meta ON provider_meta.file_id=provider.id AND provider_meta.format_version=2 '
                . 'WHERE d.file_id IN (' . $placeholders . ') '
                . 'ORDER BY d.file_id,d.required_package,d.required_object_path,d.id',
                $chunk
            );

            foreach ($rows as $row) {
                $status = strtolower(trim((string)($row['status'] ?? 'missing')));
                $resolvedFileId = $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : 0;
                $key = strtolower(
                    trim((string)($row['required_package'] ?? '')) . '|'
                    . trim((string)($row['required_object_path'] ?? ''))
                );

                if ($status === 'common') {
                    $commonKeys[$key] = true;
                    continue;
                }
                if ($status === 'missing' || $resolvedFileId < 1) {
                    $missingKeys[$key] = true;
                    continue;
                }
                if ($status === 'package_only') {
                    $packageOnlyKeys[$key] = true;
                }

                // Normal dependency resolution is game-scoped. Never let a stale
                // resolved_file_id jump the closure into a different source game.
                if ((int)($row['provider_game_id'] ?? 0) !== $sourceGameId
                    || (int)($row['provider_metadata_file_id'] ?? 0) !== $resolvedFileId) {
                    $missingKeys[$key] = true;
                    continue;
                }
                if ($resolvedFileId === $rootFileId) {
                    continue;
                }

                if (!isset($dependencies[$resolvedFileId])) {
                    $dependencies[$resolvedFileId] = $resolvedFileId;
                    if (count($dependencies) > self::MAX_DEPENDENCY_FILES) {
                        throw new \RuntimeException(
                            'Cross-game dependency closure exceeds the safety limit of '
                            . number_format(self::MAX_DEPENDENCY_FILES) . ' files.'
                        );
                    }
                }
                if (!isset($scheduled[$resolvedFileId]) && !isset($visited[$resolvedFileId])) {
                    $scheduled[$resolvedFileId] = true;
                    $queue[] = $resolvedFileId;
                }
            }
        }

        // Traversal is breadth-first. Reversing it queues deeper dependencies
        // before the packages that directly depend on them.
        $fileIds = array_reverse(array_values($dependencies));

        return [
            'file_ids' => $fileIds,
            'missing_count' => count($missingKeys),
            'common_count' => count($commonKeys),
            'package_only_count' => count($packageOnlyKeys),
        ];
    }
}
