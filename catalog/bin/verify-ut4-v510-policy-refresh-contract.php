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
    && str_contains($source, 'f.package_version BETWEEN 214 AND 511')
    && str_contains($source, 'f.package_version NOT IN ('));
$check('unversioned_rows_are_deferred_to_pass1_not_refreshed',
    str_contains($source, "unversioned_requires_pass1_reparse")
    && str_contains($source, '$deferred[$id]'));
$check('uses_staged_v5_only',
    str_contains($source, 'Uedb5MetadataReader')
    && str_contains($source, 'Uedb5MetadataSnapshotWriter')
    && str_contains($source, 'refreshExisting')
    && str_contains($source, 'markStageSucceeded'));
$check('does_not_open_original_ue4_packages',
    !str_contains($source, 'UnrealPackageReader4')
    && !str_contains($source, 'Uedb5GameSourceMigrationService')
    && !str_contains($source, 'ue_file_metadata'));
$check('writes_only_source_backed_policy_for_exact_version',
    str_contains($source, 'Uedb5Ut4SnapshotBuilder::SOURCE_POLICY_V511')
    && str_contains($source, '$snapshot[\'source_policy\'] = $expectedPolicy'));
$check('supports_exact_file_and_resume_cursor',
    str_contains($source, "'file-id::'")
    && str_contains($source, "'after-id::'")
    && str_contains($source, "'limit::'")
    && str_contains($source, "'storage-root::'")
    && str_contains($source, '$result[\'resume_after_id\'] = $lastScannedId'));
$check('partial_file_write_retry_is_idempotent',
    str_contains($source, '$snapshotPolicy === $legacyPolicy')
    && str_contains($source, '$snapshotPolicy !== $expectedPolicy'));
$check('registration_and_dependency_invalidation_are_transactional',
    str_contains($source, '$db->beginTransaction()')
    && str_contains($source, '$registration->refreshExisting($gameId, $id)')
    && str_contains($source, '$statuses->markStageSucceeded($id, $gameId)')
    && str_contains($source, '$db->commit()')
    && str_contains($source, '$db->rollBack()'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
