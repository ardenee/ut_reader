<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageStoreBackendArbitrator as Arb;
$id='AABBCCDDEEFF0011';
$b=[
 ['backend_id'=>'file','priority'=>1,'entries'=>[$id=>['status'=>'Ok']],'redirects'=>[$id=>['target_package_id'=>'1111111111111111']]],
 ['backend_id'=>'higher','priority'=>3,'entries'=>[$id=>['status'=>'Missing']],'redirects'=>[$id=>['target_package_id'=>'3333333333333333']]],
 ['backend_id'=>'middle','priority'=>2,'entries'=>[$id=>['status'=>'Pending']],'redirects'=>[]]
];
$a=Arb::entry($b,$id);$r=Arb::redirect($b,$id);
$redirectFallback=Arb::redirect([
 ['backend_id'=>'higher','priority'=>5,'entries'=>[],'redirects'=>[]],
 ['backend_id'=>'lower','priority'=>1,'entries'=>[],'redirects'=>[$id=>['target_package_id'=>'5555555555555555']]],
],$id);
$b[1]['entries'][$id]=['status'=>'Ok'];
$c=Arb::entry($b,$id);
$b[1]['entries'][$id]=['status'=>'None'];$b[2]['entries'][$id]=['status'=>'Missing'];
$f=Arb::entry($b,$id);
$b[1]['entries'][$id]=['status'=>'NotInstalled'];
$notInstalled=Arb::entry($b,$id);
$bad=false;try{Arb::ordered([['backend_id'=>'bad','priority'=>'high','entries'=>[],'redirects'=>[]]]);}catch(RuntimeException){$bad=true;}
$ties=Arb::ordered([
 ['backend_id'=>'first','priority'=>7,'entries'=>[],'redirects'=>[]],
 ['backend_id'=>'second','priority'=>7,'entries'=>[],'redirects'=>[]],
]);
$mixedCaseBackend=[['backend_id'=>'mixed-case','priority'=>8,
 'entries'=>[strtolower($id)=>['status'=>'Ok','file_id'=>314]],
 'redirects'=>[strtolower($id)=>['source_package_id'=>strtolower($id),'target_package_id'=>'8888888888888888']]]];
$mixedCaseEntry=Arb::entry($mixedCaseBackend,$id);
$mixedCaseRedirect=Arb::redirect($mixedCaseBackend,$id);
$mixedCaseEntryWorks=$mixedCaseEntry['status']==='Ok'
 &&($mixedCaseEntry['entry']['file_id']??null)===314
 &&($mixedCaseRedirect['redirect']['target_package_id']??null)==='8888888888888888';
$duplicateCaseRejected=false;
try {
 Arb::ordered([['backend_id'=>'case-collision','priority'=>1,
  'entries'=>[$id=>['status'=>'Ok'],strtolower($id)=>['status'=>'Missing']],
  'redirects'=>[]]]);
} catch(RuntimeException) { $duplicateCaseRejected=true; }
$malformedPackageIdRejected=false;
try {
 Arb::ordered([['backend_id'=>'invalid-id','priority'=>1,
  'entries'=>['not-a-package-id'=>['status'=>'Ok']],'redirects'=>[]]]);
} catch(RuntimeException) { $malformedPackageIdRejected=true; }
$ok=$ties[0]['backend_id']==='second'&&$a['backend_id']==='middle'&&$a['status']==='Pending'
 &&$r['backend_id']==='higher'&&$c['backend_id']==='higher'
 &&$f['backend_id']==='file'&&$redirectFallback['backend_id']==='lower'&&$bad
 &&$notInstalled['status']==='NotInstalled'&&$notInstalled['backend_id']==='higher'
 &&$mixedCaseEntryWorks&&$duplicateCaseRejected&&$malformedPackageIdRejected;
echo json_encode(['ok'=>$ok,'pending_stops'=>$a,'redirect_priority'=>$r,'redirect_fallback'=>$redirectFallback,'higher_priority_ok'=>$c,'none_missing_fallback'=>$f,'not_installed_stops'=>$notInstalled,'reject_bad_priority'=>$bad,'equal_priority_newest_first'=>($ties[0]['backend_id']==='second'),
 'mixed_case_package_ids_resolve'=>$mixedCaseEntryWorks,
 'case_fold_duplicate_rejected'=>$duplicateCaseRejected,
 'malformed_package_id_rejected'=>$malformedPackageIdRejected ],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
