<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$source=(string)file_get_contents($root.'/bin/repair-uedb5-staged.php');
$checks=[
 'bounded_batch'=>str_contains($source,'min(500, $limit)') || str_contains($source,'min(500, (int)'),
 'staged_only'=>str_contains($source,'s.status="staged"'),
 'v5_registration_only'=>str_contains($source,'JOIN ue_uedb5_files'),
 'requires_explicit_apply'=>str_contains($source, "'apply'") && str_contains($source, 'isset($options'),
 'original_container_backup'=>str_contains($source,'Uedb5MetadataContainer::path') && str_contains($source,'hash_file'),
 'authoritative_pass1'=>str_contains($source,'$source->runFile($gameId,$fileId,true)'),
 'v5_dependency_pass2'=>str_contains($source,'$dependencies->runFile($gameId,$fileId,true,true)'),
 'step8_source_validation'=>str_contains($source,'$validation->validateFile($slug,$fileId)'),
 'no_legacy_sql'=>preg_match('/ue_(?:file_metadata|export_lookup|name_lookup|dependency_links)/',$source)===0,
 'resume_cursor'=>str_contains($source,'s.file_id>?'),
];
$failed=array_keys(array_filter($checks,static fn(bool $pass):bool=>!$pass));
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
