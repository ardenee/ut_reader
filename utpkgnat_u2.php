<?php
declare(strict_types=1);

require_once __DIR__ . '/ut_native_registry.php';

function utpkgnat_u2_load_native_functions_from_pas(): array
{
    $pasFile = __DIR__ . '/utpkgnat_u2.pas';
    $source = file_get_contents($pasFile);
    if ($source === false) {
        throw new RuntimeException('Unable to read native function source: ' . $pasFile);
    }

    $pattern = "/\{\s*(\d+)\s*\}\s*\(\s*Index\s*:\s*(\d+)\s*;\s*Format\s*:\s*(nff[A-Za-z0-9_]+)\s*;\s*OperatorPrecedence\s*:\s*(\d+)(?:\s*\{[^}]*\})?\s*;\s*Name\s*:\s*'((?:''|[^'])*)'\s*\)\s*,?\s*(?:\/\/\s*([^\r\n]*))?/m";
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    $rows = [];
    foreach ($matches as $m) {
        $rows[] = [
            'ordinal' => (int)$m[1],
            'index' => (int)$m[2],
            'format' => constant($m[3]),
            'formatName' => $m[3],
            'operatorPrecedence' => (int)$m[4],
            'name' => str_replace("''", "'", $m[5]),
            'source' => isset($m[6]) ? trim($m[6]) : '',
        ];
    }

    return $rows;
}

function utpkgnat_u2_native_functions(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = utpkgnat_u2_load_native_functions_from_pas();
    }
    return $rows;
}

function utpkgnat_u2_native_function_objects(): array
{
    return array_map(fn(array $row) => new TNativeFunction($row['index'], $row['format'], $row['operatorPrecedence'], $row['name']), utpkgnat_u2_native_functions());
}

function utpkgnat_u2_native_functions_by_index(): array
{
    $out = [];
    foreach (utpkgnat_u2_native_functions() as $row) {
        $out[(int)$row['index']] = $row;
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

function utpkgnat_u2_find_native_function(int $index): ?array
{
    $rows = utpkgnat_u2_native_functions_by_index();
    return $rows[$index] ?? null;
}

RegisterNativeFunctionArray(UTPGH_Unreal2, utpkgnat_u2_native_function_objects());

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(utpkgnat_u2_native_functions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
