<?php
/**
 * Runtime loader for utpkgnat_*.pas native-function tables.
 *
 * The Pascal files contain records like:
 *   (Index:00112;Format:nffOperator;OperatorPrecedence:040;Name:'$')
 *
 * This helper converts those records into PHP arrays without manually
 * duplicating the full Pascal tables.
 */

function utpkgnat_load_pas_native_functions(string $pasFile): array
{
    if (!is_file($pasFile)) {
        throw new RuntimeException('Pascal native-function file not found: ' . $pasFile);
    }

    $source = file_get_contents($pasFile);
    if ($source === false) {
        throw new RuntimeException('Unable to read Pascal native-function file: ' . $pasFile);
    }

    $pattern = "/\{\s*(\d+)\s*\}\s*\(\s*Index\s*:\s*(\d+)\s*;\s*Format\s*:\s*(nff[A-Za-z0-9_]+)\s*;\s*OperatorPrecedence\s*:\s*(\d+)(?:\s*\{[^}]*\})?\s*;\s*Name\s*:\s*'((?:''|[^'])*)'\s*\)\s*,?\s*(?:\/\/\s*([^\r\n]*))?/m";

    if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $rows = [];
    foreach ($matches as $m) {
        $name = str_replace("''", "'", $m[5]);
        $rows[] = [
            'ordinal' => (int)$m[1],
            'index' => (int)$m[2],
            'format' => $m[3],
            'operatorPrecedence' => (int)$m[4],
            'name' => $name,
            'source' => isset($m[6]) ? trim($m[6]) : '',
        ];
    }

    return $rows;
}

function utpkgnat_index_native_functions(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[(int)$row['index']] = $row;
    }
    ksort($indexed, SORT_NUMERIC);
    return $indexed;
}

function utpkgnat_find_native_function(array $rows, int $index): ?array
{
    foreach ($rows as $row) {
        if ((int)$row['index'] === $index) {
            return $row;
        }
    }
    return null;
}

function utpkgnat_emit_json(array $rows): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
