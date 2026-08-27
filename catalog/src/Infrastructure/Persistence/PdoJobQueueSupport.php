<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Centralizes value validation, JSON encoding and UTC helpers shared by PDO job queue adapters.
 * Why: Queue persistence previously duplicated these low-level rules across lifecycle implementations.
 * Role: Infrastructure-only helper; queue callers delegate database contention policy to PdoContention.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
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
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        try {
            return json_encode($value, $flags);
        } catch (\JsonException $error) {
            if ($error->getCode() !== JSON_ERROR_UTF8) {
                throw $error;
            }

            // Queue state must never be lost solely because a decoder surfaced a
            // legacy byte string in diagnostic/progress data. Domain decoders are
            // still responsible for normalizing identities at source; this is the
            // persistence boundary safety net for any remaining invalid bytes.
            return json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
        }
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

    /** Compatibility façade for existing queue callers. */
    public static function retryableContention(Throwable $exception): bool
    {
        return PdoContention::retryable($exception);
    }

    /** Compatibility façade for existing queue callers. */
    public static function contentionBackoffMicros(int $attempt): int
    {
        return PdoContention::backoffMicros($attempt);
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
