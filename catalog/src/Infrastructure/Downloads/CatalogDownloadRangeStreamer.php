<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams an exact file range while completing its download-audit lifecycle row.
 * Why: Binary HTTP transfer mechanics should be separate from audit-row persistence.
 * Role: Infrastructure downloads streaming service preserving existing range/throttle/interruption behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogDownloadRangeStreamer
{
    private readonly CatalogDownloadAuditService $audit;

    public function __construct(PDO $db)
    {
        $this->audit = new CatalogDownloadAuditService($db);
    }

    /** @return never */
    public function stream(
        ?int $auditId,
        string $path,
        int $start,
        int $length,
        int $bytesPerSecond = 0
    ): never {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            $this->audit->finish(
                $auditId,
                'failed',
                0,
                'The download file could not be opened.',
                500
            );
            throw new RuntimeException('The download file could not be opened.');
        }
        if ($start > 0 && fseek($handle, $start, SEEK_SET) !== 0) {
            fclose($handle);
            $this->audit->finish(
                $auditId,
                'failed',
                0,
                'The download file could not be positioned.',
                500
            );
            throw new RuntimeException('The download file could not be positioned.');
        }

        @set_time_limit(0);
        $chunkSize = $bytesPerSecond > 0
            ? max(4096, min(256 * 1024, intdiv($bytesPerSecond, 4) ?: 4096))
            : 256 * 1024;
        $remaining = max(0, $length);
        $sent = 0;
        $startedAt = microtime(true);
        $failure = null;

        try {
            while ($remaining > 0 && !connection_aborted()) {
                $chunk = fread($handle, min($chunkSize, $remaining));
                if ($chunk === false || $chunk === '') {
                    $failure = 'The binary stream ended before the requested bytes were sent.';
                    break;
                }
                echo $chunk;
                $written = strlen($chunk);
                $remaining -= $written;
                $sent += $written;
                flush();

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
        } catch (Throwable $error) {
            $failure = $error->getMessage();
        } finally {
            fclose($handle);
        }

        $status = $remaining === 0
            ? 'completed'
            : (connection_aborted() ? 'interrupted' : 'failed');
        $this->audit->finish($auditId, $status, $sent, $failure);
        if ($failure !== null) {
            error_log('[UnrealDB download audit] ' . $failure);
        }
        exit;
    }
}
