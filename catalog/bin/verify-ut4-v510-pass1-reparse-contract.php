#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/bin/reparse-ut4-v510-pass1.php';
$source = (string)@file_get_contents($path);

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
$check('selection_is_bounded_to_legacy_ut4_known_risk_versions',
    str_contains($source, "'ue4-4.27.2-release-classic-package'")
    && str_contains($source, 'f.package_version IN (')
    && str_contains($source, 'OR f.package_version=511')
    && str_contains($source, 'f.licensee_version=0'));
$check('reader_gate_set_is_exact',
    str_contains($source, '[325,335,364,383,443,458,484,503,506,507,509,510]'));
$check('v511_requires_staged_summary_classification',
    str_contains($source, 'elseif ($version === 511)')
    && str_contains($source, "['unversioned']")
    && str_contains($source, '$assumed !== 510 || $effective !== 510'));
$check('explicit_v511_is_skipped_from_pass1',
    str_contains($source, '$skippedExplicitV511[] = $id')
    && str_contains($source, 'empty($summary[\'unversioned\'])'));
$check('pass1_must_produce_clean_master_policy',
    str_contains($source, 'Uedb5Ut4SnapshotBuilder::SOURCE_POLICY')
    && str_contains($source, 'Pass-1 result did not produce the UT4 clean-master source policy'));
$check('supports_bounded_resume_and_storage_override',
    str_contains($source, "'after-id::'")
    && str_contains($source, "'limit::'")
    && str_contains($source, "'file-id::'")
    && str_contains($source, "'storage-root::'")
    && str_contains($source, '\'next_after_id\' => $nextAfterId'));
$check('does_not_mutate_live_catalogue_version',
    !str_contains($source, 'UPDATE ue_files')
    && !str_contains($source, 'SET package_version'));

echo json_encode(
    ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failures===[] ? 0 : 1);
