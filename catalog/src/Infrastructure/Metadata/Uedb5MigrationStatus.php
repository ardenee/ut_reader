<?php
/** Durable Step 8 per-file UEDB5 migration states. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5MigrationStatus
{
    public const PENDING = 'pending';
    public const STAGED = 'staged';
    public const VALIDATED = 'validated';
    public const FAILED = 'failed';
    public const VALIDATOR_POLICY = 'uedb5-validation-v1';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::PENDING, self::STAGED, self::VALIDATED, self::FAILED];
    }

    public static function assert(string $status): void
    {
        if (!in_array($status, self::values(), true)) {
            throw new RuntimeException('Invalid UEDB5 migration status: ' . $status);
        }
    }
}
