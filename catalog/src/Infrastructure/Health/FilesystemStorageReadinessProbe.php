<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Health;

use UnrealDb\Catalog\Application\System\Contract\ReadinessProbe;

/**
 * Current package-storage readiness adapter.
 *
 * This adapter is intentionally filesystem-specific. A future object-store or
 * network-store implementation can replace it without changing Application or
 * the readiness endpoint.
 */
final class FilesystemStorageReadinessProbe implements ReadinessProbe
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function name(): string
    {
        return 'package_storage';
    }

    public function ready(): bool
    {
        $path = rtrim(trim($this->storagePath), '/\\');
        return $path !== ''
            && is_dir($path)
            && is_readable($path)
            && is_writable($path);
    }
}
