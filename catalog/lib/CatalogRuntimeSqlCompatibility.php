<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical dependency SQL call shapes while routing reads exclusively to current compact metadata.
 * Why: Shared callers may still express queries with the old ue_dependencies table name, but runtime execution must
 *      never fall back to that retired storage representation.
 * Role: Legacy SQL-shape compatibility facade over the authoritative compact dependency read source.
 */
declare(strict_types=1);

/**
 * Rewrite read-only references to ue_dependencies so verified dependencies come
 * exclusively from compact projections. If the current projection is unavailable,
 * fail closed rather than executing the historical SQL against legacy tables.
 */
function catalog_runtime_sql_compat_rewrite(PDO $db, string $sql): string
{
    if (stripos($sql, 'ue_dependencies') === false) {
        return $sql;
    }

    $leading = ltrim($sql);
    if (preg_match('/^(SELECT|WITH|EXPLAIN)\b/i', $leading) !== 1) {
        return $sql;
    }

    $class = '\\UnrealDb\\Catalog\\Application\\Dependency\\CatalogDependencyReadSource';
    if (!class_exists($class)) {
        throw new RuntimeException(
            'Current compact dependency read source is unavailable; runtime legacy dependency reads are disabled.'
        );
    }

    try {
        /** @var class-string $class */
        $source = $class::sql($db);
    } catch (Throwable $error) {
        throw new RuntimeException(
            'Current compact dependency read source could not be built: ' . $error->getMessage(),
            0,
            $error
        );
    }

    $keywords = 'WHERE|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|STRAIGHT_JOIN|GROUP|ORDER|LIMIT|HAVING|UNION|FOR|LOCK|USING';
    $pattern = '/\b(FROM|(?:LEFT\s+|RIGHT\s+|INNER\s+|OUTER\s+|CROSS\s+|STRAIGHT_)?JOIN)\s+'
        . 'ue_dependencies\b'
        . '(?:\s+(?:AS\s+)?((?!(?:' . $keywords . ')\b)[A-Za-z_][A-Za-z0-9_]*))?/i';

    $rewritten = preg_replace_callback(
        $pattern,
        static function (array $match) use ($source): string {
            $operator = (string)$match[1];
            $alias = trim((string)($match[2] ?? ''));
            if ($alias === '') {
                $alias = '_runtime_dependencies';
            }
            return $operator . ' ' . $source . ' ' . $alias;
        },
        $sql
    );

    if (!is_string($rewritten)) {
        throw new RuntimeException('Current compact dependency SQL rewrite failed.');
    }
    return $rewritten;
}
