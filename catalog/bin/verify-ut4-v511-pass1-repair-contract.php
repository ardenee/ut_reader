#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$source = (string)@file_get_contents($root . '/bin/repair-ut4-v511-pass1.php');

$checks = [];
$failures = [];
$check = static function(string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$check('tool_exists', $source !== '');
$check('read_only_by_default',
    str_contains($source, '$apply = array_key_exists(\'apply\', $options)')
    && str_contains($source, '$service->runFile($gameId, $id, $apply)'));
$check('selects_only_faulty_v510_policy',
    str_contains($source, 'Uedb5Ut4SnapshotBuilder::LEGACY_SOURCE_POLICY_V510')
    && str_contains($source, 'v.source_policy=?')
    && !str_contains($source, 'package_version IN')
    && !str_contains($source, 'OR f.package_version=511'));
$check('requires_canonical_v511_output',
    str_contains($source, 'Uedb5Ut4SnapshotBuilder::SOURCE_POLICY')
    && str_contains($source, 'Corrected Pass-1 did not produce canonical UT4 clean-master v511 source policy.'));
$check('supports_exact_file_resume_limit_and_storage_override',
    str_contains($source, "'file-id::'")
    && str_contains($source, "'after-id::'")
    && str_contains($source, "'limit::'")
    && str_contains($source, "'storage-root::'")
    && str_contains($source, '\'next_after_id\' => $nextAfterId'));
$check('reports_remaining_bad_policy_count',
    str_contains($source, '\'remaining_bad_policy_count\' => $remainingBadPolicyCount'));
$check('does_not_mutate_live_catalogue_identity',
    !str_contains($source, 'UPDATE ue_files')
    && !str_contains($source, 'SET package_version'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
