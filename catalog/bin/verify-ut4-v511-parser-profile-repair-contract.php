#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$script = (string)file_get_contents($root . '/bin/repair-ut4-v511-parser-profile.php');

$checks = [];
$failures = [];
$check = static function(string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$check('read_only_default', str_contains($script, '$apply = array_key_exists(\'apply\', $options)'));
$check('requires_exact_ids_file', str_contains($script, "'ids-file:'") && str_contains($script, 'is_file($idsFile)'));
$check('bounded_and_resumable', str_contains($script, "'after-id::'") && str_contains($script, "'limit::'"));
$check('canonical_ut4_profile_only', str_contains($script, "'ut4-alpha'") && str_contains($script, '!== 511'));
$check('rejects_unversioned_rows', str_contains($script, 'unversioned_row_not_metadata_only'));
$check('requires_current_dependency_checkpoint', str_contains($script, 'dependency_checkpoint_not_current_payload'));
$check('preserves_dependency_results', str_contains($script, 'dependency_results_changed_during_profile_refresh'));
$check('refreshes_registration_only_after_write', str_contains($script, 'refreshExisting($gameId, $id)'));
$check('republishes_provider_keys', str_contains($script, '$providerPublisher->publish($id)'));
$check('rebinds_dependency_checkpoint_to_new_payload', str_contains($script, 'markDependencySucceeded'));
$check('does_not_open_original_source_bytes',
    !str_contains($script, "DIRECTORY_SEPARATOR . 'verified'")
    && !str_contains($script, 'md5_file(')
    && !str_contains($script, 'sha1_file(')
);
$check('transactional_sql_publication', str_contains($script, '$db->beginTransaction()') && str_contains($script, '$db->commit()'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
