<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams public download files with the configured byte-rate ceiling.
 * Why: File transfer I/O should not be mixed with public settings persistence or request-abuse policy.
 * Role: Infrastructure security/download collaborator preserving the existing throttled streaming behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use RuntimeException;

final class CatalogPublicFileStreamer
{
    public function stream(string $path, int $bytesPerSecond = 0): never
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The download file could not be opened.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);
        $chunkSize = $bytesPerSecond > 0
            ? max(1024, min(64 * 1024, intdiv($bytesPerSecond, 4) ?: 1024))
            : 64 * 1024;
        $startedAt = microtime(true);
        $sent = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException('The download file could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                $sent += strlen($chunk);
                if (function_exists('fastcgi_finish_request')) {
                    // Do not call it here: the stream must remain open until complete.
                }
                @ob_flush();
                flush();
                if (connection_aborted()) {
                    break;
                }
                if ($bytesPerSecond > 0) {
                    $expectedElapsed = $sent / $bytesPerSecond;
                    while (!connection_aborted()) {
                        $delay = $expectedElapsed - (microtime(true) - $startedAt);
                        if ($delay <= 0) {
                            break;
                        }
                        usleep((int)min($delay * 1000000, 250000));
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        exit;
    }
}
