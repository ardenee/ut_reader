<?php
/**
 * Stateless anti-scraping entry gate: no database, source packages or UEDB5.
 * Read-only behavioural checks of token integrity and redirect allowlist.
 */
declare(strict_types=1);

require_once dirname(__DIR__,2).'/catalog/lib/CatalogDirectEntryGate.php';
function verify(bool $truth,string $label):void {
    if (!$truth) throw new RuntimeException($label);
}
$file=sys_get_temp_dir().'/unrealdb-direct-gate-'.bin2hex(random_bytes(6)).'.secret';
file_put_contents($file,random_bytes(48));
putenv('UNREALDB_GATE_SECRET_PATH='.$file);
try {
    $_SERVER['HTTP_USER_AGENT']='UnrealDB gate acceptance agent';
    verify(CatalogDirectEntryGate::eligibleRoute('file-examine.php'),'File examine should be gated');
    verify(CatalogDirectEntryGate::eligibleRoute('game-files.php'),'Game files should be gated');
    verify(CatalogDirectEntryGate::eligibleRoute('access-matrix.php'),'Access matrix should be gated');
    verify(!CatalogDirectEntryGate::eligibleRoute('admin.php'),'Unknown routes must not be gated');
    foreach(['','https://evil.invalid/catalog/file-examine.php','//evil.invalid/catalog/file-examine.php',
        '/catalog/../../../admin.php','/catalog/file-examine.php#anchor','/catalog/direct-entry-token.php',
        "/catalog/file-examine.php\r\nX-Test: injected",
        '/catalog/file-examine.php?next=http://evil.invalid/path'] as $bad) {
        verify(CatalogDirectEntryGate::validatedReturn($bad) === null,
            'Unsafe return target accepted: '.json_encode($bad));
    }
    $safe='/catalog/file-examine.php?id=1253000&tab=imports&page_size=250';
    verify(CatalogDirectEntryGate::validatedReturn($safe)===$safe,'Safe deep link lost its query');
    verify(CatalogDirectEntryGate::trustedReferer('https://www.google.ie/search?q=unreal'),'Google search result not recognised');
    verify(CatalogDirectEntryGate::trustedReferer('https://www.bing.com/search?q=unreal'),'Bing search result not recognised');
    verify(CatalogDirectEntryGate::trustedReferer('https://192.168.0.55/catalog/games.php'),'Internal navigation not recognised');
    foreach(['https://google.evil.com/search','https://www.google.ie.evil.invalid/path',
        'http://evil.invalid/','ftp://www.google.ie/search',''] as $bad) {
        verify(!CatalogDirectEntryGate::trustedReferer($bad),'Spoofed/unknown referrer trusted: '.$bad);
    }
    verify(CatalogDirectEntryGate::internalClient('192.168.0.60'),'LAN IP not exempt');
    verify(!CatalogDirectEntryGate::internalClient('1.2.3.4'),'Public source IP falsely exempt');
    $now=time();
    $nonce=bin2hex(random_bytes(8));
    $issued=CatalogDirectEntryGate::issueToken('issue',$now,$nonce);
    verify(!CatalogDirectEntryGate::checkToken($issued,'issue',3,300),'Immediate token bypassed 3-second wait');
    $older=CatalogDirectEntryGate::issueToken('issue',$now-4,$nonce);
    verify(CatalogDirectEntryGate::checkToken($older,'issue',3,300),'Elapsed legitimate issue token rejected');
    verify(!CatalogDirectEntryGate::checkToken($older,'pass',0,1800),'Issue token valid as pass token');
    verify(!CatalogDirectEntryGate::checkToken($older.'f','issue',3,300),'Tampered token accepted');
    verify(!CatalogDirectEntryGate::checkToken(
        CatalogDirectEntryGate::issueToken('issue',$now-400,$nonce),'issue',3,300
    ),'Expired token accepted');
    $pass=CatalogDirectEntryGate::issueToken('pass',$now,$nonce);
    verify(CatalogDirectEntryGate::checkToken($pass,'pass',0,1800),'Fresh signed pass rejected');
    $_SERVER['HTTP_USER_AGENT']='Different UA';
    verify(!CatalogDirectEntryGate::checkToken($pass,'pass',0,1800),'Pass not bound to request UA');
    $output=[
        'pass'=>true,'no_db_used'=>true,'routes'=>3,
        'actual_wait_sec'=>3,'issue_ttl_sec'=>300,'pass_ttl_sec'=>1800,
        'original_query_preserved'=>true,'open_redirect_rejected'=>true,
        'unknown_referrers_not_trusted'=>true,'spoofed_search_referrers_rejected'=>true,
        'genuine_search_referrers_allowed'=>true,'tampered_cookie_rejected'=>true,
        'expired_cookie_rejected'=>true,'UA_binding'=>true,'site_data_changed'=>false
    ];
    echo json_encode($output,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    @unlink($file);
}
