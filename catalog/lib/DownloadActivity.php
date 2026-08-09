<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the stable download/generation audit helper API for existing entry points and workers.
 * Why: Audit persistence and throttled range streaming now have focused namespaced owners.
 * Role: Thin compatibility facade; do not add download telemetry or streaming implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogDownloadAuditService;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogDownloadRangeStreamer;

function catalog_download_audit_user_agent(): string
{
    return CatalogDownloadAuditService::userAgent();
}

/** @param array<string,mixed> $data */
function catalog_download_audit_generation_queued(PDO $db, array $data): void
{
    (new CatalogDownloadAuditService($db))->generationQueued($data);
}

/** @param array<string,mixed> $data */
function catalog_download_audit_generation_status(PDO $db, int $jobId, string $status, array $data = []): void
{
    (new CatalogDownloadAuditService($db))->generationStatus($jobId, $status, $data);
}

/** @param array<string,mixed> $data */
function catalog_download_audit_start(PDO $db, array $data): ?int
{
    return (new CatalogDownloadAuditService($db))->start($data);
}

function catalog_download_audit_finish(
    PDO $db,
    ?int $auditId,
    string $status,
    int $bytesSent,
    ?string $errorMessage = null,
    ?int $httpStatus = null
): void {
    (new CatalogDownloadAuditService($db))->finish(
        $auditId,
        $status,
        $bytesSent,
        $errorMessage,
        $httpStatus
    );
}

/** @return never */
function catalog_download_audit_stream(
    PDO $db,
    ?int $auditId,
    string $path,
    int $start,
    int $length,
    int $bytesPerSecond = 0
): never {
    (new CatalogDownloadRangeStreamer($db))->stream(
        $auditId,
        $path,
        $start,
        $length,
        $bytesPerSecond
    );
}
