<?php
/**
 * First-party access matrix telemetry.
 *
 * Records coarse navigation/interaction metadata only. It deliberately excludes
 * form values, request bodies, cookies, credentials and query-string secrets.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;

final class CatalogAccessEventRecorder
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function recordPageView(): void
    {
        $script = basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $pageParam = trim((string)($_GET['page'] ?? ''));
        $pageKey = $script !== '' ? $script : 'unknown';
        if ($script === 'index.php' && $pageParam !== '') {
            $pageKey .= ':' . substr(preg_replace('/[^A-Za-z0-9._-]+/', '', $pageParam) ?? '', 0, 80);
        }

        $this->insert([
            'event_type' => 'server_page',
            'page_key' => $pageKey,
            'request_path' => self::safeRequestPath((string)($_SERVER['REQUEST_URI'] ?? $script)),
            'section_key' => null,
            'action_key' => null,
            'target_path' => null,
            'referrer_path' => self::safeReferrer((string)($_SERVER['HTTP_REFERER'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $event */
    public function recordClientEvent(array $event): void
    {
        $type = strtolower(trim((string)($event['event_type'] ?? '')));
        if (!in_array($type, ['page_view', 'section', 'interaction'], true)) {
            return;
        }

        $pageKey = self::text($event['page_key'] ?? '', 190);
        if ($pageKey === '') {
            $script = basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
            $pageKey = $script !== '' ? $script : 'unknown';
        }

        $this->insert([
            'event_type' => $type,
            'page_key' => $pageKey,
            'request_path' => self::safeRequestPath((string)($event['request_path'] ?? '')),
            'section_key' => self::nullableText($event['section_key'] ?? '', 190),
            'action_key' => self::nullableText($event['action_key'] ?? '', 190),
            'target_path' => self::nullableText($event['target_path'] ?? '', 500),
            'referrer_path' => self::safeReferrer((string)($event['referrer_path'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $event */
    private function insert(array $event): void
    {
        try {
            $ipText = function_exists('catalog_public_access_client_ip')
                ? trim((string)\catalog_public_access_client_ip())
                : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
            $packedIp = @inet_pton($ipText);
            $sessionHash = null;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $sessionId = session_id();
                if ($sessionId !== '') {
                    $sessionHash = hash('sha256', $sessionId, true);
                }
            }
            $userId = isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0
                ? (int)$_SESSION['user']['id']
                : null;

            $statement = $this->db->prepare(
                'INSERT INTO ue_access_events('
                . 'event_type,page_key,request_path,section_key,action_key,target_path,referrer_path,'
                . 'request_method,request_id,ip_address,user_id,session_hash,user_agent,occurred_at'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP(6))'
            );
            $statement->execute([
                self::text($event['event_type'] ?? 'page_view', 32),
                self::text($event['page_key'] ?? 'unknown', 190),
                self::text($event['request_path'] ?? '', 500),
                self::nullableText($event['section_key'] ?? '', 190),
                self::nullableText($event['action_key'] ?? '', 190),
                self::nullableText($event['target_path'] ?? '', 500),
                self::nullableText($event['referrer_path'] ?? '', 500),
                self::text($_SERVER['REQUEST_METHOD'] ?? 'GET', 12),
                function_exists('catalog_request_id') ? self::nullableText(\catalog_request_id(), 64) : null,
                is_string($packedIp) ? $packedIp : null,
                $userId,
                $sessionHash,
                self::text($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
            ]);
        } catch (\PDOException $error) {
            $message = strtolower($error->getMessage());
            if (strtoupper((string)$error->getCode()) === '42S02'
                || (str_contains($message, 'ue_access_events') && str_contains($message, 'doesn\'t exist'))) {
                return;
            }
            error_log('[UnrealDB access matrix] ' . $error->getMessage());
        } catch (\Throwable $error) {
            error_log('[UnrealDB access matrix] ' . $error->getMessage());
        }
    }

    private static function safeRequestPath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return self::text($value, 500);
        }
        $path = (string)($parts['path'] ?? '');
        $query = (string)($parts['query'] ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            foreach (array_keys($params) as $key) {
                if (preg_match('/(?:csrf|token|secret|password|key)/i', (string)$key) === 1) {
                    unset($params[$key]);
                }
            }
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return self::text($path . ($query !== '' ? '?' . $query : ''), 500);
    }

    private static function safeReferrer(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        $current = strtolower((string)(preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))) ?? ''));
        if ($host !== '' && $current !== '' && !hash_equals($current, $host)) {
            return null;
        }
        return self::safeRequestPath(
            (string)($parts['path'] ?? '') . (isset($parts['query']) ? '?' . (string)$parts['query'] : '')
        );
    }

    private static function nullableText(mixed $value, int $limit): ?string
    {
        $text = self::text($value, $limit);
        return $text !== '' ? $text : null;
    }

    private static function text(mixed $value, int $limit): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        return mb_strlen($value, 'UTF-8') > $limit
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : $value;
    }
}
