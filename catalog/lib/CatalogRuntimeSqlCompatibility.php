<?php
declare(strict_types=1);

/**
 * Rewrite read-only references to ue_dependencies so verified format-2 files
 * come from compact projections while unverified/unconverted rows continue to
 * come from the installed legacy staging table.
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
        return $sql;
    }

    try {
        /** @var class-string $class */
        $source = $class::sql($db);
    } catch (Throwable $error) {
        error_log('[UnrealDB dependency compatibility] SQL rewrite skipped: ' . $error->getMessage());
        return $sql;
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

    return is_string($rewritten) ? $rewritten : $sql;
}
