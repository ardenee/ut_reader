#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/bin/refresh-ut4-v510-source-policy.php';
$source = (string)@file_get_contents($path);

$checks = [];
$failures = [];
$check = static function(string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) $failures[] = $name;
};

$check('tool_exists', $source !== '');
$check('read_only_by_default',
    str_contains($source, '$apply = array_key_exists(\'apply\', $options)')
    && str_contains($source, 'if (!$apply)'));
$check('refresh_is_bounded_to_legacy_ut4_non_gate_rows',
    str_contains($source, "'ue4-4.27.2-release-classic-package'")
    && str_contains($source, 'f.package_version BETWEEN 214 AND 510')
    && str_contains($source, 'f.package_version NOT IN ('));
$check('unversioned_rows_are_blocked_from_metadata_only_refresh',
    str_contains($source, "unversioned_requires_pass1_review"));
$check('uses_staged_v5_only',
    str_contains($source, 'Uedb5MetadataReader')
    && str_contains($source, 'Uedb5MetadataSnapshotWriter')
    && str_contains($source, 'refreshExisting')
    && str_contains($source, 'markStageSucceeded'));
$check('does_not_open_original_ue4_packages',
    !str_contains($source, 'UnrealPackageReader4')
    && !str_contains($source, 'Uedb5GameSourceMigrationService')
    && !str_contains($source, 'ue_file_metadata'));
$check('writes_only_new_source_policy',
    str_contains($source, '$snapshot[\'source_policy\'] = Uedb5Ut4SnapshotBuilder::SOURCE_POLICY'));
$check('supports_exact_file_and_resume_cursor',
    str_contains($source, "'file-id::'")
    && str_contains($source, "'after-id::'")
    && str_contains($source, "'limit::'"));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
