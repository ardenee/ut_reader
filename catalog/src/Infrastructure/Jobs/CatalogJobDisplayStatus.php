<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines administrator-visible durable-job status normalization and indexed SQL filtering.
 * Why: The same display semantics are shared by persistence queries and result hydration without reparsing result JSON in hot reads.
 * Role: Infrastructure query helper; normalization semantics remain stable.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogJobDisplayStatus
{
    private const FAILED_OUTCOMES = ['failed', 'rejected', 'unverified', 'invalid_ue_package'];
    private const FILTERS = ['queued', 'running', 'completed', 'failed', 'dead_letter', 'cancelled', 'partial_archive'];

    public static function normalize(string $queueStatus, ?string $resultStatus): string
    {
        $queueStatus = strtolower(trim($queueStatus));
        $resultStatus = strtolower(trim((string)$resultStatus));

        if ($queueStatus !== 'completed') {
            return $queueStatus !== '' ? $queueStatus : 'unknown';
        }
        if ($resultStatus === '' || $resultStatus === 'completed') {
            return 'completed';
        }
        return $resultStatus === 'verified' ? 'imported' : $resultStatus;
    }

    public static function group(string $queueStatus, ?string $resultStatus): string
    {
        $displayStatus = self::normalize($queueStatus, $resultStatus);
        if (in_array($displayStatus, self::FAILED_OUTCOMES, true)) {
            return 'failed';
        }
        if (strtolower(trim($queueStatus)) === 'completed') {
            return 'completed';
        }
        return $displayStatus;
    }

    public static function groupDisplayStatus(string $queueStatus, string $displayStatus): string
    {
        $queueStatus = strtolower(trim($queueStatus));
        $displayStatus = strtolower(trim($displayStatus));
        if (in_array($displayStatus, self::FAILED_OUTCOMES, true)) {
            return 'failed';
        }
        if ($queueStatus === 'completed') {
            return 'completed';
        }
        return $displayStatus !== '' ? $displayStatus : $queueStatus;
    }

    public static function isValidFilter(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::FILTERS, true);
    }

    /**
     * Kept as the stable query API for existing callers. The expression is now a
     * generated, indexed column maintained atomically by MySQL from status/result_json.
     */
    public static function sqlExpression(string $alias = ''): string
    {
        return self::prefix($alias) . 'display_status';
    }

    /** @return array{sql:string,params:list<string>} */
    public static function filterCondition(string $status, string $alias = ''): array
    {
        $status = strtolower(trim($status));
        if (!self::isValidFilter($status)) {
            throw new \InvalidArgumentException('Unsupported job status filter.');
        }

        $prefix = self::prefix($alias);
        if ($status === 'partial_archive') {
            // This is an operator-only synthetic filter over fixed JobType
            // constants. Keeping those constants in the SQL means the retained
            // archive count and page query add no extra parameters beyond the
            // shared queue/search scope, so both paths use the same bind order.
            return [
                'sql' => $prefix . 'status="completed" AND '
                    . $prefix . 'job_type IN ("' . JobType::PROCESS_BUCKET_ARCHIVE . '","'
                    . JobType::IMPORT_STAGED_ARCHIVE . '") AND '
                    . $prefix . 'display_status="partial"',
                'params' => [],
            ];
        }
        if (in_array($status, ['queued', 'running', 'dead_letter', 'cancelled'], true)) {
            return ['sql' => $prefix . 'status=?', 'params' => [$status]];
        }
        if ($status === 'failed') {
            return [
                'sql' => $prefix . 'display_status IN ("failed","rejected","unverified","invalid_ue_package")',
                'params' => [],
            ];
        }
        if ($status === 'completed') {
            return [
                'sql' => $prefix . 'status="completed" AND '
                    . $prefix . 'display_status NOT IN ("failed","rejected","unverified","invalid_ue_package") AND NOT ('
                    . $prefix . 'display_status="partial" AND '
                    . $prefix . 'job_type IN ("' . JobType::PROCESS_BUCKET_ARCHIVE . '","'
                    . JobType::IMPORT_STAGED_ARCHIVE . '"))',
                'params' => [],
            ];
        }

        return ['sql' => $prefix . 'display_status=?', 'params' => [$status]];
    }

    private static function prefix(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL table alias.');
        }
        return $alias . '.';
    }
}
