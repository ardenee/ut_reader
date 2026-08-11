<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Detects retryable MySQL/MariaDB transaction contention and provides bounded jittered backoff.
 * Why: Queue lifecycle and compact metadata publication both need the same deadlock/lock-wait retry policy.
 * Role: Infrastructure PDO contention utility with no queue or feature-specific policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDOException;
use Throwable;

final class PdoContention
{
    public static function retryable(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                $sqlState = strtoupper(trim((string)($current->errorInfo[0] ?? $current->getCode())));
                $driverCode = (int)($current->errorInfo[1] ?? 0);
                if ($sqlState === '40001' || in_array($driverCode, [1205, 1213], true)) {
                    return true;
                }
            }

            $message = strtolower($current->getMessage());
            if (str_contains($message, 'deadlock found when trying to get lock')
                || str_contains($message, 'lock wait timeout exceeded')) {
                return true;
            }
        }
        return false;
    }

    public static function backoffMicros(int $attempt, int $baseMicros = 5000): int
    {
        $attempt = max(1, min(8, $attempt));
        $baseMicros = max(1000, min(250000, $baseMicros));
        $base = $baseMicros * $attempt;
        try {
            return $base + random_int(0, $baseMicros * 2 * $attempt);
        } catch (Throwable) {
            return $base;
        }
    }
}
