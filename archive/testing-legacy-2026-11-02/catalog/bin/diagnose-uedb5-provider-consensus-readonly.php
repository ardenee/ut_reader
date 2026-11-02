#!/usr/bin/env php
<?php
/**
 * Indexed read-only V5 provider-consensus audit for explicit file IDs only.
 * No full-table discovery and no persistent MySQL writes.
 *
 * Example: php diagnose-uedb5-provider-consensus-readonly.php --file-ids=111139,113253
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require_once dirname(__DIR__).'/bootstrap.php';
$arg=$argv[1]??'';
if($argc!==2 || !str_starts_with($arg,'--file-ids=')){
    fwrite(STDERR,"Usage: php diagnose-uedb5-provider-consensus-readonly.php --file-ids=1,2,... (max 250)\n");
    exit(2);
}
$value=substr($arg,11);
if(!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D',$value)){
    fwrite(STDERR,"Expected a comma-separated list of positive integer file IDs.\n");
    exit(2);
}
$ids=array_values(array_unique(array_map('intval',explode(',',$value))));
if(count($ids)>250 || in_array(0,$ids,true)){
    fwrite(STDERR,"File ID list must contain 1 to 250 positive integers.\n");
    exit(2);
}
$app=catalog_bootstrap(false);
$db=$app->db;
$placeholders=implode(',',array_fill(0,count($ids),'?'));
$sql='SELECT p.file_id,p.package_key_kind,HEX(p.package_key) package_key_hex,'
    .'p.provider_file_id,COUNT(DISTINCT e.resolved_file_id) distinct_resolved_providers,'
    .'MIN(e.resolved_file_id) sole_provider '
    .'FROM ue_uedb5_dependency_packages p '
    .'LEFT JOIN ue_uedb5_dependency_edges e ON e.file_id=p.file_id '
    .'AND e.required_package_key_kind=p.package_key_kind '
    .'AND e.required_package_key=p.package_key '
    .'WHERE p.file_id IN ('.$placeholders.') '
    .'GROUP BY p.file_id,p.package_key_kind,p.package_key,p.provider_file_id '
    .'ORDER BY p.file_id,p.package_key_kind,p.package_key';
$db->beginTransaction();
try{
    $q=$db->prepare($sql);
    $q->execute($ids);
    $checked=0;$disputes=0;$mismatches=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        ++$checked;
        $distinct=(int)$row['distinct_resolved_providers'];
        if($distinct>1){++$disputes;}
        $expected=$distinct===1?(int)$row['sole_provider']:null;
        $stored=$row['provider_file_id']===null?null:(int)$row['provider_file_id'];
        if($stored!==$expected){
            $mismatches[]=[
                'file_id'=>(int)$row['file_id'],
                'package_key_kind'=>(int)$row['package_key_kind'],
                'package_key_hex'=>(string)$row['package_key_hex'],
                'distinct_providers'=>$distinct,
                'stored_provider'=>$stored,
                'expected_provider'=>$expected,
            ];
        }
    }
    $db->rollBack();
    $ok=$mismatches===[];
    echo json_encode([
        'ok'=>$ok,'read_only'=>true,'bounded_file_ids'=>$ids,
        'package_summaries_examined'=>$checked,
        'disputed_package_summaries'=>$disputes,
        'inconsistent_summaries'=>$mismatches,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:1);
}catch(Throwable $error){
    if($db->inTransaction()){$db->rollBack();}
    fwrite(STDERR,'Read-only provider-consensus check failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
