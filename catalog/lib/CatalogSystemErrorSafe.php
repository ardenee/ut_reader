<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog system error safe.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * Central, bounded PHP/API error capture. This bootstrap never buffers page or
 * download output; browser error capture is attached by the admin navigation.
 */
function catalog_system_error_register(): void
{
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['catalog_system_error_registered'])) {
        return;
    }
    $GLOBALS['catalog_system_error_registered'] = true;

    $previousErrorHandler = null;
    $previousErrorHandler = set_error_handler(
        static function (int $type, string $message, string $file, int $line) use (&$previousErrorHandler): bool {
            if ((error_reporting() & $type) !== 0) {
                catalog_system_error_record([
                    'source_kind' => 'php',
                    'severity' => catalog_system_error_php_severity($type),
                    'error_type' => catalog_system_error_php_type($type),
                    'message' => $message,
                    'source_file' => $file,
                    'source_line' => $line,
                ]);
            }
            return is_callable($previousErrorHandler)
                ? (bool)$previousErrorHandler($type, $message, $file, $line)
                : false;
        }
    );

    $previousExceptionHandler = null;
    $previousExceptionHandler = set_exception_handler(
        static function (Throwable $error) use (&$previousExceptionHandler): void {
            catalog_system_error_record_exception($error, 'php_uncaught');
            if (is_callable($previousExceptionHandler)) {
                $previousExceptionHandler($error);
                return;
            }
            $reference = catalog_system_error_request_id();
            error_log('[UnrealDB][' . $reference . '] uncaught ' . get_class($error) . ': '
                . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine()
                . "\n" . $error->getTraceAsString());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store');
            }
            echo 'The request could not be completed. Reference: ' . $reference;
        }
    );

    register_shutdown_function(static function (): void {
        $last = error_get_last();
        if (!is_array($last)) {
            return;
        }
        $type = (int)($last['type'] ?? 0);
        if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        catalog_system_error_record([
            'source_kind' => 'php_fatal',
            'severity' => 'critical',
            'error_type' => catalog_system_error_php_type($type),
            'message' => (string)($last['message'] ?? 'Unknown fatal error.'),
            'source_file' => (string)($last['file'] ?? ''),
            'source_line' => (int)($last['line'] ?? 0),
        ]);
    });
}

