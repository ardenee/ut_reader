<?php
/**
 * Stable Unreal object-identity hashing shared by compact metadata and SQL projections.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

final class CatalogUnrealIdentityHash
{
    public const VERIFY_IMPORT_ALGORITHM = 'md5-fname-ci-v1';
    public const OBJECT_PATH_ALGORITHM = 'md5-fnamepath-ci-v1';

    public static function verifyImportBinary(
        string $objectName,
        string $className,
        string $classPackage
    ): string {
        return md5(
            self::nameKey($objectName) . "\0"
            . self::nameKey($className) . "\0"
            . self::nameKey($classPackage),
            true
        );
    }

    public static function verifyImportHex(
        string $objectName,
        string $className,
        string $classPackage
    ): string {
        return bin2hex(self::verifyImportBinary($objectName, $className, $classPackage));
    }

    public static function objectPathBinary(string $path): string
    {
        return md5(self::pathKey($path), true);
    }

    public static function objectPathHex(string $path): string
    {
        return bin2hex(self::objectPathBinary($path));
    }

    public static function nameKey(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function pathKey(string $value): string
    {
        $parts = array_values(array_filter(
            array_map('trim', explode('.', trim($value, '.'))),
            static fn(string $part): bool => $part !== ''
        ));
        return self::nameKey(implode('.', $parts));
    }
}
