<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns public-access configuration normalization, persistence and cache publication.
 * Why: Settings policy and storage should not be mixed with request blocking, rate limiting, SMTP form handling or file streaming.
 * Role: Infrastructure security settings store preserving the existing public-access configuration contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogPublicAccessSettingsStore
{
    /** @var array<string,array<string,mixed>> Request-local normalized settings by cache path. */
    private static array $requestCache = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
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

    /** @return list<string> */
    public static function settingNames(): array
    {
        return array_keys(self::defaults());
    }

    public static function boolValue(mixed $value, bool $default = false): bool
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

    public static function intValue(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return max($minimum, min($parsed === false ? $default : (int)$parsed, $maximum));
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public static function normalize(array $values): array
    {
        $defaults = self::defaults();
        return [
            'site_development_mode' => self::boolValue($values['site_development_mode'] ?? null, $defaults['site_development_mode']),
            'site_development_title' => substr(trim((string)($values['site_development_title'] ?? $defaults['site_development_title'])), 0, 180),
            'site_development_message' => substr(trim((string)($values['site_development_message'] ?? $defaults['site_development_message'])), 0, 2000),
            'feedback_enabled' => self::boolValue($values['feedback_enabled'] ?? null, $defaults['feedback_enabled']),
            'feedback_recipient' => substr(trim((string)($values['feedback_recipient'] ?? $defaults['feedback_recipient'])), 0, 254),
            'public_download_max_files' => self::intValue($values['public_download_max_files'] ?? null, 10, 1, 10000),
            'public_download_window_seconds' => self::intValue($values['public_download_window_seconds'] ?? null, 3600, 60, 604800),
            'public_package_max_builds' => self::intValue($values['public_package_max_builds'] ?? null, 10, 1, 10000),
            'public_package_window_seconds' => self::intValue($values['public_package_window_seconds'] ?? null, 3600, 60, 604800),
            'public_download_speed_kbps' => self::intValue($values['public_download_speed_kbps'] ?? null, 0, 0, 1048576),
            'public_block_crawlers' => self::boolValue($values['public_block_crawlers'] ?? null, true),
            'public_burst_max_requests' => self::intValue($values['public_burst_max_requests'] ?? null, 30, 2, 10000),
            'public_burst_window_seconds' => self::intValue($values['public_burst_window_seconds'] ?? null, 10, 1, 3600),
            'public_burst_block_seconds' => self::intValue($values['public_burst_block_seconds'] ?? null, 600, 10, 86400),
            'feedback_max_requests' => self::intValue($values['feedback_max_requests'] ?? null, 5, 1, 1000),
            'feedback_window_seconds' => self::intValue($values['feedback_window_seconds'] ?? null, 3600, 60, 604800),
        ];
    }

    /** @return array<string,mixed> */
    public function settings(?PDO $db = null): array
    {
        $path = $this->cachePath();
        if (!$db instanceof PDO && isset(self::$requestCache[$path])) {
            return self::$requestCache[$path];
        }

        $values = [];
        if ($db instanceof PDO) {
            $names = self::settingNames();
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $statement = $db->prepare(
                'SELECT setting_name,setting_value FROM ue_federation_settings WHERE setting_name IN (' . $placeholders . ')'
            );
            $statement->execute($names);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $values[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
            }
            $settings = self::normalize($values);
            self::$requestCache[$path] = $settings;
            try {
                $this->writeCache($settings);
            } catch (Throwable $error) {
                error_log('[UnrealDB public access] settings cache update failed: ' . $error->getMessage());
            }
            return $settings;
        }

        if (is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }
        return self::$requestCache[$path] = self::normalize($values);
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function save(PDO $db, array $settings): array
    {
        $settings = $this->saveDatabase($db, $settings);
        return $this->publish($settings);
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function saveDatabase(PDO $db, array $settings): array
    {
        $settings = self::normalize($settings);
        $statement = $db->prepare(
            'INSERT INTO ue_federation_settings(setting_name,setting_value) VALUES(?,?) '
            . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
        );
        foreach ($settings as $name => $value) {
            $statement->execute([$name, is_bool($value) ? ($value ? '1' : '0') : (string)$value]);
        }
        return $settings;
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function publish(array $settings): array
    {
        $settings = self::normalize($settings);
        $this->writeCache($settings);
        self::$requestCache[$this->cachePath()] = $settings;
        return $settings;
    }

    public static function windowLabel(int $seconds): string
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

    private function cachePath(): string
    {
        return rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-access-settings.json';
    }

    /** @param array<string,mixed> $settings */
    private function writeCache(array $settings): void
    {
        $path = $this->cachePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create public-access settings storage.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode(
            self::normalize($settings),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
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
}
