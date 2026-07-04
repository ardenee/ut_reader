<?php
declare(strict_types=1);

/**
 * Compatibility rules deliberately require an explicit profile rule. They do not
 * make a blanket claim that an engine can load every package produced by an
 * earlier engine.
 *
 * Rule shape:
 * {
 *   "detected_engine":"UE1",
 *   "reader_engine":"UE1",
 *   "extensions":["utx"],
 *   "package_version_min":40,
 *   "package_version_max":99,
 *   "licensee_version_min":null,
 *   "licensee_version_max":null,
 *   "label":"Legacy UE1 texture package"
 * }
 */
function compat_rules(array $profile): array
{
    $rules = json_decode((string)($profile['compatibility_rules_json'] ?? '[]'), true);
    return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
}

function compat_rule_extensions(array $rule): array
{
    $exts = $rule['extensions'] ?? [];
    if (is_string($exts)) {
        $exts = preg_split('/[\s,]+/', $exts) ?: [];
    }
    if (!is_array($exts)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map(static fn($ext) => strtolower(trim((string)$ext, '. ')), $exts), static fn($ext) => $ext !== '')));
}

function compat_rule_match(array $profile, string $extension, ?int $version, ?int $licensee, ?string $detectedEngine): ?array
{
    $extension = strtolower(trim($extension, '. '));
    $detectedEngine = strtoupper(trim((string)$detectedEngine));

    foreach (compat_rules($profile) as $rule) {
        $ruleEngine = strtoupper(trim((string)($rule['detected_engine'] ?? '')));
        if ($ruleEngine === '' || $ruleEngine !== $detectedEngine) {
            continue;
        }

        $extensions = compat_rule_extensions($rule);
        if (!$extensions || !in_array($extension, $extensions, true)) {
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

        $label = trim((string)($rule['label'] ?? 'Legacy-compatible package'));
        return [
            'label' => $label !== '' ? $label : 'Legacy-compatible package',
            'reader_engine' => strtoupper(trim((string)($rule['reader_engine'] ?? $ruleEngine))),
            'rule' => $rule,
        ];
    }

    return null;
}
