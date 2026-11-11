<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Security\CatalogFeedbackEntryGuard as Guard;
$site=[
    'REQUEST_METHOD'=>'GET','HTTP_HOST'=>'192.168.0.55',
    'SERVER_PORT'=>443,'HTTPS'=>'on',
];
$cases=[
    ['public-menu',$site+['HTTP_REFERER'=>'https://192.168.0.55/catalog/games.php','HTTP_SEC_FETCH_SITE'=>'same-origin'],false],
    ['file-examine-link',$site+['HTTP_REFERER'=>'https://192.168.0.55/catalog/file-examine.php?id=1247732&page=2&page_size=250&tab=exports&target=export-301'],false],
    ['missing-referrer',$site+['HTTP_SEC_FETCH_SITE'=>'none'],true],
    ['copied-return-to',$site+['HTTP_SEC_FETCH_SITE'=>'none','REQUEST_URI'=>'/catalog/feedback.php?return_to=file-examine.php%3Fid%3D1247732'],true],
    ['site-hint-without-referrer',$site+['HTTP_SEC_FETCH_SITE'=>'same-origin'],true],
    ['external-referrer',$site+['HTTP_REFERER'=>'https://example.net/catalog/games.php','HTTP_SEC_FETCH_SITE'=>'cross-site'],true],
    ['host-prefix-spoof',$site+['HTTP_REFERER'=>'https://192.168.0.55.evil.example/catalog/games.php'],true],
    ['referer-userinfo-spoof',$site+['HTTP_REFERER'=>'https://attacker@192.168.0.55/catalog/games.php'],true],
    ['wrong-site-port',$site+['HTTP_REFERER'=>'https://192.168.0.55:444/catalog/games.php'],true],
    ['outside-catalog',$site+['HTTP_REFERER'=>'https://192.168.0.55/something.html'],true],
    ['cross-site-fetch-with-referrer',$site+['HTTP_REFERER'=>'https://192.168.0.55/catalog/games.php','HTTP_SEC_FETCH_SITE'=>'cross-site'],true],
    ['normal-apex-link',[
        'REQUEST_METHOD'=>'GET','HTTP_HOST'=>'unrealdb.com','SERVER_PORT'=>443,'HTTPS'=>'on',
        'HTTP_REFERER'=>'https://unrealdb.com/catalog/index.php?page=search',
        'HTTP_SEC_FETCH_SITE'=>'same-origin'
    ],false],
    ['POST-submission',array_replace($site,['REQUEST_METHOD'=>'POST']),false],
    ['admin-direct',$site,false,'admin'],
    ['diagnostic-redirect',$site,false,'diagnostic'],
];
$results=[];
foreach($cases as $row){
    [$name,$env,$expected]=$row;
    $admin=($row[3]??'')==='admin';
    $diag=($row[3]??'')==='diagnostic';
    $actual=Guard::mustReject($env,$admin,$diag);
    if($actual!==$expected)throw new RuntimeException('Failed '. $name . ': '.json_encode([$expected,$actual]));
    $results[$name]=$actual?'deny':'allow';
}
foreach([
    ['203.0.113.8',false],['192.168.0.51',false],['127.0.0.1',false],
    ['8.8.8.8',true],['1.2.164.208',true],['::1',false],['2606:4700:4700::1111',true]
] as [$ip,$expected]) {
    if(Guard::isPublicClientIp($ip)!==$expected)throw new RuntimeException('Incorrect public IP classification for '.$ip);
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/catalog/feedback.php');
if(!str_contains($source,'CatalogFeedbackEntryGuard::enforce(')
    || !str_contains($source,"'catalog_feedback_diagnostic_attachment'")
    || !str_contains($source,"if (\$_SERVER['REQUEST_METHOD'] === 'POST')")) {
    throw new RuntimeException('Feedback guard integration missing');
}
Guard::enforce(new PDO('sqlite::memory:'), [], false); // CLI MUST never block or write.
echo json_encode(['pass'=>true,'cases'=>$results,'ip_classification'=>7,'writes'=>0],JSON_PRETTY_PRINT).PHP_EOL;

