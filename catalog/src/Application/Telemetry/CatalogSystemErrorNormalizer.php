<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Normalizes, bounds and fingerprints captured PHP/API errors before persistence.
 * Why: Error sanitization and dedupe identity are application policy separate from handler registration and database IO.
 * Role: Application telemetry policy preserving the established system-error record shape and bounds.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Telemetry;

use Throwable;

final class CatalogSystemErrorNormalizer
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function normalize(array $data): array
    {
        $sourceKind = self::identifier((string)($data['source_kind'] ?? 'php'), 32, 'php');
        $severity = strtolower(trim((string)($data['severity'] ?? 'error')));
        if (!in_array($severity, ['debug', 'info', 'warning', 'error', 'critical'], true)) {
            $severity = 'error';
        }
        $errorType = self::text((string)($data['error_type'] ?? 'runtime_error'), 120);
        $message = self::text((string)($data['message'] ?? 'Unknown error.'), 8000);
        $route = self::text((string)($data['route'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), 500);
        $method = self::identifier((string)($data['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')), 12, '');
        $statusValue = $data['http_status'] ?? 0;
        $httpStatus = max(0, min(599, is_numeric($statusValue) ? (int)$statusValue : 0));
        $sourceFile = self::text((string)($data['source_file'] ?? ''), 1000);
        $sourceLine = max(0, (int)($data['source_line'] ?? 0));
        $trace = self::text((string)($data['trace_text'] ?? ''), 16000);
        $requestId = self::text((string)($data['request_id'] ?? self::requestId()), 64);
        $userId = max(0, (int)($data['user_id'] ?? ($_SESSION['user']['id'] ?? 0)));

        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $context = array_merge([
            'query_keys' => array_values(array_map('strval', array_keys($_GET ?? []))),
            'user_agent' => self::text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
        ], $context);
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $contextJson = is_string($contextJson) ? self::text($contextJson, 16000) : '';

        $fingerprintMessage = preg_replace(
            '/\b[0-9a-f]{12,64}\b/i',
            '{id}',
            mb_strtolower($message, 'UTF-8')
        ) ?? $message;
        $fingerprintRoute = $route;

        // Use the durable job id as the fingerprint route. The worker reporter
        // now keeps provenance in structured context instead of appending queue
        // state and temporary paths to the visible error sentence; the trailing
        // cleanup remains for historical records created by older workers.
        if ($sourceKind === 'background-job') {
            $jobId = max(0, (int)($context['job_id'] ?? 0));
            if ($jobId > 0) {
                $disposition = self::identifier((string)($context['disposition'] ?? ''), 40, '');
                $fingerprintRoute = 'job:' . $jobId . ($disposition !== '' ? ':' . $disposition : '');
                foreach ([
                    '/\s+file:\s+.*$/isu',
                    '/\s+archive:\s+.*$/isu',
                    '/\s+archive source:\s+.*$/isu',
                    '/\s+source:\s+.*$/isu',
                ] as $pattern) {
                    $normalizedFingerprint = preg_replace($pattern, '', $fingerprintMessage);
                    if (is_string($normalizedFingerprint) && trim($normalizedFingerprint) !== '') {
                        $fingerprintMessage = $normalizedFingerprint;
                    }
                }
            }
        }

        $errorKey = hash('sha256', implode("\n", [
            $sourceKind,
            $errorType,
            $fingerprintRoute,
            $sourceFile,
            (string)$sourceLine,
            $fingerprintMessage,
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

    public static function requestId(): string
    {
        if (function_exists('catalog_request_id')) {
            try {
                $value = trim((string)\catalog_request_id());
                if ($value !== '') {
                    return $value;
                }
            } catch (Throwable) {
            }
        }
        try {
            return bin2hex(random_bytes(12));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('error', true));
        }
    }

    public static function phpType(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'PHP_' . $type,
        };
    }

    public static function phpSeverity(int $type): string
    {
        return match ($type) {
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'critical',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'error',
            default => 'warning',
        };
    }

    public static function identifier(string $value, int $max, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        $value = trim($value, '._:-');
        return $value !== '' ? substr($value, 0, $max) : $fallback;
    }

    public static function text(string $value, int $max): string
    {
        $value = trim((string)(preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u',
            ' ',
            $value
        ) ?? $value));
        return mb_strlen($value, 'UTF-8') > $max
            ? mb_substr($value, 0, $max, 'UTF-8')
            : $value;
    }
}
