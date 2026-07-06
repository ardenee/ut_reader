<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/GameProfiles.php';

/**
 * Files rejected by the normal scanner are intentionally filesystem-only. They
 * live under storage/games/<game slug>/unverified with an adjacent .txt reason.
 * This manager inventories and reuses those files without creating a ue_files
 * row until a real import succeeds.
 */

function uvf_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function uvf_base64url_decode(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return null;
    }
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function uvf_storage_games_root(array $config): string
{
    $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    if ($storageRoot === false || !is_dir($storageRoot)) {
        throw new RuntimeException('Catalog storage folder is unavailable.');
    }

    $gamesRoot = $storageRoot . DIRECTORY_SEPARATOR . 'games';
    if (!is_dir($gamesRoot) && !@mkdir($gamesRoot, 0775, true) && !is_dir($gamesRoot)) {
        throw new RuntimeException('Could not create the catalog games storage folder.');
    }

    $resolved = realpath($gamesRoot);
    if ($resolved === false) {
        throw new RuntimeException('Catalog games storage folder is unavailable.');
    }
    return $resolved;
}

function uvf_unverified_dir(array $config, array $game, bool $create = false): string
{
    $slug = scanner_slug_text((string)($game['slug'] ?? ''));
    $dir = uvf_storage_games_root($config) . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'unverified';
    if ($create && !is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create unverified storage for ' . ($game['name'] ?? $slug) . '.');
    }
    return $dir;
}

