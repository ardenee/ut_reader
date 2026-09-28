<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function catalog_ue5_parser_profile_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

/** @param array<string,mixed> $config @param array<string,mixed> $game @param array<string,mixed> $profile */
function catalog_ue5_parser_profile(array $config, array $game = [], array $profile = []): array
{
    $ue5 = is_array($config['ue5'] ?? null) ? $config['ue5'] : [];
    $base = [
        'profile_key' => 'standard-ue5',
        'label' => 'Standard UE5 5.8.3 classic package parser',
        'source_reference' => 'UE5 5.8.3 release 396c9f059903aed5fec78ecd3d437a40c6415368',
        'assumed_unversioned_ue4_version' => 522,
        'assumed_unversioned_ue5_version' => 1018,
    ];
    if (isset($ue5['parser_profile']) && is_array($ue5['parser_profile'])) {
        $base = array_replace($base, $ue5['parser_profile']);
    }
    $profiles = isset($ue5['parser_profiles']) && is_array($ue5['parser_profiles']) ? $ue5['parser_profiles'] : [];
    $keys = [];
    foreach ([(string)($game['slug'] ?? ''), (string)($profile['profile_name'] ?? ''), (string)($game['name'] ?? '')] as $candidate) {
        $key = catalog_ue5_parser_profile_key($candidate);
        if ($key !== '') $keys[] = $key;
    }
    foreach (array_values(array_unique($keys)) as $key) {
        if (isset($profiles[$key]) && is_array($profiles[$key])) {
            $base = array_replace($base, $profiles[$key]);
            $base['profile_key'] = (string)($base['profile_key'] ?? $key);
        }
    }
    $base['assumed_unversioned_ue4_version'] = max(214, (int)($base['assumed_unversioned_ue4_version'] ?? 522));
    $base['assumed_unversioned_ue5_version'] = max(1000, (int)($base['assumed_unversioned_ue5_version'] ?? 1018));
    return $base;
}

/** @return array<string,mixed> */
function catalog_ue5_reader_options(array $config, array $game = [], array $profile = []): array
{
    return ['parser_profile' => catalog_ue5_parser_profile($config, $game, $profile)];
}
function catalog_ue5_set_next_reader_options(array $options): void
{
    $GLOBALS['UNREALDB_UE5_NEXT_READER_OPTIONS'] = $options;
}

/** @return array<string,mixed> */
function catalog_ue5_take_next_reader_options(): array
{
    $options = $GLOBALS['UNREALDB_UE5_NEXT_READER_OPTIONS'] ?? [];
    unset($GLOBALS['UNREALDB_UE5_NEXT_READER_OPTIONS']);
    return is_array($options) ? $options : [];
}
