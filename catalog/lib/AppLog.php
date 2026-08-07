<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for app log.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function app_log(PDO $db, string $level, string $event, string $message = '', array $context = []): void
{
    $level = strtoupper(trim($level));
    if (!in_array($level, ['DEBUG', 'INFO', 'WARN', 'ERROR'], true)) {
        $level = 'INFO';
    }

    $event = strtoupper(trim($event));
    if ($event === '') {
        $event = 'APP_EVENT';
    }

    $contextJson = $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    try {
        $stmt = $db->prepare('INSERT INTO ue_app_logs(level, event, message, context_json, request_uri, remote_addr, user_id) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([
            $level,
            $event,
            $message,
            $contextJson,
            substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 1000),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null,
        ]);
    } catch (Throwable $e) {
        error_log('[UnrealDB app_log fallback] ' . $level . ' ' . $event . ' ' . $message . ' | db_log_error=' . $e->getMessage());
    }
}

function app_log_exception(PDO $db, string $event, Throwable $e, array $context = []): void
{
    $context['exception_class'] = get_class($e);
    $context['exception_message'] = $e->getMessage();
    $context['exception_file'] = $e->getFile();
    $context['exception_line'] = $e->getLine();
    $context['exception_trace'] = $e->getTraceAsString();

    app_log($db, 'ERROR', $event, $e->getMessage(), $context);
}
