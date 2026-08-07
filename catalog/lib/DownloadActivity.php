<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for download activity.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

function catalog_download_audit_text(mixed $value, int $maximum): string
{
    $value = trim(str_replace("\0", '', (string)$value));
    return substr($value, 0, max(0, $maximum));
}

function catalog_download_audit_ip(?string $ip): ?string
{
    $ip = trim((string)$ip);
    if ($ip === '' || strtolower($ip) === 'unknown') {
        return null;
    }
    $packed = @inet_pton($ip);
    return is_string($packed) && in_array(strlen($packed), [4, 16], true) ? $packed : null;
}

function catalog_download_audit_user_agent(): string
{
    return catalog_download_audit_text($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
}

/** @param array<string,mixed> $data */
function catalog_download_audit_generation_queued(PDO $db, array $data): void
{
    try {
        $statement = $db->prepare(
            'INSERT INTO ue_generated_package_audit('
            . 'job_id,file_id,game_id,user_id,request_ip,user_agent,package_format,package_name,package_version,'
            . 'include_dependencies,allow_incomplete,status,queued_at,updated_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,"queued",CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'file_id=VALUES(file_id),game_id=VALUES(game_id),user_id=VALUES(user_id),request_ip=VALUES(request_ip),'
            . 'user_agent=VALUES(user_agent),package_format=VALUES(package_format),package_name=VALUES(package_name),'
            . 'package_version=VALUES(package_version),include_dependencies=VALUES(include_dependencies),'
            . 'allow_incomplete=VALUES(allow_incomplete),updated_at=CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            max(1, (int)($data['job_id'] ?? 0)),
            max(1, (int)($data['file_id'] ?? 0)),
            max(1, (int)($data['game_id'] ?? 0)),
            isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null,
            catalog_download_audit_ip((string)($data['ip_address'] ?? '')),
            catalog_download_audit_text($data['user_agent'] ?? '', 500),
            catalog_download_audit_text($data['package_format'] ?? '', 32),
            catalog_download_audit_text($data['package_name'] ?? '', 255),
            catalog_download_audit_text($data['package_version'] ?? '', 80),
            !empty($data['include_dependencies']) ? 1 : 0,
            !empty($data['allow_incomplete']) ? 1 : 0,
        ]);
    } catch (Throwable $error) {
        error_log('[UnrealDB download audit] Could not record package generation #' . (int)($data['job_id'] ?? 0) . ': ' . $error->getMessage());
    }
}

/** @param array<string,mixed> $data */
function catalog_download_audit_generation_status(PDO $db, int $jobId, string $status, array $data = []): void
{
    if ($jobId < 1) {
        return;
    }
    $status = catalog_download_audit_text($status, 24);
    try {
        $sets = ['status=?', 'updated_at=CURRENT_TIMESTAMP(6)'];
        $values = [$status];
        if ($status === 'running') {
            $sets[] = 'started_at=COALESCE(started_at,CURRENT_TIMESTAMP(6))';
        }
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $sets[] = 'completed_at=CURRENT_TIMESTAMP(6)';
        }
        if (array_key_exists('artifact_name', $data)) {
            $sets[] = 'artifact_name=?';
            $values[] = catalog_download_audit_text($data['artifact_name'], 255);
        }
        if (array_key_exists('artifact_size', $data)) {
            $sets[] = 'artifact_size=?';
            $values[] = max(0, (int)$data['artifact_size']);
        }
        if (array_key_exists('artifact_sha256', $data)) {
            $sha = strtolower(trim((string)$data['artifact_sha256']));
            $sets[] = 'artifact_sha256=?';
            $values[] = preg_match('/^[a-f0-9]{64}$/', $sha) === 1 ? hex2bin($sha) : null;
        }
        if (array_key_exists('error_message', $data)) {
            $sets[] = 'error_message=?';
            $message = catalog_download_audit_text($data['error_message'], 1000);
            $values[] = $message !== '' ? $message : null;
        }
        $values[] = $jobId;
        $statement = $db->prepare(
            'UPDATE ue_generated_package_audit SET ' . implode(',', $sets) . ' WHERE job_id=?'
        );
        $statement->execute($values);
    } catch (Throwable $error) {
        error_log('[UnrealDB download audit] Could not update package generation #' . $jobId . ': ' . $error->getMessage());
    }
}

