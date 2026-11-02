#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$script = (string)@file_get_contents($root . '/bin/reset-ut4-v511-provider-graph-dependencies.php');

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;
    if(!$ok){$failures[]=$name;}
};

$check('tool_exists',$script!=='');
$check('read_only_default',str_contains($script,'$apply = array_key_exists(\'apply\', $options)'));
$check('requires_canonical_ut4_policy',str_contains($script,'Uedb5Ut4SnapshotBuilder::SOURCE_POLICY'));
$check('requires_exact_fname_primary_key_kind',
    str_contains($script,'PACKAGE_KEY_CLASSIC_FNAME')
    && str_contains($script,'bad_primary_key_kind_count'));
$check('requires_one_primary_provider_per_file',
    str_contains($script,'duplicate_primary_provider_count')
    && str_contains($script,'primary_provider_count'));
$check('resets_validation_state',
    str_contains($script,'status="staged"')
    && str_contains($script,'validator_policy=NULL')
    && str_contains($script,'validated_payload_sha256=NULL')
    && str_contains($script,'validated_at=NULL'));
$check('resets_dependency_state',
    str_contains($script,'dependency_policy=NULL')
    && str_contains($script,'dependency_payload_sha256=NULL')
    && str_contains($script,'dependency_completed_at=NULL'));
$check('touches_only_staging_status_table',
    str_contains($script,"assertWriteTable('ue_uedb5_migration_status')")
    && !str_contains($script,'UPDATE ue_files ')
    && !str_contains($script,'UPDATE ue_dependency_links '));
$check('transactional',str_contains($script,'$db->beginTransaction()')
    && str_contains($script,'$db->commit()')
    && str_contains($script,'$db->rollBack()'));
$check('verifies_full_reset_after_apply',
    str_contains($script,"'dependency_invalidated_after'")
    && str_contains($script,"'validated_after'"));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
