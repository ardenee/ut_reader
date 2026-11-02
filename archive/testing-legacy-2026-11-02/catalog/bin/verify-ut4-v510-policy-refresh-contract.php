#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$refresh = (string)@file_get_contents($root . '/bin/refresh-ut4-v510-source-policy.php');
$diagnose = (string)@file_get_contents($root . '/bin/diagnose-ut4-v510-impact.php');

$checks = [
    'legacy_refresh_is_disabled' => str_contains($refresh, 'This command is disabled')
        && str_contains($refresh, 'v510 source-policy model was invalid')
        && str_contains($refresh, 'exit(2);'),
    'legacy_diagnostic_is_disabled' => str_contains($diagnose, 'This command is disabled')
        && str_contains($diagnose, 'prefix-only UE4 enum count')
        && str_contains($diagnose, 'repair-ut4-v511-pass1.php')
        && str_contains($diagnose, 'exit(2);'),
];
$failures = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