/** @param array<string,mixed> $data */
function catalog_download_audit_start(PDO $db, array $data): ?int
{
    try {
        $statement = $db->prepare(
            'INSERT INTO ue_download_audit('
            . 'download_type,file_id,game_id,job_id,user_id,ip_address,user_agent,download_name,package_format,'
            . 'artifact_size,range_start,range_end,bytes_requested,bytes_sent,status,http_status,started_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,CURRENT_TIMESTAMP(6))'
        );
        $statement->execute([
            catalog_download_audit_text($data['download_type'] ?? 'file', 32),
            isset($data['file_id']) && (int)$data['file_id'] > 0 ? (int)$data['file_id'] : null,
            isset($data['game_id']) && (int)$data['game_id'] > 0 ? (int)$data['game_id'] : null,
            isset($data['job_id']) && (int)$data['job_id'] > 0 ? (int)$data['job_id'] : null,
            isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null,
            catalog_download_audit_ip((string)($data['ip_address'] ?? '')),
            catalog_download_audit_text($data['user_agent'] ?? '', 500),
            catalog_download_audit_text($data['download_name'] ?? '', 255),
            isset($data['package_format']) && trim((string)$data['package_format']) !== ''
                ? catalog_download_audit_text($data['package_format'], 32)
                : null,
            isset($data['artifact_size']) ? max(0, (int)$data['artifact_size']) : null,
            isset($data['range_start']) ? max(0, (int)$data['range_start']) : null,
            isset($data['range_end']) ? max(0, (int)$data['range_end']) : null,
            isset($data['bytes_requested']) ? max(0, (int)$data['bytes_requested']) : null,
            catalog_download_audit_text($data['status'] ?? 'started', 24),
            max(100, min(599, (int)($data['http_status'] ?? 200))),
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $error) {
        error_log('[UnrealDB download audit] Could not record download: ' . $error->getMessage());
        return null;
    }
}

function catalog_download_audit_finish(
    PDO $db,
    ?int $auditId,
    string $status,
    int $bytesSent,
    ?string $errorMessage = null,
    ?int $httpStatus = null
): void {
    if ($auditId === null || $auditId < 1) {
        return;
    }
    try {
        $sets = [
            'status=?',
            'bytes_sent=?',
            'completed_at=CURRENT_TIMESTAMP(6)',
            'error_message=?',
        ];
        $values = [
            catalog_download_audit_text($status, 24),
            max(0, $bytesSent),
            ($message = catalog_download_audit_text($errorMessage ?? '', 1000)) !== '' ? $message : null,
        ];
        if ($httpStatus !== null) {
            $sets[] = 'http_status=?';
            $values[] = max(100, min(599, $httpStatus));
        }
        $values[] = $auditId;
        $statement = $db->prepare('UPDATE ue_download_audit SET ' . implode(',', $sets) . ' WHERE id=?');
        $statement->execute($values);
    } catch (Throwable $error) {
        error_log('[UnrealDB download audit] Could not complete download record #' . $auditId . ': ' . $error->getMessage());
    }
}

/**
 * Stream an exact file range and finish its audit row before exiting.
 *
 * @return never
 */
function catalog_download_audit_stream(
    PDO $db,
    ?int $auditId,
    string $path,
    int $start,
    int $length,
    int $bytesPerSecond = 0
): never {
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        catalog_download_audit_finish($db, $auditId, 'failed', 0, 'The download file could not be opened.', 500);
        throw new RuntimeException('The download file could not be opened.');
    }
    if ($start > 0 && fseek($handle, $start, SEEK_SET) !== 0) {
        fclose($handle);
        catalog_download_audit_finish($db, $auditId, 'failed', 0, 'The download file could not be positioned.', 500);
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
    catalog_download_audit_finish($db, $auditId, $status, $sent, $failure);
    if ($failure !== null) {
        error_log('[UnrealDB download audit] ' . $failure);
    }
    exit;
}
