<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Dispatches source-scan progress states to an optional callback.
 * Why: Progress transport is a tiny reusable boundary and should not require a procedural helper file.
 * Role: Stateless source-scan progress adapter.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

final class CatalogSourceScanProgress
{
    /** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $state */
    public static function report(?callable $progress, array $state): void
    {
        if ($progress !== null) {
            $progress($state);
        }
    }
}
