<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Centralizes value validation, JSON encoding and UTC time helpers shared by the PDO job queue adapters.
 * Why: Queue persistence previously duplicated these low-level rules across lifecycle implementations.
 * Role: Infrastructure-only helper; contains no queue policy or HTTP/process concerns.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

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

    public static function trimError(\Throwable $exception): string
    {
        return substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 60000);
    }

    public static function trimReason(string $reason): string
    {
        $reason = trim($reason);
        return substr($reason !== '' ? $reason : 'Cancellation requested.', 0, 1000);
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
