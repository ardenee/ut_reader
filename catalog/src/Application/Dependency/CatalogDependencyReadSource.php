<?php
/**
 * Compatibility facade for the authoritative dependency read source.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class CatalogDependencyReadSource
{
    public static function compactAvailable(PDO $db): bool
    {
        return PdoDependencyReadSource::compactAvailable($db);
    }

    public static function sql(PDO $db): string
    {
        return PdoDependencyReadSource::sql($db);
    }
}
