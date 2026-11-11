<?php
/**
 * Minimal first-party operational access logging for successful catalogue
 * page renders and response-cache hits. No browser tracking, beacon, cookies
 * or interaction/section measurements are enabled by this service.
 *
 * Failure never prevents a catalogue response: the underlying recorder writes
 * its own diagnostics and existing administrator exclusions remain enforced.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use Throwable;

final class CatalogOperationalAccessLogger
{
    private static bool $recorded = false;

    public static function record(): void
    {
        if (self::$recorded || PHP_SAPI === 'cli'
            || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!str_contains($script, '/catalog/')) {
            return;
        }
        // Every completed response is recorded at most once. A cached page
        // bypasses catalog_head(), so the cache-serve boundary calls us too.
        self::$recorded = true;
        try {
            (new CatalogAccessEventRecorder(\catalog_db(\catalog_config())))->recordPageView();
        } catch (Throwable $error) {
            error_log('[UnrealDB operational access logging] ' . $error->getMessage());
        }
    }
}
