<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical metadata-query hook expected by CatalogSupportCore.
 * Why: Runtime metadata callers now use explicit compact readers and projections; SQL-shape emulation is retired.
 * Role: Temporary no-op facade until the hook itself is removed from CatalogSupportCore.
 */
declare(strict_types=1);

/**
 * @param list<mixed> $args
 * @return array{handled:bool,value:mixed}
 */
function catalog_metadata_compat_query(PDO $db, string $mode, string $sql, array $args): array
{
    return ['handled' => false, 'value' => null];
}
