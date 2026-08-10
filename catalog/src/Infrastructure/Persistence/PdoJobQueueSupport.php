<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Centralizes value validation, JSON encoding, retryable database-contention detection and UTC helpers shared by PDO job queue adapters.
 * Why: Queue persistence previously duplicated these low-level rules across lifecycle implementations.
 * Role: Infrastructure-only helper; contains no queue policy or HTTP/process concerns.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class PdoJobQueueSupport
{
    private const UTC = 'UTC';

    public static function requiredIdentifier(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 120) {
            throw new \InvalidArgumentException('Invalid job ' . $label . '.');
        }
        return $value;
    }

    public static function optionalIdentifier(string $value, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 191) {
            throw new \InvalidArgumentException('Invalid job ' . $label . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $value */
    public static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    public static function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Job payload must decode to an object.');
        }
        return $decoded;
    }

    public static function trimError(Throwable $exception): string
    {
        return substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 60000);
    }

    public static function trimReason(string $reason): string
    {
        $reason = trim($reason);
        return substr($reason !== '' ? $reason : 'Cancellation requested.', 0, 1000);
    }

    /**
     * MySQL/MariaDB contention failures for which the complete transaction was
     * rolled back and may safely be retried against the same lease token.
     */
    public static function retryableContention(Throwable $exception): bool
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

    /** Small bounded jitter for retrying short queue-state transactions. */
    public static function contentionBackoffMicros(int $attempt): int
    {
        $attempt = max(1, min(8, $attempt));
        $base = 5000 * $attempt;
        try {
            return $base + random_int(0, 10000 * $attempt);
        } catch (Throwable) {
            return $base;
        }
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::UTC));
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone(self::UTC);
    }
}
