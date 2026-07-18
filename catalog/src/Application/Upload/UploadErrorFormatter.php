<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload;

use Throwable;

/** Converts internal exceptions into the existing concise upload error text. */
final class UploadErrorFormatter
{
    public static function concise(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if (preg_match('/Bad package tag 0x[0-9A-Fa-f]+/', $message, $matches) === 1) {
            return $matches[0];
        }

        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $parts = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message);
        $message = trim((string)($parts[0] ?? $message));

        return $message !== '' ? $message : 'Unknown error';
    }
}
