<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CompatibilityRules.php';

function gp_profile_display_name(array $profile): string
{
    $name = trim((string)($profile['profile_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $game = trim((string)($profile['game_name'] ?? ''));
    return $game !== '' ? ($game) : ('Profile #' . (int)($profile['id'] ?? 0));
}

function gp_all_profiles(PDO $db): array
{
    return catalog_all($db, 'SELECT p.*, g.name legacy_game_name, g.slug legacy_game_slug FROM ue_game_profiles p LEFT JOIN ue_games g ON g.id=p.game_id WHERE p.is_active=1 ORDER BY COALESCE(p.profile_name, g.name), p.engine_key, p.id');
}

function gp_profile_for_game(PDO $db, int $gameId): ?array
{
    return catalog_one($db, 'SELECT p.*, g.name game_name, g.slug game_slug FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1', [$gameId]);
}

function gp_required_profile_for_game(PDO $db, int $gameId): array
{
    $profile = gp_profile_for_game($db, $gameId);
    if (!$profile || empty($profile['id'])) {
        $game = catalog_one($db, 'SELECT name FROM ue_games WHERE id=?', [$gameId]);
        $name = $game ? (string)$game['name'] : ('game #' . $gameId);
        throw new RuntimeException('No active scanner profile is assigned to ' . $name . '. Assign one in Game Admin before scanning files.');
    }
    return $profile;
}

function gp_engine_for_game(PDO $db, int $gameId): string
{
    $profile = gp_required_profile_for_game($db, $gameId);
    return strtoupper((string)$profile['engine_key']);
}

function gp_games_missing_profiles(PDO $db): array
{
    return catalog_all($db, 'SELECT g.* FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE p.id IS NULL ORDER BY g.name');
}

function gp_games_with_profile_counts(PDO $db): array
{
    return catalog_all($db, 'SELECT g.id, g.name, g.slug, CASE WHEN p.id IS NULL THEN 0 ELSE 1 END active_profiles FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name');
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
    if (in_array($ext, ['ut2','un2','usx','ukx','upx','ugx','con'], true)) {
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

function gp_is_unreal2_legacy_package(array $profile, string $ext, ?int $version, ?int $licensee, ?string $detectedEngine): bool
{
    $selectedEngine = strtoupper((string)($profile['engine_key'] ?? ''));
    $gameSlug = strtolower((string)($profile['game_slug'] ?? $profile['legacy_game_slug'] ?? ''));
    $profileName = strtolower((string)($profile['profile_name'] ?? $profile['game_name'] ?? $profile['legacy_game_name'] ?? ''));

    if ($selectedEngine !== 'UE2') {
        return false;
    }
    if ($gameSlug !== 'unreal2' && !str_contains($profileName, 'unreal ii') && !str_contains($profileName, 'unreal 2')) {
        return false;
    }
    if (!in_array(strtolower($ext), ['upx'], true)) {
        return false;
    }
    if ($version !== 83) {
        return false;
    }
    if ($licensee !== null && !in_array($licensee, [635, 763], true)) {
        return false;
    }

    return strtoupper((string)$detectedEngine) === 'UE1';
}

function gp_compatibility_for_file(array $profile, string $ext, ?int $version, ?int $licensee, ?string $detectedEngine): ?array
{
    $rule = compat_rule_match($profile, $ext, $version, $licensee, $detectedEngine);
    if ($rule) {
        return $rule;
    }

    // Preserve the previously accepted Unreal II special case until its profile
    // is explicitly migrated to a JSON rule.
    if (gp_is_unreal2_legacy_package($profile, $ext, $version, $licensee, $detectedEngine)) {
        return [
            'label' => 'Unreal II legacy UPX package',
            'reader_engine' => 'UE1',
            'rule' => ['builtin' => 'unreal2_upx_83'],
        ];
    }

    return null;
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

    if (!$profile || empty($profile['id'])) {
        $notes[] = 'No active game profile is assigned to selected game.';
        return [
            'selected_engine' => $selectedEngine ?: null,
            'detected_engine' => $detectedEngine,
            'reader_engine' => $detectedEngine,
            'package_version' => $version,
            'licensee_version' => $licensee,
            'confidence' => 'unknown',
            'compatibility_status' => 'mismatch',
            'compatibility_label' => null,
            'ok_for_selected_game' => false,
            'notes' => $notes,
            'suggested_games' => [],
        ];
    }

    $allowedExts = gp_extensions($profile);
    $extOk = !$allowedExts || in_array($ext, $allowedExts, true);
    if (!$extOk) {
        $notes[] = 'Extension .' . $ext . ' is not listed for ' . gp_profile_display_name($profile) . '. Allowed: ' . implode(', ', $allowedExts);
    }

    if (!$legacy['ok']) {
        $notes[] = (string)$legacy['reason'];
    } else {
        $notes[] = 'Legacy package header version=' . $version . ' licensee=' . $licensee . '.';
    }

    $compatibility = gp_compatibility_for_file($profile, $ext, $version, $licensee, $detectedEngine);
    $legacyCompatible = $compatibility !== null;
    if ($legacyCompatible) {
        $notes[] = 'Accepted by profile compatibility rule: ' . $compatibility['label'] . '. Parsed with ' . $compatibility['reader_engine'] . ' reader.';
    }

    $min = $profile['package_version_min'] !== null ? (int)$profile['package_version_min'] : null;
    $max = $profile['package_version_max'] !== null ? (int)$profile['package_version_max'] : null;
    $versionOk = true;
    if (!$legacyCompatible && $version !== null && $min !== null && $version < $min) {
        $versionOk = false;
        $notes[] = 'Package version is below the active game profile range.';
    }
    if (!$legacyCompatible && $version !== null && $max !== null && $version > $max) {
        $versionOk = false;
        $notes[] = 'Package version is above the active game profile range.';
    }

    $engineOk = $selectedEngine === '' || strtoupper((string)$detectedEngine) === $selectedEngine || $legacyCompatible;
    if (!$engineOk) {
        $notes[] = 'Detected engine ' . $detectedEngine . ' does not match active game profile engine ' . $selectedEngine . '.';
    }

    if ($engineOk && $extOk && $versionOk && $legacy['ok']) {
        $confidence = $legacyCompatible ? 'medium' : 'high';
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
            if (!$candidateExtOk) {
                continue;
            }
            foreach (catalog_all($db, 'SELECT id, name FROM ue_games WHERE profile_id=? ORDER BY name', [(int)$candidate['id']]) as $game) {
                $suggested[] = ['game_id' => (int)$game['id'], 'game_name' => (string)$game['name'], 'engine_key' => (string)$candidate['engine_key']];
            }
        }
    }

    return [
        'selected_engine' => $selectedEngine,
        'detected_engine' => $detectedEngine,
        'reader_engine' => $legacyCompatible ? (string)$compatibility['reader_engine'] : $selectedEngine,
        'package_version' => $version,
        'licensee_version' => $licensee,
        'confidence' => $confidence,
        'compatibility_status' => $legacyCompatible ? 'legacy_compatible' : 'native',
        'compatibility_label' => $legacyCompatible ? (string)$compatibility['label'] : null,
        'ok_for_selected_game' => in_array($confidence, ['high','medium'], true),
        'notes' => $notes,
        'suggested_games' => $suggested,
    ];
}
