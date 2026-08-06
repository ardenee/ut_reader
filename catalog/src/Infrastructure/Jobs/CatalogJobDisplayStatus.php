<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogJobDisplayStatus
{
    private const FAILED_OUTCOMES = ['failed', 'rejected', 'unverified'];
    private const FILTERS = ['queued', 'running', 'completed', 'failed', 'dead_letter', 'cancelled'];

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

    public static function isValidFilter(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::FILTERS, true);
    }

    public static function sqlExpression(string $alias = ''): string
    {
        $prefix = self::prefix($alias);
        $resultStatus = 'LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT('
            . $prefix . 'result_json,"$.status")),"")))';

        return '(CASE '
            . 'WHEN LOWER(' . $prefix . 'status)<>"completed" THEN LOWER(' . $prefix . 'status) '
            . 'WHEN ' . $resultStatus . ' IN ("","completed") THEN "completed" '
            . 'WHEN ' . $resultStatus . '="verified" THEN "imported" '
            . 'ELSE ' . $resultStatus . ' END)';
    }

    /** @return array{sql:string,params:list<string>} */
    public static function filterCondition(string $status, string $alias = ''): array
    {
        $status = strtolower(trim($status));
        if (!self::isValidFilter($status)) {
            throw new \InvalidArgumentException('Unsupported job status filter.');
        }

        $prefix = self::prefix($alias);

        // These visible statuses are identical to the persisted queue status.
        // Avoid JSON extraction and a full CASE expression so MySQL can use the
        // queue/status indexes during large bulk actions and status counts.
        if (in_array($status, ['queued', 'running', 'dead_letter', 'cancelled'], true)) {
            return ['sql' => $prefix . 'status=?', 'params' => [$status]];
        }

        $displayStatus = self::sqlExpression($alias);
        if ($status === 'failed') {
            return [
                'sql' => $displayStatus . ' IN ("failed","rejected","unverified")',
                'params' => [],
            ];
        }
        if ($status === 'completed') {
            return [
                'sql' => $prefix . 'status="completed" AND '
                    . $displayStatus . ' NOT IN ("failed","rejected","unverified")',
                'params' => [],
            ];
        }

        return ['sql' => $displayStatus . '=?', 'params' => [$status]];
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
