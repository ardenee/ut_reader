<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

final class JobResourcePolicy
{
    public const DEPENDENCY_HEAVY = 'dependency-heavy';
    public const HOUSEKEEPING = 'housekeeping';
    public const DEFAULT = 'default';

    /** @param array<string,mixed> $payload */
    public static function for(string $jobType, array $payload): JobResourceProfile
    {
        return match ($jobType) {
            JobType::REBUILD_GAME_DEPENDENCIES => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('dependency:game:', $payload['game_id'] ?? null)
            ),
            JobType::REBUILD_FILE_DEPENDENCIES,
            JobType::REBUILD_AFFECTED_DEPENDENCIES => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('dependency:file:', $payload['file_id'] ?? null)
            ),
            JobType::REPAIR_SOURCE_IDENTITY_FILE => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('source-identity:file:', $payload['file_id'] ?? null)
            ),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('source-identity:game:', $payload['game_id'] ?? null)
            ),
            JobType::PRUNE_UPLOAD_PROGRESS => new JobResourceProfile(
                self::HOUSEKEEPING,
                self::configuredLimit(self::HOUSEKEEPING, 2)
            ),
            default => new JobResourceProfile(
                self::DEFAULT,
                self::configuredLimit(self::DEFAULT, 4)
            ),
        };
    }

    private static function configuredLimit(string $resourceClass, int $default): int
    {
        $name = 'UNREALDB_JOB_RESOURCE_LIMIT_' . strtoupper(str_replace('-', '_', $resourceClass));
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        return $value === false ? $default : max(1, min((int)$value, 100));
    }

    private static function positiveKey(string $prefix, mixed $value): ?string
    {
        $id = (int)$value;
        return $id > 0 ? $prefix . $id : null;
    }
}
