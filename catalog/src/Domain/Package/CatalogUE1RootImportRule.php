<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Package;

/**
 * Source-defined UE1 VerifyImport root precondition for linker imports.
 * Unreal v1.200 and UT99 v400 require a PackageIndex=0 root to be
 * Core.Package; prior to package version 50 _ObjectPackage is serialized
 * separately, so this rule must not be used for pre-50 imports.
 *
 * Shared by V4 SQL and V5 snapshot dependency projections. It checks
 * only serialized ancestry, never asserts a runtime package was loaded.
 */
final class CatalogUE1RootImportRule
{
    /** @param array<int,array<string,mixed>> $imports */
    public static function invalidRoot(array $imports, int $importIndex): bool
    {
        $seen = [];
        while (isset($imports[$importIndex]) && !isset($seen[$importIndex])) {
            $seen[$importIndex] = true;
            $row = $imports[$importIndex];
            if (!empty($row['object_package_present']) || self::sourceNone($row)) {
                return false;
            }
            $outer = (int)($row['outer_index'] ?? $row['package_index'] ?? 0);
            if ($outer === 0) {
                return !self::same((string)($row['class_package'] ?? ''), 'Core')
                    || !self::same((string)($row['class_name'] ?? ''), 'Package');
            }
            if ($outer > 0) {
                return false;
            }
            $importIndex = -$outer - 1;
        }
        // Missing or cyclic ancestry has no provable root; leave it to
        // the source import-reference verifier rather than guessing one.
        return false;
    }

    private static function sourceNone(array $row): bool
    {
        foreach (['class_package', 'class_name', 'object_name'] as $field) {
            if (!empty($row[$field . '_is_none'])) {
                return true;
            }
            if (!array_key_exists($field . '_is_none', $row)
                && self::same((string)($row[$field] ?? ''), 'None')) {
                return true;
            }
        }
        return false;
    }

    private static function same(string $a, string $b): bool
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        }
        return strcasecmp($a, $b) === 0;
    }
}
