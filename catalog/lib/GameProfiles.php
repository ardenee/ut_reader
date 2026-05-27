<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function gp_all_profiles(PDO $db): array
{
    return catalog_all($db, 'SELECT p.*, g.name game_name, g.slug game_slug FROM ue_game_profiles p JOIN ue_games g ON g.id=p.game_id WHERE p.is_active=1 ORDER BY g.name');
}

function gp_profile_for_game(PDO $db, int $gameId): ?array
{
    return catalog_one($db, 'SELECT p.*, g.name game_name, g.slug game_slug FROM ue_game_profiles p JOIN ue_games g ON g.id=p.game_id WHERE p.game_id=? AND p.is_active=1', [$gameId]);
}

function gp_extensions(array $profile): array
{
    $json = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    if (!is_array($json)) {
        return [];
    }
    return array_values(array_filter(array_map(static fn($v) => strtolower(trim((string)$v, '. ')), $json), static fn($v) => $v !== ''));
}

function gp_detect_from_extension(string $ext): ?string
{
    $ext = strtolower(trim($ext, '. '));
    if (in_array($ext, ['uasset','umap'], true)) {
        return 'UE4';
    }
    if (in_array($ext, ['ut3','upk'], true)) {
        return 'UE3';
    }
    if (in_array($ext, ['ut2','un2','usx','ukx'], true)) {
        return 'UE2';
    }
    if (in_array($ext, ['unr','umx'], true)) {
        return 'UE1';
    }
    return null;
}

function gp_engine_from_version(?int $version): ?string
{
    if ($version === null || $version <= 0) {
        return null;
    }
    if ($version >= 500) {
        return 'UE3';
    }
    if ($version >= 100 && $version <= 199) {
        return 'UE2';
    }
    if ($version >= 40 && $version <= 99) {
        return 'UE1';
    }
    return null;
}

function gp_engine_rank(string $engine): int
{
    return match (strtoupper($engine)) {
        'UE1' => 1,
        'UE2' => 2,
        'UE3' => 3,
        'UE4' => 4,
        'UE5' => 5,
        default => 0,
    };
}

function gp_read_legacy_summary(string $path): array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return ['ok' => false, 'reason' => 'Could not open file'];
    }
    $bytes = fread($fh, 16);
    fclose($fh);
    if ($bytes === false || strlen($bytes) < 8) {
        return ['ok' => false, 'reason' => 'File too small'];
    }

    $magic = unpack('V', substr($bytes, 0, 4))[1];
    if ($magic !== 0x9E2A83C1) {
        return ['ok' => false, 'reason' => 'Legacy package magic not found'];
    }

    $version = unpack('v', substr($bytes, 4, 2))[1];
    $licensee = unpack('v', substr($bytes, 6, 2))[1];
    return ['ok' => true, 'magic' => sprintf('0x%08X', $magic), 'version' => $version, 'licensee' => $licensee, 'engine_hint' => gp_engine_from_version($version)];
}

function gp_classify_file(PDO $db, int $selectedGameId, string $path, string $originalName): array
{
    $profile = gp_profile_for_game($db, $selectedGameId);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $legacy = gp_read_legacy_summary($path);
    $version = $legacy['ok'] ? (int)$legacy['version'] : null;
    $licensee = $legacy['ok'] ? (int)$legacy['licensee'] : null;
    $engineByVersion = $legacy['engine_hint'] ?? null;
    $engineByExt = gp_detect_from_extension($ext);
    $selectedEngine = strtoupper((string)($profile['engine_key'] ?? ''));
    $detectedEngine = $engineByVersion ?: $engineByExt ?: ($selectedEngine ?: 'UNKNOWN');
    $notes = [];

    if (!$profile) {
        $notes[] = 'No active game profile exists for selected game.';
        return [
            'selected_engine' => $selectedEngine ?: null,
            'detected_engine' => $detectedEngine,
            'package_version' => $version,
            'licensee_version' => $licensee,
            'confidence' => 'unknown',
            'ok_for_selected_game' => false,
            'notes' => $notes,
            'suggested_games' => [],
        ];
    }

    $allowedExts = gp_extensions($profile);
    $extOk = !$allowedExts || in_array($ext, $allowedExts, true);
    if (!$extOk) {
        $notes[] = 'Extension .' . $ext . ' is not listed for ' . $profile['game_name'] . '. Allowed: ' . implode(', ', $allowedExts);
    }

    if (!$legacy['ok']) {
        $notes[] = (string)$legacy['reason'];
    } else {
        $notes[] = 'Legacy package header version=' . $version . ' licensee=' . $licensee . '.';
    }

    $min = $profile['package_version_min'] !== null ? (int)$profile['package_version_min'] : null;
    $max = $profile['package_version_max'] !== null ? (int)$profile['package_version_max'] : null;
    $versionOk = true;
    if ($version !== null && $min !== null && $version < $min) {
        $versionOk = false;
        $notes[] = 'Package version is below selected game profile range.';
    }
    if ($version !== null && $max !== null && $version > $max) {
        $versionOk = false;
        $notes[] = 'Package version is above selected game profile range.';
    }

    $engineOk = $selectedEngine === '' || strtoupper((string)$detectedEngine) === $selectedEngine;
    if (!$engineOk) {
        $notes[] = 'Detected engine ' . $detectedEngine . ' does not match selected game engine ' . $selectedEngine . '.';
    }

    if ($engineOk && $extOk && $versionOk && $legacy['ok']) {
        $confidence = 'high';
    } elseif ($engineOk && $extOk) {
        $confidence = 'medium';
    } elseif (!$engineOk) {
        $confidence = 'mismatch';
    } else {
        $confidence = 'low';
    }

    $suggested = [];
    if (!$engineOk && $detectedEngine !== 'UNKNOWN') {
        foreach (gp_all_profiles($db) as $candidate) {
            if (strtoupper((string)$candidate['engine_key']) !== strtoupper((string)$detectedEngine)) {
                continue;
            }
            $candidateExts = gp_extensions($candidate);
            $candidateExtOk = !$candidateExts || in_array($ext, $candidateExts, true);
            if ($candidateExtOk) {
                $suggested[] = ['game_id' => (int)$candidate['game_id'], 'game_name' => (string)$candidate['game_name'], 'engine_key' => (string)$candidate['engine_key']];
            }
        }
    }

    return [
        'selected_engine' => $selectedEngine,
        'detected_engine' => $detectedEngine,
        'package_version' => $version,
        'licensee_version' => $licensee,
        'confidence' => $confidence,
        'ok_for_selected_game' => in_array($confidence, ['high','medium'], true),
        'notes' => $notes,
        'suggested_games' => $suggested,
    ];
}
