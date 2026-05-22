<?php
require_once __DIR__ . '/utpkgnat_pas_loader.php';

function utpkgnat_ut_native_functions(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = utpkgnat_load_pas_native_functions(__DIR__ . '/utpkgnat_ut.pas');
    }
    return $rows;
}

function utpkgnat_ut_native_functions_by_index(): array
{
    return utpkgnat_index_native_functions(utpkgnat_ut_native_functions());
}

function utpkgnat_ut_find_native_function(int $index): ?array
{
    return utpkgnat_find_native_function(utpkgnat_ut_native_functions(), $index);
}

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    utpkgnat_emit_json(utpkgnat_ut_native_functions());
}
