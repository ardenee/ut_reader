<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
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
    public static function analyze(PDO $db, int $gameId, string $packageName): array
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

        $statement = $db->prepare(
            'SELECT DISTINCT p.file_id FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'WHERE p.game_id=? AND p.required_package_name=? ORDER BY p.file_id'
        );
        $statement->execute([$gameId, $packageName]);
        $consumerIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $requirements = [];
        $requiredClasses = [];
        $consumers = [];
        if ($consumerIds !== []) {
            $reader = new Uedb5ParityV5ReadService($db, self::catalogConfig());
            foreach ($consumerIds as $consumerFileId) {
                foreach ($reader->dependencies($gameId, $consumerFileId) as $dependency) {
                    if (strcasecmp((string)($dependency['required_package'] ?? ''), $packageName) !== 0) {
                        continue;
                    }
                    $fullPath = trim((string)($dependency['required_object_path'] ?? ''));
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
                $requiredClasses
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
