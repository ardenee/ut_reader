<?php
/**
 * Dedicated durable queue identity for generated download packages.
 *
 * Generated archives are interactive user work and must never sit behind the
 * catalogue import/dependency worker pool. A separate queue gives them their own
 * detached worker while retaining the same durable-job infrastructure.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogGeneratedPackageQueue
{
    /** @param array<string,mixed> $config */
    public static function name(array $config): string
    {
        $configured = trim((string)($config['queue']['package_name'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $main = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        return substr($main . '-packages', 0, 80);
    }

    /** @param array<string,mixed> $config */
    public static function workerCount(array $config): int
    {
        return max(1, min(
            2,
            (int)($config['queue']['package_worker_processes'] ?? 1)
        ));
    }
}
