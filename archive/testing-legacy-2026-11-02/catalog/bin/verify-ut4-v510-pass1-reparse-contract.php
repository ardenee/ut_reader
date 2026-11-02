#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$source = (string)@file_get_contents($root . '/bin/reparse-ut4-v510-pass1.php');

$checks = [
    'legacy_tool_exists' => $source !== '',
    'legacy_tool_is_hard_disabled' => str_contains($source, 'This command is disabled.')
        && str_contains($source, 'VAR_UE4_ARRAY_PROPERTY_INNER_TAGS')
        && str_contains($source, 'repair-ut4-v511-pass1.php')
        && !str_contains($source, 'Uedb5GameSourceMigrationService'),
    'legacy_tool_exits_failure' => str_contains($source, 'exit(2);'),
];
$failures = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
