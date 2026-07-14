<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function catalog_ue4_parser_profile_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

/**
 * Builds the UE4 parser profile used by PHP readers.
 *
 * The standard UE4 parser profile is the base. Game-specific profiles are small
 * overlays keyed by game slug or profile name, so adding another UE4 game later
 * should usually mean adding only a config entry for the known differences.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $game
 * @param array<string,mixed> $profile
 * @return array<string,mixed>
 */
function catalog_ue4_parser_profile(array $config, array $game = [], array $profile = []): array
{
    $ue4 = is_array($config['ue4'] ?? null) ? $config['ue4'] : [];

    $base = [
        'profile_key' => 'standard-ue4',
        'label' => 'Standard UE4 package parser',
        'source_reference' => 'UE4/UT4 package summary layout',
        'assumed_unversioned_parser_version' => 511,
        'notes' => 'Base UE4 parser profile. Game profiles should override only known differences.',
    ];

    if (isset($ue4['parser_profile']) && is_array($ue4['parser_profile'])) {
        $base = array_replace($base, $ue4['parser_profile']);
    }

    if (isset($ue4['assumed_unversioned_parser_version'])) {
        $base['assumed_unversioned_parser_version'] = (int)$ue4['assumed_unversioned_parser_version'];
    }

    $profiles = isset($ue4['parser_profiles']) && is_array($ue4['parser_profiles']) ? $ue4['parser_profiles'] : [];
    $legacyAssumptions = isset($ue4['assumed_unversioned_parser_versions']) && is_array($ue4['assumed_unversioned_parser_versions'])
        ? $ue4['assumed_unversioned_parser_versions']
        : [];

    $keys = [];
    foreach ([(string)($game['slug'] ?? ''), (string)($profile['profile_name'] ?? ''), (string)($game['name'] ?? '')] as $candidate) {
        $key = catalog_ue4_parser_profile_key($candidate);
        if ($key !== '') {
            $keys[] = $key;
        }
    }

    foreach (array_values(array_unique($keys)) as $key) {
        if (isset($profiles[$key]) && is_array($profiles[$key])) {
            $base = array_replace($base, $profiles[$key]);
            $base['profile_key'] = (string)($base['profile_key'] ?? $key);
        }
        if (isset($legacyAssumptions[$key])) {
            $base['assumed_unversioned_parser_version'] = (int)$legacyAssumptions[$key];
        }
    }

    $base['assumed_unversioned_parser_version'] = max(0, (int)($base['assumed_unversioned_parser_version'] ?? 0));
    if ($base['assumed_unversioned_parser_version'] <= 0) {
        $base['assumed_unversioned_parser_version'] = 511;
    }

    return $base;
}

/**
 * Reader options are deliberately generic: later UE4 games can add profile
 * fields without changing reader construction call sites.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $game
 * @param array<string,mixed> $profile
 * @return array<string,mixed>
 */
function catalog_ue4_reader_options(array $config, array $game = [], array $profile = []): array
{
    return [
        'parser_profile' => catalog_ue4_parser_profile($config, $game, $profile),
    ];
}

/**
 * Stores the next UE4 reader context for legacy call sites that construct the
 * reader with only a file path.
 *
 * @param array<string,mixed> $options
 */
function catalog_ue4_set_next_reader_options(array $options): void
{
    $GLOBALS['UNREALDB_UE4_NEXT_READER_OPTIONS'] = $options;
}

/** @return array<string,mixed> */
function catalog_ue4_take_next_reader_options(): array
{
    $options = $GLOBALS['UNREALDB_UE4_NEXT_READER_OPTIONS'] ?? [];
    unset($GLOBALS['UNREALDB_UE4_NEXT_READER_OPTIONS']);
    return is_array($options) ? $options : [];
}
