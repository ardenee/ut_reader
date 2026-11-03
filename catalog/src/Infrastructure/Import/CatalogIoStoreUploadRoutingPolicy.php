<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

/**
 * IoStore .utoc/.ucas are jointly decoded containers, never loose .uasset files.
 * Profiled/general upload must not queue their bytes as individual packages.
 */
final class CatalogIoStoreUploadRoutingPolicy
{
    public const REDIRECT_MESSAGE = 'IoStore .utoc/.ucas files must be imported as a matching container set with Import IoStore (iostore-import.php). Do not upload these as individual packages through Upload Files.';

    public static function isIoStoreComponent(string $filename): bool
    {
        return preg_match('/\.(?:utoc|ucas)$/iD', basename(str_replace('\\', '/', $filename))) === 1;
    }

    public static function assertProfiledPackage(string $filename): void
    {
        if (self::isIoStoreComponent($filename)) {
            throw new \InvalidArgumentException(self::REDIRECT_MESSAGE);
        }
    }
}
