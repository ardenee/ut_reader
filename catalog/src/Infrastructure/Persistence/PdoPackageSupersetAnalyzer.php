<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

/**
 * Builds the catalog-wide union of object paths required from one logical
 * package and evaluates every verified UEDB5 provider against that union.
 */
final class PdoPackageSupersetAnalyzer
{
    /**
     * @return array{
     *   game_id:int,
     *   package_name:string,
     *   consumer_count:int,
     *   required_object_count:int,
     *   required_object_paths:list<string>,
     *   providers:list<array<string,mixed>>
     * }
     */
    public static function analyze(PDO $db, int $gameId, string $packageName, ?array $config = null): array
    {
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '') {
            return [
                'game_id' => $gameId,
                'package_name' => $packageName,
                'consumer_count' => 0,
                'required_object_count' => 0,
                'required_object_paths' => [],
                'providers' => [],
            ];
        }

        // The UE2 classic dependency row stores the original import identity,
        // not a preassembled object_path. Reconstruct that path by following the
        // source import outer chain rather than dropping all UE2 requirements.
        // Other engines retain their existing coverage behaviour unchanged.
        $engineStatement = $db->prepare(
            'SELECT gp.engine_key FROM ue_games g '
            . 'JOIN ue_game_profiles gp ON gp.id=g.profile_id AND gp.is_active=1 '
            . 'WHERE g.id=? LIMIT 1'
        );
        $engineStatement->execute([$gameId]);
        $isUe2 = strtoupper(trim((string)($engineStatement->fetchColumn() ?: ''))) === 'UE2';

        $statement = $db->prepare(
            'SELECT DISTINCT p.file_id FROM ue_uedb5_dependency_packages p '
            . \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::joinCurrentPackages('p')
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'WHERE p.game_id=? AND p.required_package_name=? ORDER BY p.file_id'
        );
        $statement->execute([$gameId, $packageName]);
        $consumerIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $requirements = [];
        $requiredClasses = [];
        $consumers = [];
        if ($consumerIds !== []) {
            $reader = new Uedb5ParityV5ReadService($db, $config ?? self::catalogConfig());
            foreach ($consumerIds as $consumerFileId) {
                $ue2SourceImports = [];
                if ($isUe2) {
                    foreach (Uedb5ClassicDependencyResolver::importCoverageRows(
                        $reader->snapshot($gameId, $consumerFileId)
                    ) as $sourceImport) {
                        $ue2SourceImports[(int)$sourceImport['import_index']] = $sourceImport;
                    }
                }
                foreach ($reader->dependencies($gameId, $consumerFileId) as $dependency) {
                    if (strcasecmp((string)($dependency['required_package'] ?? ''), $packageName) !== 0) {
                        continue;
                    }
                    $fullPath = trim((string)($dependency['required_object_path'] ?? ''));
                    $sourceImport = null;
                    if ($fullPath === '' && $isUe2
                        && (string)($dependency['source_section'] ?? '') === 'imports') {
                        $sourceImport = $ue2SourceImports[(int)($dependency['source_index'] ?? -1)] ?? null;
                        if (is_array($sourceImport)
                            && strcasecmp((string)($sourceImport['root_package'] ?? ''), $packageName) === 0) {
                            // Core.Package is a linker root, not a provider export.
                            // Only nested import objects contribute required paths.
                            if ((string)($sourceImport['relative_object_path'] ?? '') === '') {
                                continue;
                            }
                            $fullPath = (string)($sourceImport['full_path'] ?? '');
                        }
                    }
                    $relativePath = self::relativePath($packageName, $fullPath);
                    if ($relativePath === '') {
                        continue;
                    }
                    $key = self::key($relativePath);
                    $requirements[$key] ??= $relativePath;
                    $className = trim((string)($dependency['class_name'] ?? ''));
                    if ($className !== '') {
                        $requiredClasses[$relativePath] ??= [
                            'class_package' => trim((string)($dependency['class_package'] ?? '')),
                            'class_name' => $className,
                        ];
                    }
                    $consumers[$consumerFileId] = true;
                }
            }
        }

        $paths = array_values($requirements);
        $providers = $paths === []
            ? []
            : PdoPackageObjectCoverageResolver::evaluate(
                $db,
                $gameId,
                $packageName,
                $paths,
                0,
                $requiredClasses,
                $isUe2 ? 'UE2' : '',
                (string)(($config ?? self::catalogConfig())['storage_path'] ?? '')
            );

        return [
            'game_id' => $gameId,
            'package_name' => $packageName,
            'consumer_count' => count($consumers),
            'required_object_count' => count($paths),
            'required_object_paths' => $paths,
            'providers' => $providers,
        ];
    }

    /** @return array<string,mixed> */
    private static function catalogConfig(): array
    {
        $root = dirname(__DIR__, 3);
        return require $root . '/config.php';
    }

    private static function relativePath(string $packageName, string $fullPath): string
    {
        $fullPath = trim($fullPath, '. ');
        if ($fullPath === '') {
            return '';
        }
        $prefix = $packageName . '.';
        if (str_starts_with(self::key($fullPath), self::key($prefix))) {
            return trim(substr($fullPath, strlen($prefix)), '.');
        }
        return $fullPath;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
    }
}
