<?php
declare(strict_types=1);
/** Read-only check of V5 compact candidate tables and retired runtime table references. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\LegacyMetadataRuntimeScanner;
$app=catalog_bootstrap(false);
$installed=$app->db->query("SHOW TABLES LIKE '%candidate%'")->fetchAll(PDO::FETCH_COLUMN);
sort($installed,SORT_STRING);
$expected=['ue_uedb5_compact_name_candidates','ue_uedb5_compact_object_candidates'];
sort($expected,SORT_STRING);
$audit=LegacyMetadataRuntimeScanner::scan(dirname(__DIR__));
$unexpected=array_values(array_diff($installed,$expected));
$missing=array_values(array_diff($expected,$installed));
$report=[
 'ok'=>$unexpected===[]&&$missing===[]&&($audit['references']??-1)===0,
 'read_only'=>true,
 'candidate_tables'=>$installed,
 'unexpected_candidate_tables'=>$unexpected,
 'missing_compact_candidate_tables'=>$missing,
 'retired_runtime_table_reference_files'=>$audit['files'],
 'retired_runtime_table_references'=>$audit['references'],
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($report['ok']?0:2);
