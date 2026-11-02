<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$depth='Nested archive depth limit of 4 reached while content-routing Hidden.rar.';
$types=[JobType::PROCESS_BUCKET_STAGED_PACKAGE,JobType::IMPORT_STAGED_PACKAGE];
$cases=[];
$ok=true;
foreach($types as $type){
 $det=JobFailureRetryPolicy::isDeterministicFailureText($type,$depth);
 $corrupt=JobFailureRetryPolicy::isCorruptContentText($type,$depth);
 $cases[]=['job_type'=>$type,'depth_limit_deterministic'=>$det,'not_corrupt_source'=>$corrupt===false];
 $ok=$ok&&$det&&!$corrupt;
}
$retry=JobFailureRetryPolicy::isDeterministicFailureText(JobType::IMPORT_STAGED_PACKAGE,'Transient filesystem sharing violation.');
$cases[]=['transient_remains_retryable'=>!$retry];$ok=$ok&&!$retry;
echo json_encode(['ok'=>$ok,'read_only'=>true,'checks'=>$cases],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
