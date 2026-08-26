<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists generated-package and download audit lifecycle records.
 * Why: Download/generation telemetry persistence should be separate from HTTP file streaming and procedural page helpers.
 * Role: Infrastructure downloads audit service preserving existing best-effort logging semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use Throwable;

final class CatalogDownloadAuditService
{
    private readonly CatalogGeoIpCountryResolver $geoIp;

    public function __construct(private readonly PDO $db)
    {
        $this->geoIp = new CatalogGeoIpCountryResolver($db);
    }

    public static function text(mixed $value, int $maximum): string
    {
        $value = trim(str_replace("\0", '', (string)$value));
        return substr($value, 0, max(0, $maximum));
    }

    public static function ip(?string $ip): ?string
    {
        $ip = trim((string)$ip);
        if ($ip === '' || strtolower($ip) === 'unknown') {
            return null;
        }
        $packed = @inet_pton($ip);
        return is_string($packed) && in_array(strlen($packed), [4, 16], true) ? $packed : null;
    }

    public static function userAgent(): string
    {
        return self::text($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
    }

    /** @param array<string,mixed> $data */
    public function generationQueued(array $data): void
    {
        try {
            $ipText = (string)($data['ip_address'] ?? '');
            $country = $this->geoIp->resolve($ipText);
            $statement = $this->db->prepare(
                'INSERT INTO ue_generated_package_audit('
                . 'job_id,file_id,game_id,user_id,request_ip,country_code,country_name,user_agent,package_format,package_name,package_version,'
                . 'include_dependencies,allow_incomplete,status,queued_at,updated_at'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,"queued",CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'file_id=VALUES(file_id),game_id=VALUES(game_id),user_id=VALUES(user_id),request_ip=VALUES(request_ip),'
                . 'country_code=VALUES(country_code),country_name=VALUES(country_name),'
                . 'user_agent=VALUES(user_agent),package_format=VALUES(package_format),package_name=VALUES(package_name),'
                . 'package_version=VALUES(package_version),include_dependencies=VALUES(include_dependencies),'
                . 'allow_incomplete=VALUES(allow_incomplete),updated_at=CURRENT_TIMESTAMP(6)'
            );
            $statement->execute([
                max(1, (int)($data['job_id'] ?? 0)),
                max(1, (int)($data['file_id'] ?? 0)),
                max(1, (int)($data['game_id'] ?? 0)),
                isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null,
                self::ip($ipText),
                $country['country_code'] !== '' ? $country['country_code'] : null,
                $country['country_name'] !== '' ? $country['country_name'] : null,
                self::text($data['user_agent'] ?? '', 500),
                self::text($data['package_format'] ?? '', 32),
                self::text($data['package_name'] ?? '', 255),
                self::text($data['package_version'] ?? '', 80),
                !empty($data['include_dependencies']) ? 1 : 0,
                !empty($data['allow_incomplete']) ? 1 : 0,
            ]);
        } catch (Throwable $error) {
            error_log(
                '[UnrealDB download audit] Could not record package generation #'
                . (int)($data['job_id'] ?? 0) . ': ' . $error->getMessage()
            );
        }
    }

    /** @param array<string,mixed> $data */
    public function generationStatus(int $jobId, string $status, array $data = []): void
    {
        if ($jobId < 1) {
            return;
        }
        $status = self::text($status, 24);
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
                $values[] = self::text($data['artifact_name'], 255);
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
                $message = self::text($data['error_message'], 1000);
                $values[] = $message !== '' ? $message : null;
            }
            $values[] = $jobId;
            $statement = $this->db->prepare(
                'UPDATE ue_generated_package_audit SET ' . implode(',', $sets) . ' WHERE job_id=?'
            );
            $statement->execute($values);
        } catch (Throwable $error) {
            error_log(
                '[UnrealDB download audit] Could not update package generation #'
                . $jobId . ': ' . $error->getMessage()
            );
        }
    }

    /** @param array<string,mixed> $data */
    public function start(array $data): ?int
    {
        try {
            $ipText = (string)($data['ip_address'] ?? '');
            $country = $this->geoIp->resolve($ipText);
            $statement = $this->db->prepare(
                'INSERT INTO ue_download_audit('
                . 'download_type,file_id,game_id,job_id,user_id,ip_address,country_code,country_name,user_agent,download_name,package_format,'
                . 'artifact_size,range_start,range_end,bytes_requested,bytes_sent,status,http_status,started_at'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,CURRENT_TIMESTAMP(6))'
            );
            $statement->execute([
                self::text($data['download_type'] ?? 'file', 32),
                isset($data['file_id']) && (int)$data['file_id'] > 0 ? (int)$data['file_id'] : null,
                isset($data['game_id']) && (int)$data['game_id'] > 0 ? (int)$data['game_id'] : null,
                isset($data['job_id']) && (int)$data['job_id'] > 0 ? (int)$data['job_id'] : null,
                isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null,
                self::ip($ipText),
                $country['country_code'] !== '' ? $country['country_code'] : null,
                $country['country_name'] !== '' ? $country['country_name'] : null,
                self::text($data['user_agent'] ?? '', 500),
                self::text($data['download_name'] ?? '', 255),
                isset($data['package_format']) && trim((string)$data['package_format']) !== ''
                    ? self::text($data['package_format'], 32)
                    : null,
                isset($data['artifact_size']) ? max(0, (int)$data['artifact_size']) : null,
                isset($data['range_start']) ? max(0, (int)$data['range_start']) : null,
                isset($data['range_end']) ? max(0, (int)$data['range_end']) : null,
                isset($data['bytes_requested']) ? max(0, (int)$data['bytes_requested']) : null,
                self::text($data['status'] ?? 'started', 24),
                max(100, min(599, (int)($data['http_status'] ?? 200))),
            ]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $error) {
            error_log('[UnrealDB download audit] Could not record download: ' . $error->getMessage());
            return null;
        }
    }

    public function finish(
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
                self::text($status, 24),
                max(0, $bytesSent),
                ($message = self::text($errorMessage ?? '', 1000)) !== '' ? $message : null,
            ];
            if ($httpStatus !== null) {
                $sets[] = 'http_status=?';
                $values[] = max(100, min(599, $httpStatus));
            }
            $values[] = $auditId;
            $statement = $this->db->prepare(
                'UPDATE ue_download_audit SET ' . implode(',', $sets) . ' WHERE id=?'
            );
            $statement->execute($values);
        } catch (Throwable $error) {
            error_log(
                '[UnrealDB download audit] Could not complete download record #'
                . $auditId . ': ' . $error->getMessage()
            );
        }
    }
}
