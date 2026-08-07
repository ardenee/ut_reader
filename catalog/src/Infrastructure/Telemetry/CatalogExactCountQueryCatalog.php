<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the representative exact-count query catalog used by timing and EXPLAIN diagnostics.
 * Why: The catalog discovers database state and assembles persistence-specific SQL, so it belongs with telemetry infrastructure.
 * Role: Infrastructure query catalog for administrator performance diagnostics.
 * Audit: Keep representative SQL definitions centralized here; do not duplicate them in pages or Application services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;
use UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;

/** Builds the representative exact-count query set used by timing and EXPLAIN tools. */
final class CatalogExactCountQueryCatalog
{
    /**
     * @return list<array{metric_key:string,label:string,context:array<string,mixed>,sql:string,args:list<mixed>}>
     */
    public static function definitions(PDO $db): array
    {
        $definitions = [];
        $summaryAvailable = (new PdoDependencyPackageSummary($db))->available();

        foreach (\catalog_all(
            $db,
            'SELECT g.id,g.name,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id ORDER BY g.id'
        ) as $game) {
            $gameId = (int)$game['id'];
            $gameName = (string)$game['name'];
            $engineKey = strtoupper(trim((string)$game['engine_key']));
            $separateUpk = $engineKey === 'UE3';
            $baseWhere = ' WHERE f.game_id=?' . ($separateUpk ? ' AND LOWER(f.extension)<>"upk"' : '');

            $definitions[] = self::definition(
                'game_files.total',
                'Game Files total: ' . $gameName,
                [
                    'game_id' => $gameId,
                    'game' => $gameName,
                    'engine' => $engineKey,
                    'separate_upk' => $separateUpk,
                    'filters' => 'none',
                ],
                'SELECT COUNT(*) c FROM ue_files f' . $baseWhere,
                [$gameId]
            );

            $definitions[] = self::definition(
                'game_files.missing_filter',
                'Game Files missing filter: ' . $gameName,
                ['game_id' => $gameId, 'game' => $gameName, 'summary' => $summaryAvailable],
                $summaryAvailable
                    ? 'SELECT COUNT(*) c FROM ue_files f' . $baseWhere
                        . ' AND EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx '
                        . 'WHERE dx.file_id=f.id AND dx.missing_count>0)'
                    : 'SELECT COUNT(DISTINCT f.id) c FROM ue_files f JOIN ue_dependencies d ON d.file_id=f.id'
                        . $baseWhere . ' AND d.status="missing"',
                [$gameId]
            );
        }

        $summaryQueries = $summaryAvailable ? [
            'missing.files' => ['Files with missing dependencies', 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependency_package_summaries WHERE missing_count>0'],
            'missing.objects' => ['Missing dependency objects', 'SELECT COALESCE(SUM(missing_count),0) c FROM ue_dependency_package_summaries'],
            'missing.packages' => ['Distinct missing packages', 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependency_package_summaries WHERE missing_count>0'],
            'missing.resolved' => ['Resolved dependency objects', 'SELECT COALESCE(SUM(resolved_count),0) c FROM ue_dependency_package_summaries'],
        ] : [
            'missing.files' => ['Files with missing dependencies', 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing"'],
            'missing.objects' => ['Missing dependency objects', 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"'],
            'missing.packages' => ['Distinct missing packages', 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependencies WHERE status="missing" AND required_package<>""'],
            'missing.resolved' => ['Resolved dependency objects', 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="resolved"'],
        ];
        foreach ($summaryQueries as $metric => [$label, $sql]) {
            $definitions[] = self::definition($metric, $label, ['summary' => $summaryAvailable], $sql);
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
            $definitions[] = self::definition(
                'missing.package_objects',
                'Missing objects for ' . $packageName,
                ['package' => $packageName],
                'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND required_package=?',
                [$packageName]
            );
            $definitions[] = self::definition(
                'missing.package_files',
                'Files requiring ' . $packageName,
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
            $definitions[] = self::definition(
                'background_jobs.total',
                'Background Jobs: ' . $queueName . ' / all',
                ['queue' => $queueName, 'status' => 'all', 'search' => false],
                'SELECT COUNT(*) c FROM ue_background_jobs j WHERE j.queue_name=?',
                [$queueName]
            );
            foreach (['running', 'failed', 'completed'] as $status) {
                $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
                $definitions[] = self::definition(
                    'background_jobs.total',
                    'Background Jobs: ' . $queueName . ' / ' . $status,
                    ['queue' => $queueName, 'status' => $status, 'search' => false],
                    'SELECT COUNT(*) c FROM ue_background_jobs j WHERE j.queue_name=? AND ' . $condition['sql'],
                    array_merge([$queueName], $condition['params'])
                );
            }
        }

        $ignoreBaseGame = function_exists('federation_ignore_base_game_files')
            ? \federation_ignore_base_game_files($db)
            : true;
        $conflictQuery = CatalogFederationConflictListService::countQuery(0, $ignoreBaseGame);
        $definitions[] = self::definition(
            'federation.conflicts',
            'Federation identity conflicts: all peers',
            ['peer_id' => 0, 'ignore_base_game' => $ignoreBaseGame],
            $conflictQuery['sql'],
            $conflictQuery['args']
        );
        foreach (array_slice(\catalog_all(
            $db,
            'SELECT id,site_name FROM ue_federation_peers ORDER BY id'
        ), 0, 5) as $peer) {
            $peerId = (int)$peer['id'];
            $peerName = (string)$peer['site_name'];
            $conflictQuery = CatalogFederationConflictListService::countQuery($peerId, $ignoreBaseGame);
            $definitions[] = self::definition(
                'federation.conflicts',
                'Federation identity conflicts: ' . $peerName,
                ['peer_id' => $peerId, 'peer' => $peerName, 'ignore_base_game' => $ignoreBaseGame],
                $conflictQuery['sql'],
                $conflictQuery['args']
            );
        }

        return $definitions;
    }

    /**
     * @param array<string,mixed> $context
     * @param list<mixed> $args
     * @return array{metric_key:string,label:string,context:array<string,mixed>,sql:string,args:list<mixed>}
     */
    private static function definition(
        string $metricKey,
        string $label,
        array $context,
        string $sql,
        array $args = []
    ): array {
        return [
            'metric_key' => $metricKey,
            'label' => $label,
            'context' => $context,
            'sql' => $sql,
            'args' => $args,
        ];
    }
}
