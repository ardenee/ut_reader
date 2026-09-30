#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameSourceMigrationService.php');
$cli=(string)file_get_contents($root.'/bin/migrate-uedb5-game.php');
$publisher=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5BaseProjectionPublisher.php');
$checks=[]; $failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok){$failures[]=$name;}};
$check('migration_hashes_original_md5',str_contains($service,'md5_file($path)'));
$check('migration_hashes_original_sha1',str_contains($service,'sha1_file($path)'));
$check('migration_checks_original_size',str_contains($service,'filesize($path)'));
$check('migration_never_reads_uedb4_container',!str_contains($service,'BlockedCompressedMetadataReader')&&!str_contains($service,'.uedb4'));
$check('migration_uses_canonical_reader_resolver',str_contains($service,'CatalogReaderResolver::resolve'));
$check('migration_writes_v5_snapshot',str_contains($service,'writer->write($snapshot)'));
$check('migration_registers_v5_only_after_source_parse',str_contains($service,'registration->register'));
$check('projection_failure_removes_staged_registration',str_contains($service,'registration->remove'));
$check('resume_selection_uses_missing_v5_registration',str_contains($service,'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id')&&str_contains($service,'v.file_id IS NULL'));
$check('continuous_run_has_internal_cursor',str_contains($service,'f.id>?')&&str_contains($service,'$cursor = (int)$file'));
$check('ut99_is_source_version_bounded',str_contains($service,'Uedb5Ut99SnapshotBuilder::MIN_VERSION')&&str_contains($service,'Uedb5Ut99SnapshotBuilder::MAX_VERSION'));
$check('base_pass_clears_dependency_projection_for_second_pass',str_contains($publisher,'ue_uedb5_dependency_edges')&&str_contains($publisher,'ue_uedb5_dependency_packages'));
$check('cli_defaults_to_explicit_apply',str_contains($cli,'isset($options')&&str_contains($cli,"'apply'"));
$check('cli_supports_preflight',str_contains($cli,"'preflight'"));
$check('cli_supports_continuous',str_contains($cli,"'continuous'"));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
