<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns physical unverified-queue paths, tokens, filesystem inventory and pre-staging identity helpers.
 * Why: Queue storage policy is shared by upload, source, repair and admin flows and should not live as a procedural monolith.
 * Role: Infrastructure storage/read service behind the legacy UnverifiedFileManager compatibility facade.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogUnverifiedQueueStorage
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/CatalogRedirectArchive.php';
        require_once $root . '/lib/CatalogParser.php';
        require_once $root . '/lib/GameProfiles.php';
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /** @return array<string,mixed> */
    public static function bucketGame(): array
    {
        return [
            'id' => 0,
            'name' => 'Upload Bucket',
            'slug' => 'upload-bucket',
            'profile_id' => null,
            'profile_engine' => 'bucket',
        ];
    }

    /** @param array<string,mixed> $config */
    public static function storageRoot(array $config): string
    {
        $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            throw new RuntimeException('Catalog storage folder is unavailable.');
        }
        return $storageRoot;
    }

    /** @param array<string,mixed> $config */
    public static function storageGamesRoot(array $config): string
    {
        $gamesRoot = self::storageRoot($config) . DIRECTORY_SEPARATOR . 'games';
        if (!is_dir($gamesRoot) && !@mkdir($gamesRoot, 0775, true) && !is_dir($gamesRoot)) {
            throw new RuntimeException('Could not create the catalog games storage folder.');
        }
        $resolved = realpath($gamesRoot);
        if ($resolved === false) {
            throw new RuntimeException('Catalog games storage folder is unavailable.');
        }
        return $resolved;
    }

    /** @param array<string,mixed> $config */
    public static function uploadBucketDirectory(array $config, bool $create = false): string
    {
        $directory = self::storageRoot($config) . DIRECTORY_SEPARATOR . 'upload-bucket';
        if ($create && !is_dir($directory)
            && !@mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Could not create upload bucket storage.');
        }
        return $directory;
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $game */
    public static function unverifiedDirectory(array $config, array $game, bool $create = false): string
    {
        if ((int)($game['id'] ?? -1) === 0 || (string)($game['slug'] ?? '') === 'upload-bucket') {
            return self::uploadBucketDirectory($config, $create);
        }

        $slug = \scanner_slug_text((string)($game['slug'] ?? ''));
        $directory = self::storageGamesRoot($config)
            . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'unverified';
        if ($create && !is_dir($directory)
            && !@mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException(
                'Could not create unverified storage for ' . ($game['name'] ?? $slug) . '.'
            );
        }
        return $directory;
    }

    public static function pathInside(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if ($realPath === false || $realRoot === false) {
            return false;
        }
        return str_starts_with(
            $realPath,
            rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        );
    }

    public static function originalNameFromQueueName(string $queueName): string
    {
        $queueName = basename($queueName);
        return preg_replace('/^\d{8}_\d{6}_[A-Fa-f0-9]{8}_/', '', $queueName) ?: $queueName;
    }

    public static function token(int $gameId, string $queueName): string
    {
        return self::base64UrlEncode((string)json_encode([
            'game_id' => $gameId,
            'name' => basename($queueName),
        ], JSON_UNESCAPED_SLASHES));
    }

    public static function safeQueueName(string $originalName): string
    {
        $safeOriginal = basename(\catalog_clean_unreal_filename($originalName));
        $safeOriginal = trim($safeOriginal);
        if ($safeOriginal === '' || $safeOriginal === '.' || $safeOriginal === '..') {
            $safeOriginal = 'upload.bin';
        }
        return date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeOriginal;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{queue_name:string,original_name:string,size:int,path:string}
     */
    public static function storeBucketUpload(
        array $config,
        string $temporaryPath,
        string $originalName,
        string $reason
    ): array {
        if (!is_file($temporaryPath)) {
            throw new RuntimeException('Upload temporary file is missing.');
        }

        $directory = self::uploadBucketDirectory($config, true);
        $cleanName = \catalog_clean_unreal_filename($originalName);
        $queueName = self::safeQueueName($cleanName);
        $destination = self::uniqueDestination($directory, $queueName);

        $moved = is_uploaded_file($temporaryPath)
            ? @move_uploaded_file($temporaryPath, $destination)
            : @rename($temporaryPath, $destination);
        if (!$moved) {
            throw new RuntimeException('Could not move uploaded file into the upload bucket.');
        }

        @file_put_contents($destination . '.txt', $reason);
        return [
            'queue_name' => basename($destination),
            'original_name' => $cleanName,
            'size' => (int)(filesize($destination) ?: 0),
            'path' => $destination,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $legacy
     * @return array{md5:string,package_guid:string}
     */
    public static function identity(
        array $config,
        string $path,
        string $originalName,
        array $legacy
    ): array {
        $md5 = @md5_file($path);
        $packageGuid = '';
        $engine = strtoupper((string)(
            $legacy['engine_hint']
            ?? \gp_detect_from_extension(pathinfo($originalName, PATHINFO_EXTENSION))
            ?? ''
        ));

        if (in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            try {
                $header = \catalog_try_read_package_header($config, $engine, $path);
                $packageGuid = trim(\catalog_header_guid($header));
            } catch (Throwable) {
                // Keep MD5/legacy summary usable for unreadable packages.
            }
        }

        return [
            'md5' => is_string($md5) ? strtolower($md5) : '',
            'package_guid' => $packageGuid,
        ];
    }

    /** @return array<string,mixed> */
    public function resolve(string $token): array
    {
        $decoded = self::base64UrlDecode($token);
        $payload = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid unverified file reference.');
        }

        $gameId = (int)($payload['game_id'] ?? -1);
        $queueName = basename((string)($payload['name'] ?? ''));
        if ($gameId < 0
            || $queueName === ''
            || $queueName !== (string)($payload['name'] ?? '')
            || str_ends_with(strtolower($queueName), '.txt')) {
            throw new RuntimeException('Invalid unverified file reference.');
        }

        if ($gameId === 0) {
            $game = self::bucketGame();
        } else {
            $game = \catalog_one(
                $this->db,
                'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?',
                [$gameId]
            );
            if (!$game) {
                throw new RuntimeException('The source game no longer exists.');
            }
        }

        $directory = self::unverifiedDirectory($this->config, $game);
        $path = $directory . DIRECTORY_SEPARATOR . $queueName;
        if (!is_file($path) || !self::pathInside($path, $directory)) {
            throw new RuntimeException('The selected unverified file is no longer available.');
        }

        $reasonPath = $path . '.txt';
        $reason = '';
        if (is_file($reasonPath) && self::pathInside($reasonPath, $directory)) {
            $reason = trim((string)@file_get_contents($reasonPath, false, null, 0, 65535));
        }

        $originalName = self::originalNameFromQueueName($queueName);
        $legacy = \gp_read_legacy_summary($path);
        $header = [
            'ok' => !empty($legacy['ok']),
            'engine' => (string)(
                $legacy['engine_hint']
                ?? \gp_detect_from_extension(pathinfo($originalName, PATHINFO_EXTENSION))
                ?? 'UNKNOWN'
            ),
            'version' => !empty($legacy['ok']) ? (int)($legacy['version'] ?? 0) : null,
            'licensee' => !empty($legacy['ok']) ? (int)($legacy['licensee'] ?? 0) : null,
            'note' => !empty($legacy['ok']) ? '' : (string)($legacy['reason'] ?? ''),
        ];
        $identity = self::identity($this->config, $path, $originalName, $legacy);

        return [
            'token' => $token,
            'game' => $game,
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'reason_path' => $reasonPath,
            'reason' => $reason,
            'size' => (int)(filesize($path) ?: 0),
            'modified_at' => (int)(filemtime($path) ?: 0),
            'extension' => strtolower(pathinfo($originalName, PATHINFO_EXTENSION)),
            'package_name' => \scanner_logical_package_name($originalName),
            'md5' => $identity['md5'],
            'package_guid' => $identity['package_guid'],
            'header' => $header,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(?int $sourceGameId = null): array
    {
        $items = [];
        if ($sourceGameId === null || $sourceGameId === 0) {
            $bucket = self::bucketGame();
            $this->appendDirectoryItems($items, $bucket, 0);
        }

        if ($sourceGameId !== 0) {
            $games = \catalog_all(
                $this->db,
                'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name'
            );
            foreach ($games as $game) {
                if ($sourceGameId !== null && (int)$game['id'] !== $sourceGameId) {
                    continue;
                }
                $this->appendDirectoryItems($items, $game, (int)$game['id']);
            }
        }

        usort($items, static function (array $left, array $right): int {
            return ($right['modified_at'] <=> $left['modified_at'])
                ?: strcmp((string)$left['original_name'], (string)$right['original_name']);
        });
        return $items;
    }

    public static function uniqueDestination(string $directory, string $name): string
    {
        $name = basename($name);
        $candidate = $directory . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($candidate)) {
            return $candidate;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        for ($attempt = 1; $attempt <= 1000; $attempt++) {
            $candidateName = $base . '.moved-' . date('YmdHis') . '-' . $attempt
                . ($extension === '' ? '' : '.' . $extension);
            $candidate = $directory . DIRECTORY_SEPARATOR . $candidateName;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Could not choose a unique destination name for the unverified file.');
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $game */
    private function appendDirectoryItems(array &$items, array $game, int $gameId): void
    {
        $directory = self::unverifiedDirectory($this->config, $game, false);
        if (!is_dir($directory) || !is_readable($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.'
                || $entry === '..'
                || str_starts_with($entry, '.')
                || str_ends_with(strtolower($entry), '.txt')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || !self::pathInside($path, $directory)) {
                continue;
            }
            try {
                $items[] = $this->resolve(self::token($gameId, $entry));
            } catch (Throwable $error) {
                $label = $gameId === 0 ? 'upload bucket' : 'unverified';
                error_log('[UnrealDB ' . $label . '] ignored unsafe queue entry '
                    . $entry . ': ' . $error->getMessage());
            }
        }
    }
}
