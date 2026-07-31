<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Security\FileRequestRateLimiter;

function catalog_public_access_defaults(): array
{
    return [
        'site_development_mode' => true,
        'site_development_title' => 'UnrealDB is under active development',
        'site_development_message' => 'Not every function is available yet. The site is public so visitors can explore the verified-file catalog and see what will be possible soon.',
        'feedback_enabled' => false,
        'feedback_recipient' => 'info@unrealdb.com',
        'public_download_max_files' => 10,
        'public_download_window_seconds' => 3600,
        'public_package_max_builds' => 10,
        'public_package_window_seconds' => 3600,
        'public_download_speed_kbps' => 0,
        'public_block_crawlers' => true,
        'public_burst_max_requests' => 30,
        'public_burst_window_seconds' => 10,
        'public_burst_block_seconds' => 600,
        'feedback_max_requests' => 5,
        'feedback_window_seconds' => 3600,
    ];
}

function catalog_public_access_setting_names(): array
{
    return array_keys(catalog_public_access_defaults());
}

function catalog_public_access_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

function catalog_public_access_int(mixed $value, int $default, int $minimum, int $maximum): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return max($minimum, min($parsed === false ? $default : (int)$parsed, $maximum));
}

function catalog_public_access_normalize(array $values): array
{
    $defaults = catalog_public_access_defaults();
    return [
        'site_development_mode' => catalog_public_access_bool($values['site_development_mode'] ?? null, $defaults['site_development_mode']),
        'site_development_title' => substr(trim((string)($values['site_development_title'] ?? $defaults['site_development_title'])), 0, 180),
        'site_development_message' => substr(trim((string)($values['site_development_message'] ?? $defaults['site_development_message'])), 0, 2000),
        'feedback_enabled' => catalog_public_access_bool($values['feedback_enabled'] ?? null, $defaults['feedback_enabled']),
        'feedback_recipient' => substr(trim((string)($values['feedback_recipient'] ?? $defaults['feedback_recipient'])), 0, 254),
        'public_download_max_files' => catalog_public_access_int($values['public_download_max_files'] ?? null, 10, 1, 10000),
        'public_download_window_seconds' => catalog_public_access_int($values['public_download_window_seconds'] ?? null, 3600, 60, 604800),
        'public_package_max_builds' => catalog_public_access_int($values['public_package_max_builds'] ?? null, 10, 1, 10000),
        'public_package_window_seconds' => catalog_public_access_int($values['public_package_window_seconds'] ?? null, 3600, 60, 604800),
        'public_download_speed_kbps' => catalog_public_access_int($values['public_download_speed_kbps'] ?? null, 0, 0, 1048576),
        'public_block_crawlers' => catalog_public_access_bool($values['public_block_crawlers'] ?? null, true),
        'public_burst_max_requests' => catalog_public_access_int($values['public_burst_max_requests'] ?? null, 30, 2, 10000),
        'public_burst_window_seconds' => catalog_public_access_int($values['public_burst_window_seconds'] ?? null, 10, 1, 3600),
        'public_burst_block_seconds' => catalog_public_access_int($values['public_burst_block_seconds'] ?? null, 600, 10, 86400),
        'feedback_max_requests' => catalog_public_access_int($values['feedback_max_requests'] ?? null, 5, 1, 1000),
        'feedback_window_seconds' => catalog_public_access_int($values['feedback_window_seconds'] ?? null, 3600, 60, 604800),
    ];
}

function catalog_public_access_cache_path(array $config): string
{
    return rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-access-settings.json';
}

function catalog_public_access_write_cache(array $config, array $settings): void
{
    $path = catalog_public_access_cache_path($config);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create public-access settings storage.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
    $json = json_encode(catalog_public_access_normalize($settings), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not write public-access settings cache.');
    }
    if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
        @unlink($path);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish public-access settings cache.');
    }
    @chmod($path, 0600);
}