function uvf_path_inside(string $path, string $root): bool
{
    $realPath = realpath($path);
    $realRoot = realpath($root);
    if ($realPath === false || $realRoot === false) {
        return false;
    }
    return str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

function uvf_original_name_from_queue_name(string $queueName): string
{
    $queueName = basename($queueName);
    return preg_replace('/^\d{8}_\d{6}_[A-Fa-f0-9]{8}_/', '', $queueName) ?: $queueName;
}

function uvf_token(int $gameId, string $queueName): string
{
    return uvf_base64url_encode(json_encode([
        'game_id' => $gameId,
        'name' => basename($queueName),
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * @return array{token:string,game:array<string,mixed>,queue_name:string,original_name:string,path:string,reason_path:string,reason:string,size:int,modified_at:int,extension:string,package_name:string,header:array<string,mixed>}
 */
function uvf_resolve(PDO $db, array $config, string $token): array
{
    $decoded = uvf_base64url_decode($token);
    $payload = $decoded === null ? null : json_decode($decoded, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid unverified file reference.');
    }

    $gameId = (int)($payload['game_id'] ?? 0);
    $queueName = basename((string)($payload['name'] ?? ''));
    if ($gameId < 1 || $queueName === '' || $queueName !== (string)($payload['name'] ?? '') || str_ends_with(strtolower($queueName), '.txt')) {
        throw new RuntimeException('Invalid unverified file reference.');
    }

    $game = catalog_one($db, 'SELECT id, name, slug, profile_id FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('The source game no longer exists.');
    }

    $dir = uvf_unverified_dir($config, $game);
    $path = $dir . DIRECTORY_SEPARATOR . $queueName;
    if (!is_file($path) || !uvf_path_inside($path, $dir)) {
        throw new RuntimeException('The selected unverified file is no longer available.');
    }

    $reasonPath = $path . '.txt';
    $reason = '';
    if (is_file($reasonPath) && uvf_path_inside($reasonPath, $dir)) {
        $reason = trim((string)@file_get_contents($reasonPath, false, null, 0, 65535));
    }

    $originalName = uvf_original_name_from_queue_name($queueName);
    $legacy = gp_read_legacy_summary($path);
    $header = [
        'ok' => !empty($legacy['ok']),
        'engine' => (string)($legacy['engine_hint'] ?? gp_detect_from_extension(pathinfo($originalName, PATHINFO_EXTENSION)) ?? 'UNKNOWN'),
        'version' => $legacy['ok'] ? (int)($legacy['version'] ?? 0) : null,
        'licensee' => $legacy['ok'] ? (int)($legacy['licensee'] ?? 0) : null,
        'note' => $legacy['ok'] ? '' : (string)($legacy['reason'] ?? ''),
    ];

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
        'package_name' => scanner_clean_name(pathinfo($originalName, PATHINFO_FILENAME)),
        'header' => $header,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function uvf_list(PDO $db, array $config, ?int $sourceGameId = null): array
{
    $games = catalog_all($db, 'SELECT id, name, slug, profile_id FROM ue_games ORDER BY name');
    $items = [];
    foreach ($games as $game) {
        if ($sourceGameId !== null && (int)$game['id'] !== $sourceGameId) {
            continue;
        }
        $dir = uvf_unverified_dir($config, $game);
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_ends_with(strtolower($entry), '.txt')) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || !uvf_path_inside($path, $dir)) {
                continue;
            }
            try {
                $items[] = uvf_resolve($db, $config, uvf_token((int)$game['id'], $entry));
            } catch (Throwable $error) {
                error_log('[UnrealDB unverified] ignored unsafe queue entry ' . $entry . ': ' . $error->getMessage());
            }
        }
    }

    usort($items, static function (array $left, array $right): int {
        return ($right['modified_at'] <=> $left['modified_at']) ?: strcmp((string)$left['original_name'], (string)$right['original_name']);
    });
    return $items;
}

/**
 * @param list<string> $packageNames
 * @return array<string,list<array{game_id:int,game_name:string,owner_count:int,import_count:int}>> keyed by lowercase package name
 */
function uvf_reference_matches(PDO $db, array $packageNames): array
{
    $keys = [];
    foreach ($packageNames as $packageName) {
        $packageName = trim((string)$packageName);
        if ($packageName !== '') {
            $keys[strtolower($packageName)] = $packageName;
        }
    }
    if ($keys === []) {
        return [];
    }

    $values = array_values($keys);
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $rows = catalog_all(
        $db,
        'SELECT LOWER(d.required_package) package_key, g.id game_id, g.name game_name, COUNT(DISTINCT d.file_id) owner_count, COUNT(*) import_count'
        . ' FROM ue_dependencies d'
        . ' JOIN ue_files f ON f.id=d.file_id'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' WHERE d.required_package IN (' . $placeholders . ')'
        . ' GROUP BY LOWER(d.required_package), g.id, g.name'
        . ' ORDER BY g.name',
        $values
    );

    $out = [];
    foreach ($rows as $row) {
        $key = strtolower((string)$row['package_key']);
        $out[$key] ??= [];
        $out[$key][] = [
            'game_id' => (int)$row['game_id'],
            'game_name' => (string)$row['game_name'],
            'owner_count' => (int)$row['owner_count'],
            'import_count' => (int)$row['import_count'],
        ];
    }
    return $out;
}

function uvf_unique_destination(string $directory, string $name): string
{
    $name = basename($name);
    $candidate = $directory . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($candidate)) {
        return $candidate;
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
    for ($attempt = 1; $attempt <= 1000; $attempt++) {
        $candidateName = $base . '.moved-' . date('YmdHis') . '-' . $attempt . ($extension === '' ? '' : '.' . $extension);
        $candidate = $directory . DIRECTORY_SEPARATOR . $candidateName;
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not choose a unique destination name for the unverified file.');
}

/** @return array{original_name:string,source_game:string,target_game:string} */
function uvf_move(PDO $db, array $config, string $token, int $targetGameId): array
{
    $source = uvf_resolve($db, $config, $token);
    $target = catalog_one($db, 'SELECT id, name, slug, profile_id FROM ue_games WHERE id=?', [$targetGameId]);
    if (!$target) {
        throw new RuntimeException('Target game not found.');
    }
    if ((int)$target['id'] === (int)$source['game']['id']) {
        throw new RuntimeException('The file is already in this game’s unverified queue.');
    }

    $targetDir = uvf_unverified_dir($config, $target, true);
    $destination = uvf_unique_destination($targetDir, (string)$source['queue_name']);
    if (!@rename($source['path'], $destination)) {
        throw new RuntimeException('Could not move the unverified package to the target queue.');
    }

    if (is_file($source['reason_path'])) {
        @rename($source['reason_path'], $destination . '.txt');
    }

    return [
        'original_name' => (string)$source['original_name'],
        'source_game' => (string)$source['game']['name'],
        'target_game' => (string)$target['name'],
    ];
}

/** @return array{original_name:string,source_game:string} */
function uvf_discard(PDO $db, array $config, string $token): array
{
    $source = uvf_resolve($db, $config, $token);
    if (!@unlink($source['path'])) {
        throw new RuntimeException('Could not remove the selected unverified package.');
    }
    if (is_file($source['reason_path'])) {
        @unlink($source['reason_path']);
    }
    return [
        'original_name' => (string)$source['original_name'],
        'source_game' => (string)$source['game']['name'],
    ];
}

/**
 * Re-import a queued file into a chosen game. The queue original is retained
 * until the selected scanner succeeds or finds an existing same-game MD5.
 *
 * @return array{status:string,file_id:int|null,original_name:string,target_game:string,message:string}
 */
function uvf_import(PDO $db, array $config, string $token, int $targetGameId, ?int $userId, bool $allowProfileOverride): array
{
    $source = uvf_resolve($db, $config, $token);
    $target = catalog_one($db, 'SELECT id, name, slug, profile_id FROM ue_games WHERE id=?', [$targetGameId]);
    if (!$target) {
        throw new RuntimeException('Target game not found.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ue_unverified_');
    if ($tmp === false || !@copy($source['path'], $tmp)) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not prepare the queued package for import.');
    }

    try {
        /*
         * Queue import is intentionally loose: the selected game is a catalog
         * choice, while the scanner uses the detected package reader. The
         * explicit override additionally permits extensions absent from the
         * target profile, for legacy/mixed game installations.
         */
        $result = scanner_scan_uploaded_file(
            $db,
            $config,
            (int)$target['id'],
            $tmp,
            (string)$source['original_name'],
            $userId,
            false,
            null,
            $allowProfileOverride
        );

        if (!in_array((string)($result[0] ?? ''), ['verified', 'duplicate'], true)) {
            throw new RuntimeException((string)($result[2] ?? 'Queued package was not imported.'));
        }

        if (!@unlink($source['path'])) {
            throw new RuntimeException('Import completed, but the original unverified queue file could not be removed.');
        }
        if (is_file($source['reason_path'])) {
            @unlink($source['reason_path']);
        }

        return [
            'status' => (string)$result[0],
            'file_id' => isset($result[1]) ? (int)$result[1] : null,
            'original_name' => (string)$source['original_name'],
            'target_game' => (string)$target['name'],
            'message' => (string)($result[2] ?? ''),
        ];
    } catch (Throwable $error) {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        throw $error;
    }
}
