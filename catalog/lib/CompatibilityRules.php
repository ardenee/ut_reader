<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for compatibility rules.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * Compatibility rules deliberately require explicit serialized-header facts.
 * Filenames and extensions are never used to select or accept an engine reader.
 *
 * Rule shape:
 * {
 *   "detected_engine":"UE1",
 *   "reader_engine":"UE1",
 *   "package_version_min":40,
 *   "package_version_max":99,
 *   "licensee_version_min":null,
 *   "licensee_version_max":null,
 *   "label":"Legacy UE1 package"
 * }
 */
function compat_rules(array $profile): array
{
    $rules = json_decode((string)($profile['compatibility_rules_json'] ?? '[]'), true);
    return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
}

/**
 * $extension is retained only for call compatibility. It is intentionally ignored.
 */
function compat_rule_match(array $profile, string $extension, ?int $version, ?int $licensee, ?string $detectedEngine): ?array
{
    $detectedEngine = strtoupper(trim((string)$detectedEngine));

    foreach (compat_rules($profile) as $rule) {
        $ruleEngine = strtoupper(trim((string)($rule['detected_engine'] ?? '')));
        if ($ruleEngine === '' || $ruleEngine !== $detectedEngine) {
            continue;
        }

        foreach ([
            ['package_version_min', $version, static fn(int $actual, int $limit): bool => $actual < $limit],
            ['package_version_max', $version, static fn(int $actual, int $limit): bool => $actual > $limit],
            ['licensee_version_min', $licensee, static fn(int $actual, int $limit): bool => $actual < $limit],
            ['licensee_version_max', $licensee, static fn(int $actual, int $limit): bool => $actual > $limit],
        ] as [$key, $actual, $invalid]) {
            if (($rule[$key] ?? null) === null || $rule[$key] === '') {
                continue;
            }
            if ($actual === null || $invalid($actual, (int)$rule[$key])) {
                continue 2;
            }
        }

        $label = trim((string)($rule['label'] ?? 'Header-compatible package'));
        return [
            'label' => $label !== '' ? $label : 'Header-compatible package',
            'reader_engine' => strtoupper(trim((string)($rule['reader_engine'] ?? $ruleEngine))),
            'rule' => $rule,
        ];
    }

    return null;
}