function catalog_public_access_settings(?PDO $db = null, ?array $config = null): array
{
    $config ??= function_exists('catalog_config') ? catalog_config() : [];
    $values = [];

    if ($db instanceof PDO) {
        $names = catalog_public_access_setting_names();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $db->prepare(
            'SELECT setting_name,setting_value FROM ue_federation_settings WHERE setting_name IN (' . $placeholders . ')'
        );
        $statement->execute($names);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
        }
        $settings = catalog_public_access_normalize($values);
        try {
            catalog_public_access_write_cache($config, $settings);
        } catch (Throwable $error) {
            error_log('[UnrealDB public access] settings cache update failed: ' . $error->getMessage());
        }
        return $settings;
    }

    $path = catalog_public_access_cache_path($config);
    if (is_file($path) && is_readable($path)) {
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $values = $decoded;
        }
    }
    return catalog_public_access_normalize($values);
}

function catalog_public_access_save(PDO $db, array $config, array $settings): array
{
    $settings = catalog_public_access_normalize($settings);
    $statement = $db->prepare(
        'INSERT INTO ue_federation_settings(setting_name,setting_value) VALUES(?,?) '
        . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    );
    foreach ($settings as $name => $value) {
        $statement->execute([$name, is_bool($value) ? ($value ? '1' : '0') : (string)$value]);
    }
    catalog_public_access_write_cache($config, $settings);
    return $settings;
}

function catalog_public_access_client_ip(): string
{
    $ip = function_exists('catalog_client_ip') ? trim((string)catalog_client_ip()) : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

function catalog_public_access_known_crawler(string $userAgent): bool
{
    if ($userAgent === '') {
        return false;
    }
    return preg_match(
        '/(?:bot\b|crawler|spider|slurp|bingpreview|facebookexternalhit|bytespider|headlesschrome|phantomjs|wget|curl\/|python-requests|scrapy|go-http-client|java\/)/i',
        $userAgent
    ) === 1;
}

function catalog_public_access_exempt(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (session_status() === PHP_SESSION_ACTIVE && (($_SESSION['user']['role'] ?? '') === 'admin')) {
        return true;
    }
    // Never trust the presence of a remember cookie by itself: any visitor can
    // create a cookie with that name. Restore and verify the administrator token
    // through the normal authentication path before granting an exemption.
    $sessionCookie = session_name();
    $hasSessionCookie = $sessionCookie !== ''
        && isset($_COOKIE[$sessionCookie])
        && trim((string)$_COOKIE[$sessionCookie]) !== '';
    $hasRememberCookie = !empty($_COOKIE['UNREALDB_REMEMBER']);
    if (($hasSessionCookie || $hasRememberCookie) && function_exists('catalog_support_is_admin')) {
        try {
            return catalog_support_is_admin();
        } catch (Throwable $error) {
            error_log('[UnrealDB public access] administrator exemption check failed: ' . $error->getMessage());
        }
    }
    return false;
}

function catalog_public_access_burst_state(array $config, string $identity, int $maximum, int $windowSeconds, int $blockSeconds): int
{
    $directory = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-burst';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create public burst-control storage.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open public burst-control state.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock public burst-control state.');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }
        $now = time();
        $blockedUntil = max(0, (int)($state['blocked_until'] ?? 0));
        if ($blockedUntil > $now) {
            $retry = $blockedUntil - $now;
        } else {
            $cutoff = $now - max(1, $windowSeconds);
            $requests = [];
            foreach ((array)($state['requests'] ?? []) as $timestamp) {
                $timestamp = (int)$timestamp;
                if ($timestamp >= $cutoff && $timestamp <= $now + 60) {
                    $requests[] = $timestamp;
                }
            }
            $requests[] = $now;
            if (count($requests) > max(2, $maximum)) {
                $blockedUntil = $now + max(10, $blockSeconds);
                $retry = $blockedUntil - $now;
            } else {
                $blockedUntil = 0;
                $retry = 0;
            }
            $state['requests'] = $requests;
            $state['blocked_until'] = $blockedUntil;
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Could not persist public burst-control state.');
        }
        @chmod($path, 0600);
        return $retry;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function catalog_public_access_abort(int $status, string $title, string $message, int $retryAfter = 0): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
        }
    }
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $escape($title) . '</title><style>body{font-family:system-ui,sans-serif;background:#111827;color:#e5e7eb;padding:32px}'
        . '.card{max-width:760px;margin:auto;background:#1f2937;border:1px solid #4b5563;border-radius:10px;padding:24px}'
        . 'h1{margin-top:0}a{color:#93c5fd}</style></head><body><div class="card"><h1>' . $escape($title) . '</h1><p>'
        . $escape($message) . '</p>'
        . ($retryAfter > 0 ? '<p>Try again in approximately ' . $retryAfter . ' seconds.</p>' : '')
        . '<p><a href="index.php">Return to UnrealDB</a></p></div></body></html>';
    exit;
}

