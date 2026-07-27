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
        && str_contains($migration, 'trg_ue_files_package_provider_au')
        && str_contains($migration, 'trg_ue_alias_package_provider_ai'),
    'Package-provider migration does not create and maintain the materialized lookup.'
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
