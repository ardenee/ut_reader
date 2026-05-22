<?php
declare(strict_types=1);

require_once __DIR__ . '/ut_packages.php';

/** @var array<int,array<int,TNativeFunction>> */
$GLOBALS['UT_NATIVE_FUNCTION_REGISTRY'] ??= [];

function RegisterNativeFunctionArray(int $gameHint, array $functions): void
{
    $out = [];
    foreach ($functions as $fn) {
        if ($fn instanceof TNativeFunction) {
            $out[$fn->Index] = $fn;
            continue;
        }
        if (is_array($fn)) {
            $obj = TNativeFunction::fromArray($fn);
            $out[$obj->Index] = $obj;
        }
    }
    ksort($out, SORT_NUMERIC);
    $GLOBALS['UT_NATIVE_FUNCTION_REGISTRY'][$gameHint] = $out;
}

function GetNativeFunctionArray(int $gameHint): array
{
    return $GLOBALS['UT_NATIVE_FUNCTION_REGISTRY'][$gameHint] ?? [];
}

function FindNativeFunction(int $gameHint, int $index): ?TNativeFunction
{
    return $GLOBALS['UT_NATIVE_FUNCTION_REGISTRY'][$gameHint][$index] ?? null;
}

function NativeFunctionRows(int $gameHint): array
{
    return array_map(fn(TNativeFunction $fn) => $fn->toArray(), GetNativeFunctionArray($gameHint));
}
