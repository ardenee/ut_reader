#!/usr/bin/env php
<?php
/** Read-only production footprint and lookup index audit; no candidate table scans. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
try {
    $db=catalog_bootstrap(false)->db;
    $q=$db->query("SELECT table_name,table_rows,data_length,index_length,data_free FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ue_uedb5_name_candidates','ue_uedb5_object_candidates') ORDER BY table_name");
    $tables=$q->fetchAll(PDO::FETCH_ASSOC);
    $plans=[];
    foreach ([
        'fname_exact'=>"SELECT file_id,first_name_index FROM ue_uedb5_name_candidates WHERE name_key_hash=UNHEX(MD5('NaliCast')) AND name_key_length=8 AND name_key_fingerprint=UNHEX(SHA2('NaliCast',256)) LIMIT 10",
        'object_exact'=>"SELECT file_id,object_kind,object_index FROM ue_uedb5_object_candidates WHERE object_name_hash=UNHEX(MD5('Light135')) AND object_name_length=8 LIMIT 10",
        'object_by_file'=>"SELECT object_kind,object_index FROM ue_uedb5_object_candidates WHERE file_id=1245312 ORDER BY object_kind,object_index LIMIT 10"
    ] as $label=>$sql) {
        $plan=$db->query('EXPLAIN '.$sql)->fetch(PDO::FETCH_ASSOC);
        $plans[$label]=['key'=>$plan['key']??null,'access_type'=>$plan['type']??null,'estimated_rows'=>$plan['rows']??null,'extra'=>$plan['Extra']??null];
    }
    echo json_encode(['ok'=>true,'read_only'=>true,'metadata_estimates'=>true,'tables'=>$tables,'plans'=>$plans,
        'conclusion'=>'Current lookup indexes are in active plans; do not drop/rebuild without benchmark and parity replacement.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()]).PHP_EOL);exit(1);}
