<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Telemetry;

use PDO;
use UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountTelemetry;

/** Runs the exact count SQL used by the largest paginated administrator views. */
final class CatalogExactCountBenchmark
{
    /** @return list<array<string,mixed>> */
    public static function run(PDO $db): array
    {
        $samples = [];
        $summaryAvailable = (new PdoDependencyPackageSummary($db))->available();

        foreach (\catalog_all(
            $db,
            'SELECT g.id,g.name,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id ORDER BY g.id'
        ) as $game) {
            $gameId = (int)$game['id'];
            $engineKey = strtoupper(trim((string)$game['engine_key']));
            $separateUpk = $engineKey === 'UE3';
            $sql = 'SELECT COUNT(*) c FROM ue_files f WHERE f.game_id=?'
                . ($separateUpk ? ' AND LOWER(f.extension)<>"upk"' : '');
            $samples[] = self::count(
                $db,
                'game_files.total',
                [
                    'game_id' => $gameId,
                    'game' => (string)$game['name'],
                    'engine' => $engineKey,
                    'separate_upk' => $separateUpk,
                    'filters' => 'none',
                ],
                $sql,
                [$gameId]
            );

            $samples[] = self::count(
                $db,
                'game_files.missing_filter',
                [
                    'game_id' => $gameId,
                    'game' => (string)$game['name'],
                    'summary' => $summaryAvailable,
                ],
                $summaryAvailable
                    ? 'SELECT COUNT(*) c FROM ue_files f WHERE f.game_id=? '
                        . ($separateUpk ? 'AND LOWER(f.extension)<>"upk" ' : '')
                        . 'AND EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx '
                        . 'WHERE dx.file_id=f.id AND dx.missing_count>0)'
                    : 'SELECT COUNT(DISTINCT f.id) c FROM ue_files f JOIN ue_dependencies d ON d.file_id=f.id '
                        . 'WHERE f.game_id=? ' . ($separateUpk ? 'AND LOWER(f.extension)<>"upk" ' : '')
                        . 'AND d.status="missing"',
                [$gameId]
            );
        }

        $summaryQueries = $summaryAvailable ? [
            'missing.files' => 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependency_package_summaries WHERE missing_count>0',
            'missing.objects' => 'SELECT COALESCE(SUM(missing_count),0) c FROM ue_dependency_package_summaries',
            'missing.packages' => 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependency_package_summaries WHERE missing_count>0',
            'missing.resolved' => 'SELECT COALESCE(SUM(resolved_count),0) c FROM ue_dependency_package_summaries',
        ] : [
            'missing.files' => 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing"',
            'missing.objects' => 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"',
            'missing.packages' => 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependencies WHERE status="missing" AND required_package<>""',
            'missing.resolved' => 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="resolved"',
        ];
        foreach ($summaryQueries as $metric => $sql) {
            $samples[] = self::count($db, $metric, ['summary' => $summaryAvailable], $sql);
        }

        $topPackages = $summaryAvailable
            ? \catalog_all(
                $db,
                'SELECT required_package,SUM(missing_count) missing_total '
                . 'FROM ue_dependency_package_summaries WHERE missing_count>0 AND required_package<>"" '
                . 'GROUP BY required_package ORDER BY missing_total DESC,required_package LIMIT 5'
            )
            : \catalog_all(
                $db,
                'SELECT required_package,COUNT(*) missing_total FROM ue_dependencies '
                . 'WHERE status="missing" AND required_package<>"" '
                . 'GROUP BY required_package ORDER BY missing_total DESC,required_package LIMIT 5'
            );
        foreach ($topPackages as $package) {
            $packageName = (string)$package['required_package'];
            $samples[] = self::count(
                $db,
                'missing.package_objects',
                ['package' => $packageName],
                'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND required_package=?',
                [$packageName]
            );
            $samples[] = self::count(
                $db,
                'missing.package_files',
                ['package' => $packageName, 'summary' => $summaryAvailable],
                $summaryAvailable
                    ? 'SELECT COUNT(*) c FROM ue_dependency_package_summaries WHERE required_package=? AND missing_count>0'
                    : 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing" AND required_package=?',
                [$packageName]
            );
        }

        foreach (array_slice(\catalog_all(
            $db,
            'SELECT queue_name,COUNT(*) total FROM ue_background_jobs GROUP BY queue_name ORDER BY total DESC,queue_name'
        ), 0, 10) as $queue) {
            $queueName = (string)$queue['queue_name'];
            $samples[] = self::count(
                $db,
                'background_jobs.total',
                ['queue' => $queueName, 'status' => 'all', 'search' => false],
                'SELECT COUNT(*) c FROM ue_background_jobs j WHERE j.queue_name=?',
                [$queueName]
            );
            foreach (['running', 'failed', 'completed'] as $status) {
                $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
                $samples[] = self::count(
                    $db,
                    'background_jobs.total',
                    ['queue' => $queueName, 'status' => $status, 'search' => false],
                    'SELECT COUNT(*) c FROM ue_background_jobs j WHERE j.queue_name=? AND ' . $condition['sql'],
                    array_merge([$queueName], $condition['params'])
                );
            }
        }

        $ignoreBaseGame = function_exists('federation_ignore_base_game_files')
            ? \federation_ignore_base_game_files($db)
            : true;
        $samples[] = self::sample(
            $db,
            'federation.conflicts',
            ['peer_id' => 0, 'ignore_base_game' => $ignoreBaseGame],
            static fn(): int => CatalogFederationConflictListService::count($db, 0, $ignoreBaseGame)
        );
        foreach (array_slice(\catalog_all(
            $db,
            'SELECT id,site_name FROM ue_federation_peers ORDER BY id'
        ), 0, 5) as $peer) {
            $peerId = (int)$peer['id'];
            $samples[] = self::sample(
                $db,
                'federation.conflicts',
                [
                    'peer_id' => $peerId,
                    'peer' => (string)$peer['site_name'],
                    'ignore_base_game' => $ignoreBaseGame,
                ],
                static fn(): int => CatalogFederationConflictListService::count($db, $peerId, $ignoreBaseGame)
            );
        }

        return $samples;
    }

    /** @param array<string,mixed> $context @param list<mixed> $args @return array<string,mixed> */
    private static function count(PDO $db, string $metric, array $context, string $sql, array $args = []): array
    {
        return self::sample(
            $db,
            $metric,
            $context,
            static fn(): int => \catalog_count($db, $sql, $args)
        );
    }

    /** @param array<string,mixed> $context @param callable():int $query @return array<string,mixed> */
    private static function sample(PDO $db, string $metric, array $context, callable $query): array
    {
        return CatalogExactCountTelemetry::sample($db, $metric, $context, $query);
    }
}