function catalog_public_access_guard_request(): void
{
    if (catalog_public_access_exempt()) {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }
    $settings = catalog_public_access_settings();
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($settings['public_block_crawlers'] && catalog_public_access_known_crawler($userAgent)) {
        catalog_public_access_abort(403, 'Automated access blocked', 'Automated crawlers and bulk link scanners are not permitted on this development service.');
    }
    $config = catalog_config();
    $retryAfter = catalog_public_access_burst_state(
        $config,
        catalog_public_access_client_ip(),
        $settings['public_burst_max_requests'],
        $settings['public_burst_window_seconds'],
        $settings['public_burst_block_seconds']
    );
    if ($retryAfter > 0) {
        catalog_public_access_abort(429, 'Temporarily blocked', 'Too many pages or links were opened in a short period. This IP has been temporarily blocked.', $retryAfter);
    }
}

function catalog_public_access_rate_limit(PDO $db, string $scope, int $maximum, int $windowSeconds): int
{
    if (function_exists('catalog_support_is_admin') && catalog_support_is_admin()) {
        return 0;
    }
    $config = catalog_config();
    $directory = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-actions';
    $limiter = new FileRequestRateLimiter($directory, max(1, $maximum), max(60, $windowSeconds));
    return $limiter->consume($scope, catalog_public_access_client_ip());
}

function catalog_public_access_limit_or_throw(PDO $db, string $scope, int $maximum, int $windowSeconds, string $label): void
{
    $retryAfter = catalog_public_access_rate_limit($db, $scope, $maximum, $windowSeconds);
    if ($retryAfter > 0) {
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
        }
        throw new RuntimeException($label . ' limit reached for this IP address. Try again in ' . $retryAfter . ' seconds.');
    }
}

function catalog_public_download_limit(PDO $db): void
{
    $settings = catalog_public_access_settings($db);
    catalog_public_access_limit_or_throw(
        $db,
        'public-file-download',
        $settings['public_download_max_files'],
        $settings['public_download_window_seconds'],
        'Public download'
    );
}

function catalog_public_package_limit(PDO $db): void
{
    $settings = catalog_public_access_settings($db);
    catalog_public_access_limit_or_throw(
        $db,
        'public-package-generation',
        $settings['public_package_max_builds'],
        $settings['public_package_window_seconds'],
        'Generated package'
    );
}

function catalog_public_feedback_limit(PDO $db): void
{
    $settings = catalog_public_access_settings($db);
    catalog_public_access_limit_or_throw(
        $db,
        'public-feedback',
        $settings['feedback_max_requests'],
        $settings['feedback_window_seconds'],
        'Feedback submission'
    );
}

function catalog_public_download_speed_bytes(PDO $db): int
{
    $settings = catalog_public_access_settings($db);
    return max(0, (int)$settings['public_download_speed_kbps']) * 1024;
}

function catalog_public_stream_file(string $path, int $bytesPerSecond = 0): never
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

function catalog_public_access_window_label(int $seconds): string
{
    if ($seconds % 3600 === 0) {
        $hours = intdiv($seconds, 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    if ($seconds % 60 === 0) {
        $minutes = intdiv($seconds, 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    return $seconds . ' seconds';
}
