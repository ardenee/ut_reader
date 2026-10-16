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
$ok=$ties[0]['backend_id']==='second'&&$a['backend_id']==='middle'&&$a['status']==='Pending'
 &&$r['backend_id']==='higher'&&$c['backend_id']==='higher'
 &&$f['backend_id']==='file'&&$bad
 &&$notInstalled['status']==='NotInstalled'&&$notInstalled['backend_id']==='higher';
echo json_encode(['ok'=>$ok,'pending_stops'=>$a,'redirect_priority'=>$r,'higher_priority_ok'=>$c,'none_missing_fallback'=>$f,'not_installed_stops'=>$notInstalled,'reject_bad_priority'=>$bad,'equal_priority_newest_first'=>($ties[0]['backend_id']==='second') ],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
