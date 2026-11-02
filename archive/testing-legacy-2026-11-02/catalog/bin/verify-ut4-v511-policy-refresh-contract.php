#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$source = (string)@file_get_contents($root . '/bin/refresh-ut4-v511-source-policy.php');

$checks = [];
$failures = [];
$check = static function(string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$check('tool_exists', $source !== '');
$check('read_only_by_default',
    str_contains($source, '$apply = array_key_exists(\'apply\', $options)'));
$check('selects_only_legacy_refresh_aliases',
    str_contains($source, "'ue4-4.27.2-release-classic-package'")
    && str_contains($source, 'LEGACY_SOURCE_POLICY_V511_STRUCTURAL')
    && str_contains($source, 'v.source_policy IN (')
    && !str_contains($source, 'LEGACY_SOURCE_POLICY_V510'));
$check('explicit_source_boundary_is_214_through_511_licensee_zero',
    str_contains($source, 'f.package_version BETWEEN 214 AND 511')
    && str_contains($source, 'f.licensee_version=0'));
$check('unversioned_rows_are_never_metadata_only_refreshed',
    str_contains($source, 'unversioned_requires_original_byte_reparse')
    && str_contains($source, '$deferred[$id]'));
$check('staged_summary_identity_must_match_catalogue_identity',
    str_contains($source, 'staged_summary_identity_mismatch'));
$check('writes_only_canonical_clean_master_policy',
    str_contains($source, 'Uedb5Ut4SnapshotBuilder::SOURCE_POLICY')
    && str_contains($source, '$snapshot[\'source_policy\'] = $targetPolicy'));
$check('registration_and_dependency_invalidation_are_transactional',
    str_contains($source, '$db->beginTransaction()')
    && str_contains($source, '$registration->refreshExisting($gameId, $id)')
    && str_contains($source, '$statuses->markStageSucceeded($id, $gameId)')
    && str_contains($source, '$db->commit()')
    && str_contains($source, '$db->rollBack()'));
$check('supports_exact_file_resume_limit_and_storage_override',
    str_contains($source, "'file-id::'")
    && str_contains($source, "'after-id::'")
    && str_contains($source, "'limit::'")
    && str_contains($source, "'storage-root::'")
    && str_contains($source, '\'next_after_id\' => $lastScannedId'));
$check('does_not_open_original_package_bytes',
    !str_contains($source, 'UnrealPackageReader4')
    && !str_contains($source, 'runFile('));

echo json_encode(
    ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failures===[] ? 0 : 1);
