<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines generated-package format labels, inference and enablement policy.
 * Why: Format selection is configuration/domain policy and should not be coupled to archive-writing functions.
 * Role: Downloads infrastructure policy shared by settings, previews and workers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

final class CatalogPackageExportFormatPolicy
{
    public const DEPENDENCY_ZIP = 'dependency_zip';
    public const UMOD = 'umod';
    public const UT2MOD = 'ut2mod';
    public const UT4MOD = 'ut4mod';
    public const UT3_ZIP = 'ut3_zip';
    public const UT4_PAK = 'ut4_pak';
    public const DISABLED = 'disabled';

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::DEPENDENCY_ZIP => 'Dependency ZIP',
            self::UMOD => 'UT99 .umod',
            self::UT2MOD => 'UT2003 .ut2mod',
            self::UT4MOD => 'UT2004 .ut4mod',
            self::UT3_ZIP => 'UT3 structured ZIP',
            self::UT4_PAK => 'UT4 unencrypted .pak',
            self::DISABLED => 'Disabled',
        ];
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::labels());
    }

    /** @param array<string,mixed> $game */
    public static function inferred(array $game): string
    {
        $engine = strtoupper(trim((string)($game['engine_key'] ?? '')));
        $identity = strtolower(trim(
            (string)($game['slug'] ?? '') . ' '
            . (string)($game['name'] ?? '') . ' '
            . (string)($game['profile_name'] ?? '')
        ));

        if ($engine === 'UE1' && str_contains($identity, 'tournament')) {
            return self::UMOD;
        }
        if ($engine === 'UE2' && str_contains($identity, '2003')) {
            return self::UT2MOD;
        }
        if ($engine === 'UE2' && str_contains($identity, '2004')) {
            return self::UT4MOD;
        }
        if ($engine === 'UE3' && str_contains($identity, 'tournament')) {
            return self::UT3_ZIP;
        }
        if ($engine === 'UE4' && str_contains($identity, 'tournament')) {
            return self::UT4_PAK;
        }
        return self::DEPENDENCY_ZIP;
    }

    /** @param array<string,mixed> $game @param array<string,mixed> $settings */
    public static function defaultFormat(array $game, array $settings): string
    {
        $override = strtolower(trim((string)(
            $settings['game_formats'][(string)($game['id'] ?? 0)] ?? 'auto'
        )));
        if ($override !== ''
            && $override !== 'auto'
            && in_array($override, self::supported(), true)) {
            return $override;
        }
        return self::inferred($game);
    }

    /** @param array<string,mixed> $settings */
    public static function enabled(string $format, array $settings): bool
    {
        if (empty($settings['enabled']) || $format === self::DISABLED) {
            return false;
        }
        return match ($format) {
            self::DEPENDENCY_ZIP => !empty($settings['dependency_zip_enabled']),
            self::UMOD, self::UT2MOD, self::UT4MOD => !empty($settings['umod_enabled']),
            self::UT3_ZIP => !empty($settings['ut3_zip_enabled']),
            self::UT4_PAK => !empty($settings['ut4_pak_enabled']),
            default => false,
        };
    }

    /** @param array<string,mixed> $game @param array<string,mixed> $settings @return list<string> */
    public static function available(array $game, array $settings): array
    {
        if (empty($settings['enabled'])) {
            return [];
        }

        $formats = [];
        if (!empty($settings['dependency_zip_enabled'])) {
            $formats[] = self::DEPENDENCY_ZIP;
        }
        $native = self::defaultFormat($game, $settings);
        if ($native !== self::DEPENDENCY_ZIP && self::enabled($native, $settings)) {
            array_unshift($formats, $native);
        }
        return array_values(array_unique($formats));
    }
}
