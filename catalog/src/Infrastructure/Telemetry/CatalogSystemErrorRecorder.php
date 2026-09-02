<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists bounded system-error events through an independent short-timeout database connection.
 * Why: Error persistence must remain available when the request's primary PDO is unhealthy and must never recursively fail.
 * Role: Infrastructure telemetry recorder preserving the existing dedupe/upsert and fallback-log behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Telemetry\CatalogSystemErrorNormalizer;

final class CatalogSystemErrorRecorder
{
    private static ?PDO $connection = null;
    private static bool $connectionAttempted = false;
    private static bool $busy = false;
    private static ?bool $tableAvailable = null;

    /** @param array<string,mixed> $data */
    public static function record(array $data): bool
    {
        if (self::$busy) {
            return false;
        }
        self::$busy = true;
        try {
            $normalized = CatalogSystemErrorNormalizer::normalize($data);
            $db = self::connection();
            if (!$db instanceof PDO) {
                self::fallback($normalized, 'independent error-log database connection unavailable');
                return false;
            }

            try {
                if (!self::tableAvailable($db)) {
                    self::fallback($normalized, 'system error migration not applied');
                    return false;
                }

                $now = gmdate('Y-m-d H:i:s');
                $statement = $db->prepare(
                    'INSERT INTO ue_system_errors '
                    . '(error_key,source_kind,severity,error_type,message,route,request_method,http_status,source_file,source_line,'
                    . 'trace_text,context_json,request_id,user_id,status,occurrence_count,first_seen_at,last_seen_at) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,"open",1,?,?) '
                    . 'ON DUPLICATE KEY UPDATE source_kind=VALUES(source_kind),severity=VALUES(severity),error_type=VALUES(error_type),'
                    . 'message=VALUES(message),route=VALUES(route),request_method=VALUES(request_method),http_status=VALUES(http_status),'
                    . 'source_file=VALUES(source_file),source_line=VALUES(source_line),trace_text=VALUES(trace_text),'
                    . 'context_json=VALUES(context_json),request_id=VALUES(request_id),user_id=VALUES(user_id),status="open",'
                    . 'occurrence_count=occurrence_count+1,last_seen_at=VALUES(last_seen_at),resolved_at=NULL,resolved_by=NULL,resolution_note=NULL'
                );
                $statement->execute([
                    $normalized['error_key'],
                    $normalized['source_kind'],
                    $normalized['severity'],
                    $normalized['error_type'],
                    $normalized['message'],
                    $normalized['route'],
                    $normalized['request_method'],
                    $normalized['http_status'],
                    $normalized['source_file'],
                    $normalized['source_line'],
                    $normalized['trace_text'] !== '' ? $normalized['trace_text'] : null,
                    $normalized['context_json'] !== '' ? $normalized['context_json'] : null,
                    $normalized['request_id'],
                    $normalized['user_id'] > 0 ? $normalized['user_id'] : null,
                    $now,
                    $now,
                ]);
                return true;
            } catch (Throwable $failure) {
                self::fallback($normalized, $failure->getMessage());
                return false;
            }
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Resolve only the operator error that represents an unreadable format-2
     * provider after a targeted repair has positively verified that provider.
     */
    public static function resolveCompactMetadataProvider(int $fileId): void
    {
        if ($fileId < 1 || self::$busy) {
            return;
        }
        self::$busy = true;
        try {
            $db = self::connection();
            if (!$db instanceof PDO || !self::tableAvailable($db)) {
                return;
            }
            try {
                $now = gmdate('Y-m-d H:i:s');
                $statement = $db->prepare(
                    'UPDATE ue_system_errors SET status="resolved",resolved_at=?,resolved_by=NULL,'
                    . 'resolution_note="Compact metadata provider verified successfully after repair." '
                    . 'WHERE status="open" AND source_kind="compact-metadata-provider" '
                    . 'AND error_type="UnreadableCompactMetadataProvider" '
                    . 'AND CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.provider_file_id")) AS UNSIGNED)=?'
                );
                $statement->execute([$now, $fileId]);
            } catch (Throwable $error) {
                error_log('[UnrealDB system error resolve] Could not resolve compact provider #' . $fileId . ': '
                    . $error->getMessage());
            }
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Resolve the exact invalid-Unreal System Error after an explicit retained
     * source revalidation has successfully read the package with current code.
     */
    public static function resolveInvalidUeJob(
        int $sourceJobId,
        int $revalidationJobId,
        string $md5 = '',
        string $sha1 = ''
    ): void {
        if ($sourceJobId < 1 || self::$busy) {
            return;
        }
        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            $md5 = '';
        }
        if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            $sha1 = '';
        }

        self::$busy = true;
        try {
            $db = self::connection();
            if (!$db instanceof PDO || !self::tableAvailable($db)) {
                return;
            }
            try {
                $now = gmdate('Y-m-d H:i:s');
                $note = 'Retained source revalidated successfully with current package reader'
                    . ($revalidationJobId > 0 ? ' by job #' . $revalidationJobId : '')
                    . '.';
                $statement = $db->prepare(
                    'UPDATE ue_system_errors SET status="resolved",resolved_at=?,resolved_by=NULL,resolution_note=? '
                    . 'WHERE status="open" AND source_kind="unreal-file-validation" '
                    . 'AND JSON_VALID(context_json) AND ('
                    . 'CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.job_id")) AS UNSIGNED)=? '
                    . 'OR (?<>"" AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.md5")),""))=?) '
                    . 'OR (?<>"" AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.sha1")),""))=?)'
                    . ')'
                );
                $statement->execute([
                    $now,
                    $note,
                    $sourceJobId,
                    $md5,
                    $md5,
                    $sha1,
                    $sha1,
                ]);
            } catch (Throwable $error) {
                error_log(
                    '[UnrealDB system error resolve] Could not resolve invalid UE source job #'
                    . $sourceJobId . ': ' . $error->getMessage()
                );
            }
        } finally {
            self::$busy = false;
        }
    }

    public static function recordException(Throwable $error, string $sourceKind = 'php'): void
    {
        self::record([
            'source_kind' => $sourceKind,
            'severity' => 'critical',
            'error_type' => get_class($error),
            'message' => $error->getMessage(),
            'source_file' => $error->getFile(),
            'source_line' => $error->getLine(),
            'trace_text' => $error->getTraceAsString(),
        ]);
    }

    /** @param array<string,mixed> $context */
    public static function recordHttp(string $code, string $message, int $status, array $context = []): void
    {
        self::record([
            'source_kind' => 'api',
            'severity' => $status >= 500 ? 'critical' : 'error',
            'error_type' => $code !== '' ? $code : 'http_error',
            'message' => $message,
            'http_status' => $status,
            'context' => $context,
        ]);
    }

    private static function connection(): ?PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        if (self::$connectionAttempted || !function_exists('catalog_config')) {
            return null;
        }
        self::$connectionAttempted = true;

        try {
            $config = \catalog_config();
            $database = is_array($config['db'] ?? null) ? $config['db'] : [];
            $host = trim((string)($database['host'] ?? ''));
            $name = trim((string)($database['database'] ?? ''));
            $username = (string)($database['username'] ?? '');
            if ($host === '' || $name === '' || $username === '') {
                return null;
            }

            $port = max(1, min(65535, (int)($database['port'] ?? 3306)));
            $charset = preg_match('/^[A-Za-z0-9_]+$/', (string)($database['charset'] ?? 'utf8mb4')) === 1
                ? (string)($database['charset'] ?? 'utf8mb4')
                : 'utf8mb4';
            self::$connection = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset,
                $username,
                (string)($database['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 2,
                ]
            );
            return self::$connection;
        } catch (Throwable) {
            return null;
        }
    }

    private static function tableAvailable(PDO $db): bool
    {
        if (self::$tableAvailable === null) {
            $statement = $db->query(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_system_errors"'
            );
            self::$tableAvailable = (int)$statement->fetchColumn() === 1;
        }
        return self::$tableAvailable;
    }

    /** @param array<string,mixed> $normalized */
    private static function fallback(array $normalized, string $reason): void
    {
        error_log(
            '[UnrealDB system error][' . (string)$normalized['request_id'] . '] '
            . strtoupper((string)$normalized['severity']) . ' '
            . (string)$normalized['source_kind'] . '/' . (string)$normalized['error_type'] . ': '
            . (string)$normalized['message'] . ' | route=' . (string)$normalized['route']
            . ' | persistence=' . CatalogSystemErrorNormalizer::text($reason, 500)
        );
    }
}
