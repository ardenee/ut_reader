<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Plans one generated mod/dependency package and its dependency closure.
 * Why: Dependency traversal, base-game exclusion, install placement, storage resolution and export limits are one read/planning use case.
 * Role: Downloads infrastructure planner used by preview and background package generation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class PdoCatalogPackageExportPlanner
{
    private readonly CatalogPackageInstallPathResolver $paths;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/BaseGameProtection.php';
        $this->paths = new CatalogPackageInstallPathResolver($db, $config);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function plan(
        int $rootFileId,
        string $format,
        bool $includeDependencies,
        array $settings
    ): array {
        if (empty($settings['enabled'])) {
            throw new RuntimeException('Package exports are disabled by the administrator.');
        }
        if (!CatalogPackageExportFormatPolicy::enabled($format, $settings)) {
            throw new RuntimeException('The selected package export format is disabled.');
        }

        \base_game_ensure($this->db);
        $root = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status<>"failed"',
            [$rootFileId]
        );
        if (!$root) {
            throw new RuntimeException('File not found.');
        }
        if (\base_game_file_is_protected($this->db, $root)) {
            throw new RuntimeException(\base_game_block_message($root));
        }

        $game = $this->gameRow((int)$root['game_id']);
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
        $transitive = !empty($settings['include_transitive']);
        $dependencySource = PdoDependencyReadSource::sql($this->db);

        while ($queue) {
            $fileId = (int)array_shift($queue);
            if (isset($visited[$fileId])) {
                continue;
            }
            $visited[$fileId] = true;

            $file = $fileId === $rootFileId
                ? $root
                : \catalog_one(
                    $this->db,
                    'SELECT * FROM ue_files WHERE id=? AND game_id=? AND scan_status<>"failed"',
                    [$fileId, (int)$root['game_id']]
                );
            if (!$file) {
                continue;
            }

            if ($fileId !== $rootFileId && \base_game_file_is_protected($this->db, $file)) {
                $blocked[$fileId] = [
                    'file_id' => $fileId,
                    'package_name' => (string)$file['package_name'],
                    'original_name' => \catalog_clean_unreal_filename((string)$file['original_name']),
                    'package_guid' => (string)$file['package_guid'],
                    'reason' => 'Official/base game package: dependency-index-only; not redistributed.',
                ];
                continue;
            }

            $placement = $this->paths->installPath($file, $game, $format);
            $file['install_path'] = $placement['path'];
            $file['source_relative_path'] = $placement['source_relative_path'];
            $file['install_path_inferred'] = $placement['path_inferred'];
            $file['storage_path'] = $this->paths->storagePath($file);

            $files[$fileId] = $file;
            $totalBytes += (int)$file['file_size'];
            if (count($files) > (int)$settings['max_files']) {
                throw new RuntimeException(
                    'Package exceeds the configured file limit of ' . (int)$settings['max_files'] . '.'
                );
            }
            if ($totalBytes > (int)$settings['max_bytes']) {
                throw new RuntimeException(
                    'Package exceeds the configured size limit of '
                    . \catalog_bytes((int)$settings['max_bytes']) . '.'
                );
            }

            if (!$includeDependencies) {
                continue;
            }

            $dependencies = \catalog_all(
                $this->db,
                'SELECT d.id,d.required_package,d.required_object_path,d.resolved_file_id,d.status '
                . 'FROM ' . $dependencySource . ' d '
                . 'WHERE d.file_id=? '
                . 'ORDER BY d.required_package,d.required_object_path,d.id',
                [$fileId]
            );
            foreach ($dependencies as $dependency) {
                $status = (string)$dependency['status'];
                $resolvedId = $dependency['resolved_file_id'] !== null
                    ? (int)$dependency['resolved_file_id']
                    : 0;
                $key = strtolower(
                    (string)$dependency['required_package'] . '|'
                    . (string)$dependency['required_object_path']
                );
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

        $installPaths = [];
        foreach ($files as $fileId => $file) {
            $pathKey = strtolower((string)$file['install_path']);
            if (isset($installPaths[$pathKey]) && $installPaths[$pathKey] !== $fileId) {
                throw new RuntimeException(
                    'Two catalog files map to the same package path: ' . $file['install_path']
                    . '. Correct their source locations before exporting.'
                );
            }
            $installPaths[$pathKey] = $fileId;
        }

        uasort($files, static function (array $left, array $right) use ($rootFileId): int {
            $leftRoot = (int)$left['id'] === $rootFileId;
            $rightRoot = (int)$right['id'] === $rootFileId;
            if ($leftRoot !== $rightRoot) {
                return $leftRoot ? -1 : 1;
            }
            return strcasecmp((string)$left['install_path'], (string)$right['install_path']);
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

    /** @return array<string,mixed>|null */
    private function gameRow(int $gameId): ?array
    {
        return \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.description,g.profile_id,p.profile_name,p.engine_key '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=?',
            [$gameId]
        );
    }
}
