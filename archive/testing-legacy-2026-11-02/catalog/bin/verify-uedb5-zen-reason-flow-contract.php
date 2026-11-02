<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
$root=dirname(__DIR__).'/src/Infrastructure/Metadata/';
$read=static fn(string $file):string=>(string)file_get_contents($root.$file);
$checks=[
 'parity_scan_preserves_reason'=>str_contains($read('Uedb5ParityV5ReadService.php'),"'reason_code'=>(string)("),
 'runtime_reader_preserves_reason'=>str_contains($read('Uedb5RuntimeMetadataReader.php'),"'reason_code' => (string)("),
 'compact_facade_preserves_reason'=>str_contains($read('CatalogCompactDependencyReadService.php'),"'reason_code' => (string)("),
 'rebuilder_preserves_zen_rows'=>str_contains($read('Uedb5DependencyRebuilder.php'),"$"."resolved = Uedb5Ue5ZenDependencyResolver::resolve("),
 'zen_filter_reason_is_explicit'=>str_contains($read('Uedb5Ue5ZenDependencyResolver.php'),"'export_filter_runtime_state_required'"),
 'zen_filtered_reason_is_explicit'=>str_contains($read('Uedb5Ue5ZenDependencyResolver.php'),"'export_filtered_for_runtime_build'"),
];
$failures=array_keys(array_filter($checks,static fn(bool $ok)=>!$ok));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT),PHP_EOL;
exit($failures===[]?0:1);
