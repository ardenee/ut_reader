<?php
/**
 * Source-only UT4 /Script/ module declaration inventory.
 * A matching Build.cs is NOT a proof of native runtime registration or of
 * imported class/object/symbol existence in a particular packaged game.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use RuntimeException;

final class CatalogUt4NativeScriptSourceIndex
{
    /** @var list<string>|null */
    private static ?array $sourcePackages = null;

    public static function manifestPath(): string
    {
        return dirname(__DIR__, 3) . '/data/ut4-native-script-source-index.json';
    }

    public static function signature(): string
    {
        $hash = @hash_file('sha256', self::manifestPath());
        if ($hash === false) {
            throw new RuntimeException('UT4 native script source inventory missing.');
        }
        return $hash;
    }

    /** @return list<string> */
    public static function sourcePackages(): array
    {
        if (self::$sourcePackages !== null) {
            return self::$sourcePackages;
        }
        $text = @file_get_contents(self::manifestPath());
        if ($text === false) {
            throw new RuntimeException('UT4 native script source inventory missing.');
        }
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? '') !== 'ut4-native-script-module-source-declarations-v1'
            || !is_array($data['modules'] ?? null)) {
            throw new RuntimeException('Invalid UT4 native script source inventory.');
        }
        $packages = [];
        foreach ($data['modules'] as $item) {
            if (!is_array($item) || !is_string($item['module'] ?? null)
                || !is_bool($item['source_match'] ?? null)) {
                throw new RuntimeException('Malformed UT4 module entry.');
            }
            $module = $item['module'];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $module) !== 1
                || ($item['package'] ?? '') !== '/Script/' . $module) {
                throw new RuntimeException('Invalid UT4 module name.');
            }
            if ($item['source_match'] === true) {
                $packages[] = '/Script/' . $module;
            }
        }
        if (count($packages) !== 35 || count($data['modules']) !== 38) {
            // Fail closed instead of silently using an incomplete registry.
            throw new RuntimeException('Unexpected UT4 source module inventory.');
        }
        return self::$sourcePackages = $packages;
    }

    public static function sourceMatchSql(string $alias): string
    {
        if (!in_array($alias, ['s'], true)) {
            throw new RuntimeException('Invalid module query alias.');
        }
        $names = array_map(
            static fn(string $package): string => "'" . $package . "'",
            self::sourcePackages()
        );
        return $alias . '.required_package_name IN (' . implode(',', $names) . ')';
    }
}
