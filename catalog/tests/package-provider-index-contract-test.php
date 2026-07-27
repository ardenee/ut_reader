<?php
declare(strict_types=1);

function package_provider_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607270002_package_provider_index.php');
package_provider_expect(is_string($migration), 'Package-provider migration could not be read.');
package_provider_expect(
    str_contains($migration, 'CREATE TABLE ue_package_providers')
        && str_contains($migration, 'idx_ue_package_providers_lookup')
        && !str_contains($migration, 'CREATE TRIGGER'),
    'Package-provider migration is not compatible with restricted binary-logging installations.'
);

$providerRepository = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoPackageProviderRepository.php');
package_provider_expect(is_string($providerRepository), 'Package-provider repository could not be read.');
package_provider_expect(
    str_contains($providerRepository, 'function syncFile(')
        && str_contains($providerRepository, 'function syncAlias(')
        && str_contains($providerRepository, 'ON DUPLICATE KEY UPDATE'),
    'Application writes do not maintain the package-provider lookup.'
);

$aliasRepository = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoPackageAliasRepository.php');
$affectedRefresh = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
package_provider_expect(
    is_string($aliasRepository)
        && is_string($affectedRefresh)
        && str_contains($aliasRepository, 'syncAlias($aliasId)')
        && str_contains($affectedRefresh, 'syncFile($newFileId)'),
    'File or alias import paths do not update package providers.'
);

$resolver = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogDependencyResolver.php');
package_provider_expect(is_string($resolver), 'Dependency resolver could not be read.');
package_provider_expect(
    str_contains($resolver, 'FROM ue_package_providers p')
        && str_contains($resolver, 'p.game_id=?')
        && str_contains($resolver, 'p.package_name IN ('),
    'Dependency resolution is not using the game-scoped package-provider index.'
);
package_provider_expect(
    str_contains($resolver, 'FROM ue_files f')
        && str_contains($resolver, 'FROM ue_file_package_aliases a')
        && str_contains($resolver, 'missingLookupValues(')
        && str_contains($resolver, 'catch (PDOException)'),
    'Dependency resolution does not retain an exact authoritative-table fallback.'
);
package_provider_expect(
    !str_contains($resolver, 'valuesTableSql(')
        && !str_contains($resolver, 'objectValuesTableSql(')
        && !str_contains($resolver, ' UNION ALL '),
    'Dependency resolution still builds large derived UNION value tables.'
);
package_provider_expect(
    str_contains($resolver, 'e.full_path IN (')
        && str_contains($resolver, '(a.package_name=? AND e.local_path=?)'),
    'Exact primary and alias object resolution are not using bounded indexed predicates.'
);
package_provider_expect(
    str_contains($resolver, '(p.source_kind="primary") DESC')
        && str_contains($resolver, '$packageMatches[self::normalizeLookup($rootPackage)]')
        && str_contains($resolver, '$exportMatches[self::normalizeLookup($fullPath)]'),
    'Provider precedence or case-insensitive lookup-key normalization is missing.'
);

fwrite(STDOUT, "Package provider index contract tests passed.\n");
