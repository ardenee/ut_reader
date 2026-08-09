<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the stable global system-error capture API used by bootstrap, pages and JSON responses.
 * Why: Handler registration, normalization/fingerprinting and independent persistence now have focused namespaced owners.
 * Role: Thin compatibility facade; do not add system-error implementation logic here.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorHandlerRegistry;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

function catalog_system_error_register(): void
{
    CatalogSystemErrorHandlerRegistry::register();
}

/** @param array<string,mixed> $data */
function catalog_system_error_record(array $data): void
{
    CatalogSystemErrorRecorder::record($data);
}

function catalog_system_error_record_exception(Throwable $error, string $sourceKind = 'php'): void
{
    CatalogSystemErrorRecorder::recordException($error, $sourceKind);
}

/** @param array<string,mixed> $context */
function catalog_system_error_record_http(string $code, string $message, int $status, array $context = []): void
{
    CatalogSystemErrorRecorder::recordHttp($code, $message, $status, $context);
}
