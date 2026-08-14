<?php
/**
 * Upload failure logging adapter.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Logging;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Upload\Contract\UploadFailureLogger;

/** Bridges upload diagnostics to PHP error logging and the optional application log. */
final class CatalogUploadFailureLogger implements UploadFailureLogger
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(string $filename, Throwable $exception): void
    {
        $details = $filename
            . ': '
            . get_class($exception)
            . ': '
            . $exception->getMessage()
            . "\n"
            . $exception->getTraceAsString();

        error_log('[UnrealDB upload] ' . $details);

        if (!function_exists('fed_log')) {
            return;
        }

        try {
            \fed_log($this->db, null, null, 'ERROR', 'UPLOAD_SCAN_FAIL', $details);
        } catch (Throwable) {
            // Optional audit logging must never replace the original upload result.
        }
    }
}
