<?php
/**
 * Shared SQL fragments for operator-facing background-job state.
 *
 * Rows, counts and filters must derive "running" from the same rule so the
 * Background Jobs page cannot report different totals and row states.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

final class BackgroundJobDisplaySql
{
    public static function hasWorkflow(string $alias = 'j'): string
    {
        $alias = self::alias($alias);
        return 'EXISTS(SELECT 1 FROM ue_background_jobs job_child '
            . 'WHERE job_child.parent_job_id=' . $alias . '.id LIMIT 1)';
    }

    public static function hasRunningChild(string $alias = 'j'): string
    {
        $alias = self::alias($alias);
        return 'EXISTS(SELECT 1 FROM ue_background_jobs running_child '
            . 'WHERE running_child.parent_job_id=' . $alias . '.id '
            . 'AND running_child.status="running" LIMIT 1)';
    }

    public static function operatorStatus(string $alias = 'j'): string
    {
        $alias = self::alias($alias);
        return 'CASE '
            . 'WHEN ' . $alias . '.status="running" THEN "running" '
            . 'WHEN ' . $alias . '.parent_job_id IS NULL '
            . 'AND ' . $alias . '.status="queued" '
            . 'AND ' . self::hasRunningChild($alias) . ' THEN "running" '
            . 'ELSE ' . $alias . '.status END';
    }

    public static function operatorStartedAt(string $alias = 'j'): string
    {
        $alias = self::alias($alias);
        return 'CASE WHEN ' . self::hasWorkflow($alias)
            . ' THEN ' . $alias . '.created_at ELSE ' . $alias . '.leased_at END';
    }

    private static function alias(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL alias.');
        }
        return $alias;
    }
}