function catalog_system_error_connection(): ?PDO
{
    static $connection = null;
    static $attempted = false;
    if ($connection instanceof PDO) {
        return $connection;
    }
    if ($attempted || !function_exists('catalog_config')) {
        return null;
    }
    $attempted = true;
    try {
        $config = catalog_config();
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
        $connection = new PDO(
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
        return $connection;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string,mixed> $data */
function catalog_system_error_record(array $data): void
{
    static $busy = false;
    static $tableAvailable = null;
    if ($busy) {
        return;
    }
    $busy = true;
    try {
        $normalized = catalog_system_error_normalize($data);
        $db = catalog_system_error_connection();
        if (!$db instanceof PDO) {
            catalog_system_error_fallback($normalized, 'independent error-log database connection unavailable');
            return;
        }
        try {
            if ($tableAvailable === null) {
                $statement = $db->query(
                    'SELECT COUNT(*) FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_system_errors"'
                );
                $tableAvailable = (int)$statement->fetchColumn() === 1;
            }
            if (!$tableAvailable) {
                catalog_system_error_fallback($normalized, 'system error migration not applied');
                return;
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
                $normalized['error_key'], $normalized['source_kind'], $normalized['severity'], $normalized['error_type'],
                $normalized['message'], $normalized['route'], $normalized['request_method'], $normalized['http_status'],
                $normalized['source_file'], $normalized['source_line'],
                $normalized['trace_text'] !== '' ? $normalized['trace_text'] : null,
                $normalized['context_json'] !== '' ? $normalized['context_json'] : null,
                $normalized['request_id'], $normalized['user_id'] > 0 ? $normalized['user_id'] : null,
                $now, $now,
            ]);
        } catch (Throwable $failure) {
            catalog_system_error_fallback($normalized, $failure->getMessage());
        }
    } finally {
        $busy = false;
    }
}

function catalog_system_error_record_exception(Throwable $error, string $sourceKind = 'php'): void
{
    catalog_system_error_record([
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
function catalog_system_error_record_http(string $code, string $message, int $status, array $context = []): void
{
    catalog_system_error_record([
        'source_kind' => 'api',
        'severity' => $status >= 500 ? 'critical' : 'error',
        'error_type' => $code !== '' ? $code : 'http_error',
        'message' => $message,
        'http_status' => $status,
        'context' => $context,
    ]);
}

/** @param array<string,mixed> $data
 *  @return array<string,mixed>
 */
function catalog_system_error_normalize(array $data): array
{
    $sourceKind = catalog_system_error_identifier((string)($data['source_kind'] ?? 'php'), 32, 'php');
    $severity = strtolower(trim((string)($data['severity'] ?? 'error')));
    if (!in_array($severity, ['debug', 'info', 'warning', 'error', 'critical'], true)) {
        $severity = 'error';
    }
    $errorType = catalog_system_error_text((string)($data['error_type'] ?? 'runtime_error'), 120);
    $message = catalog_system_error_text((string)($data['message'] ?? 'Unknown error.'), 8000);
    $route = catalog_system_error_text((string)($data['route'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), 500);
    $method = catalog_system_error_identifier((string)($data['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')), 12, '');
    $statusValue = $data['http_status'] ?? 0;
    $httpStatus = max(0, min(599, is_numeric($statusValue) ? (int)$statusValue : 0));
    $sourceFile = catalog_system_error_text((string)($data['source_file'] ?? ''), 1000);
    $sourceLine = max(0, (int)($data['source_line'] ?? 0));
    $trace = catalog_system_error_text((string)($data['trace_text'] ?? ''), 16000);
    $requestId = catalog_system_error_text((string)($data['request_id'] ?? catalog_system_error_request_id()), 64);
    $userId = max(0, (int)($data['user_id'] ?? ($_SESSION['user']['id'] ?? 0)));

    $context = is_array($data['context'] ?? null) ? $data['context'] : [];
    $context = array_merge([
        'query_keys' => array_values(array_map('strval', array_keys($_GET ?? []))),
        'user_agent' => catalog_system_error_text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
    ], $context);
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $contextJson = is_string($contextJson) ? catalog_system_error_text($contextJson, 16000) : '';

    $fingerprintMessage = preg_replace('/\b[0-9a-f]{12,64}\b/i', '{id}', mb_strtolower($message, 'UTF-8')) ?? $message;
    $errorKey = hash('sha256', implode("\n", [
        $sourceKind, $errorType, $route, $sourceFile, (string)$sourceLine, $fingerprintMessage,
    ]));

    return [
        'error_key' => $errorKey,
        'source_kind' => $sourceKind,
        'severity' => $severity,
        'error_type' => $errorType !== '' ? $errorType : 'runtime_error',
        'message' => $message !== '' ? $message : 'Unknown error.',
        'route' => $route,
        'request_method' => strtoupper($method),
        'http_status' => $httpStatus,
        'source_file' => $sourceFile,
        'source_line' => $sourceLine,
        'trace_text' => $trace,
        'context_json' => $contextJson,
        'request_id' => $requestId,
        'user_id' => $userId,
    ];
}

/** @param array<string,mixed> $normalized */
function catalog_system_error_fallback(array $normalized, string $reason): void
{
    error_log('[UnrealDB system error][' . (string)$normalized['request_id'] . '] '
        . strtoupper((string)$normalized['severity']) . ' '
        . (string)$normalized['source_kind'] . '/' . (string)$normalized['error_type'] . ': '
        . (string)$normalized['message'] . ' | route=' . (string)$normalized['route']
        . ' | persistence=' . catalog_system_error_text($reason, 500));
}

function catalog_system_error_request_id(): string
{
    if (function_exists('catalog_request_id')) {
        try {
            $value = trim((string)catalog_request_id());
            if ($value !== '') return $value;
        } catch (Throwable) {
        }
    }
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable) {
        return str_replace('.', '', uniqid('error', true));
    }
}

function catalog_system_error_php_type(int $type): string
{
    return match ($type) {
        E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE', E_STRICT => 'E_STRICT', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        default => 'PHP_' . $type,
    };
}

function catalog_system_error_php_severity(int $type): string
{
    return match ($type) {
        E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'critical',
        E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'error',
        default => 'warning',
    };
}

function catalog_system_error_identifier(string $value, int $max, string $fallback): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
    $value = trim($value, '._:-');
    return $value !== '' ? substr($value, 0, $max) : $fallback;
}

function catalog_system_error_text(string $value, int $max): string
{
    $value = trim((string)(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
}
