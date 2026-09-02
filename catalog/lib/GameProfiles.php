<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for game profiles.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
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
    return array_values(array_filter(array_map(static fn($v) => catalog_clean_unreal_extension((string)$v), $json), static fn($v) => $v !== ''));
}

function gp_engine_from_version(?int $version): ?string
{
    if ($version === null || $version <= 0) {
        return null;
    }

    // Header-only engine-family mapping from Epic package-summary versions.
    // Values outside known engine ranges are deliberately left UNKNOWN rather
    // than guessed from filename, extension, or selected profile.
    if ($version >= 334 && $version <= 867) {
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

function gp_int32_from_uint32(int $value): int
{
    return $value >= 0x80000000 ? $value - 0x100000000 : $value;
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

/**
 * Match an explicitly configured compatibility rule using serialized header
 * data only. The $ext parameter remains for call compatibility but is ignored.
 */
function gp_compatibility_for_file(array $profile, string $ext, ?int $version, ?int $licensee, ?string $detectedEngine): ?array
{
    return compat_rule_match($profile, $ext, $version, $licensee, $detectedEngine);
}

function gp_read_legacy_summary(string $path): array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return ['ok' => false, 'reason' => 'Could not open file', 'error_code' => 'unreal.header_read_failed'];
    }
    $bytes = fread($fh, 16);
    fclose($fh);
    if ($bytes === false) {
        return ['ok' => false, 'reason' => 'Could not read package header', 'error_code' => 'unreal.header_read_failed'];
    }

    $bytesRead = strlen($bytes);
    $headerHex = strtoupper(bin2hex($bytes));
    $headerText = '';
    for ($i = 0; $i < $bytesRead; $i++) {
        $value = ord($bytes[$i]);
        $headerText .= ($value >= 32 && $value <= 126) ? chr($value) : '.';
    }
    $headerArguments = [
        'bytes_read' => $bytesRead,
        'header_hex' => $headerHex,
        'header_text' => $headerText,
        'expected_magic_hex' => \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::expectedMagicHex(),
    ];

    if ($bytesRead < 4) {
        return [
            'ok' => false,
            'reason' => 'Magic not found',
            'error_code' => 'unreal.magic_not_found',
            'error_arguments' => $headerArguments,
        ];
    }

    $actualMagicBytes = strtoupper(bin2hex(substr($bytes, 0, 4)));
    $headerArguments['actual_magic_hex'] = $actualMagicBytes;
    $magic = unpack('V', substr($bytes, 0, 4))[1];
    if (!\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedLittleEndianValue((int)$magic)) {
        return [
            'ok' => false,
            'reason' => 'Magic not found',
            'error_code' => 'unreal.magic_not_found',
            'error_arguments' => $headerArguments,
        ];
    }

    if ($bytesRead < 8) {
        return [
            'ok' => false,
            'reason' => 'Package header too short',
            'error_code' => 'unreal.header_too_short',
            'error_arguments' => $headerArguments + ['minimum_header_bytes' => 8],
        ];
    }

    $version = unpack('v', substr($bytes, 4, 2))[1];
    $licensee = unpack('v', substr($bytes, 6, 2))[1];
    $version32 = (int)(unpack('V', substr($bytes, 4, 4))[1] ?? 0);
    $signedVersion32 = gp_int32_from_uint32($version32);
    $legacyEngine = gp_engine_from_version($version);

    // UE4/UE5 package summaries start with the same package magic, but the next
    // value is a signed 32-bit package file version. Legacy packages use two
    // unsigned 16-bit values in the same four bytes. A legacy licensee version
    // with its high bit set also makes the combined 32-bit value negative, so a
    // known legacy package version must take precedence over the signed marker.
    if ($legacyEngine === null && $signedVersion32 < 0) {
        return [
            'ok' => true,
            'magic' => sprintf('0x%08X', $magic),
            'package_tag_variant' => \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::variant((int)$magic),
            'format' => 'ue4_package',
            'version' => $signedVersion32,
            'licensee' => null,
            'engine_hint' => 'UE4',
        ];
    }

    return [
        'ok' => true,
        'magic' => sprintf('0x%08X', $magic),
        'package_tag_variant' => \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::variant((int)$magic),
        'format' => 'legacy_package',
        'version' => $version,
        'licensee' => $licensee,
        'engine_hint' => $legacyEngine,
    ];
}

function gp_classify_file(PDO $db, int $selectedGameId, string $path, string $originalName): array
{
    $profile = gp_profile_for_game($db, $selectedGameId);
    $cleanOriginalName = catalog_clean_unreal_filename($originalName);
    $ext = catalog_clean_unreal_extension((string)pathinfo($cleanOriginalName, PATHINFO_EXTENSION));
    $summary = gp_read_legacy_summary($path);
    $version = $summary['ok'] ? (int)$summary['version'] : null;
    $licensee = $summary['ok'] && array_key_exists('licensee', $summary) && $summary['licensee'] !== null
        ? (int)$summary['licensee']
        : null;
    $detectedEngine = strtoupper(trim((string)($summary['engine_hint'] ?? '')));
    if (!in_array($detectedEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        $detectedEngine = 'UNKNOWN';
    }
    $selectedEngine = strtoupper((string)($profile['engine_key'] ?? ''));
    $notes = [];
    $signedPackageVersion = ($summary['format'] ?? '') === 'ue4_package';
    $headerOk = !empty($summary['ok']);
    $headerErrorCode = trim((string)($summary['error_code'] ?? ''));
    $headerErrorArguments = is_array($summary['error_arguments'] ?? null)
        ? $summary['error_arguments']
        : [];

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
            'header_ok' => $headerOk,
            'header_error_code' => $headerErrorCode,
            'header_error_arguments' => $headerErrorArguments,
            'notes' => $notes,
            'suggested_games' => [],
        ];
    }

    if (!$summary['ok']) {
        $notes[] = (string)$summary['reason'];
    } elseif ($signedPackageVersion) {
        $notes[] = 'Unreal package header signed version=' . $version . '.';
    } else {
        $notes[] = 'Unreal package header version=' . $version . ' licensee=' . $licensee . '.';
    }

    if ($detectedEngine === 'UNKNOWN') {
        $notes[] = 'The serialized package header does not identify a supported engine reader; filename and extension fallback is disabled.';
    }

    $compatibility = gp_compatibility_for_file($profile, $ext, $version, $licensee, $detectedEngine);
    $compatible = $compatibility !== null;
    if ($compatible) {
        $notes[] = 'Accepted by explicit header compatibility rule: ' . $compatibility['label']
            . '. Parsed with ' . $compatibility['reader_engine'] . ' reader.';
    }

    $min = $profile['package_version_min'] !== null ? (int)$profile['package_version_min'] : null;
    $max = $profile['package_version_max'] !== null ? (int)$profile['package_version_max'] : null;
    $versionOk = true;
    if (!$signedPackageVersion && !$compatible && $version !== null && $min !== null && $version < $min) {
        $versionOk = false;
        $notes[] = 'Package version is below the active game profile range.';
    }
    if (!$signedPackageVersion && !$compatible && $version !== null && $max !== null && $version > $max) {
        $versionOk = false;
        $notes[] = 'Package version is above the active game profile range.';
    }

    // Modern package summaries are identified from the signed serialized version.
    // The current UE5 reader is the same serialized package reader implementation
    // as UE4, so a UE5 target may accept that proven modern package family; reader
    // selection itself remains the header-selected UE4 implementation.
    $modernFamilyMatch = $detectedEngine === 'UE4' && in_array($selectedEngine, ['UE4', 'UE5'], true);
    $engineOk = $detectedEngine !== 'UNKNOWN'
        && ($selectedEngine === '' || $detectedEngine === $selectedEngine || $modernFamilyMatch || $compatible);
    if (!$engineOk) {
        $notes[] = 'Header-detected engine ' . $detectedEngine
            . ' does not match active game profile engine ' . ($selectedEngine !== '' ? $selectedEngine : 'UNKNOWN') . '.';
    }

    if ($engineOk && $versionOk && !empty($summary['ok'])) {
        $confidence = $compatible ? 'medium' : 'high';
    } elseif ($detectedEngine === 'UNKNOWN') {
        $confidence = 'unknown';
    } elseif (!$engineOk) {
        $confidence = 'mismatch';
    } else {
        $confidence = 'low';
    }

    $suggested = [];
    if (!$engineOk && $detectedEngine !== 'UNKNOWN') {
        foreach (gp_all_profiles($db) as $candidate) {
            $candidateEngine = strtoupper((string)$candidate['engine_key']);
            $candidateModernMatch = $detectedEngine === 'UE4' && in_array($candidateEngine, ['UE4', 'UE5'], true);
            if ($candidateEngine !== $detectedEngine && !$candidateModernMatch) {
                continue;
            }
            foreach (catalog_all($db, 'SELECT id, name FROM ue_games WHERE profile_id=? ORDER BY name', [(int)$candidate['id']]) as $game) {
                $suggested[] = [
                    'game_id' => (int)$game['id'],
                    'game_name' => (string)$game['name'],
                    'engine_key' => (string)$candidate['engine_key'],
                ];
            }
        }
    }

    $readerEngine = $compatible
        ? strtoupper((string)$compatibility['reader_engine'])
        : $detectedEngine;
    if (!in_array($readerEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        $readerEngine = 'UNKNOWN';
    }

    return [
        'selected_engine' => $selectedEngine,
        'detected_engine' => $detectedEngine,
        'reader_engine' => $readerEngine,
        'package_version' => $version,
        'licensee_version' => $licensee,
        'confidence' => $confidence,
        'compatibility_status' => $compatible ? 'legacy_compatible' : 'native',
        'compatibility_label' => $compatible ? (string)$compatibility['label'] : null,
        'ok_for_selected_game' => $engineOk && $versionOk && !empty($summary['ok']),
        'header_ok' => $headerOk,
        'header_error_code' => $headerErrorCode,
        'header_error_arguments' => $headerErrorArguments,
        'notes' => $notes,
        'suggested_games' => $suggested,
    ];
}
