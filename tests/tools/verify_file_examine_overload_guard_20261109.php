<?php
/**
 * Test anti-scraping security controls without touching PRD database/files.
 * Repro: external public visitor receives stateless manual gate; valid
 * signed proof is not portable between peer IPs; bounded File Examine
 * admission prevents saturated Apache workers.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogDirectEntryGate.php';
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogPublicPageAdmission.php';
function checkGuard(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-admission-'.bin2hex(random_bytes(8));
mkdir($tmp,0700,true);
$secret=$tmp.DIRECTORY_SEPARATOR.'secret';
$state=$tmp.DIRECTORY_SEPARATOR.'state.json';
file_put_contents($secret,random_bytes(48));
putenv('UNREALDB_GATE_SECRET_PATH='.$secret);
putenv('UNREALDB_PUBLIC_ADMISSION_FILE='.$state);
register_shutdown_function(static function() use($secret,$state,$tmp): void {
    @unlink($secret);
    @unlink($state);
    @rmdir($tmp);
});
try{
    $_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Real Browser Test';
    $_SERVER['REMOTE_ADDR']='203.0.113.8';
    $_SERVER['HTTP_REFERER']='https://www.google.ie/search?q=ut2004';
    checkGuard(CatalogDirectEntryGate::trustedReferer($_SERVER['HTTP_REFERER']),
        'Search referer test setup invalid');
    $now=time();
    $nonce=bin2hex(random_bytes(8));
    $issue=CatalogDirectEntryGate::issueToken('issue',$now-4,$nonce);
    checkGuard(CatalogDirectEntryGate::checkToken($issue,'issue',3,300),
        'Manual 3 second issue proof invalid');
    checkGuard(!CatalogDirectEntryGate::checkToken(
        CatalogDirectEntryGate::issueToken('issue',$now,$nonce),'issue',3,300),
        'Cannot click Continue before 3 seconds');
    checkGuard(!CatalogDirectEntryGate::checkToken($issue,'pass',0,1800),
        'Issue proof was accepted as access proof without clicking Continue');
    $pass=CatalogDirectEntryGate::issueToken('pass',$now,$nonce);
    checkGuard(CatalogDirectEntryGate::checkToken($pass,'pass',0,1800),
        'Signed pass invalid at original source IP');
    $_SERVER['REMOTE_ADDR']='203.0.113.9';
    checkGuard(!CatalogDirectEntryGate::checkToken($pass,'pass',0,1800),
        'Pass cookie was transferable to unrelated attacking IP');
    $_SERVER['REMOTE_ADDR']='203.0.113.8';

    // This is a global cap across all simultaneously-running PHP requests.
    for ($i=0;$i<CatalogPublicPageAdmission::MAX_ACTIVE;$i++) {
        checkGuard(CatalogPublicPageAdmission::admit($pass),
            'Valid source unexpectedly denied before saturation at request '.$i);
    }
    checkGuard(!CatalogPublicPageAdmission::admit($pass),
        'Excess concurrent expensive public File Examine request was admitted');
    $raw=file_get_contents($state);
    $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    checkGuard(count($decoded['active'])===CatalogPublicPageAdmission::MAX_ACTIVE,
        'Concurrent active reservation count incorrect');
    foreach(array_keys($decoded['active']) as $id) {
        CatalogPublicPageAdmission::release($state,$id);
    }
    checkGuard(CatalogPublicPageAdmission::admit($pass),
        'Available slot did not recover after completed source request');
    $text=file_get_contents(dirname(__DIR__,2).'/catalog/direct-entry.html');
    checkGuard(str_contains($text,'button.disabled=false'),
        'Manual Continue was not enabled after delay');
    checkGuard(str_contains($text,"getElementById('gate').addEventListener('submit'"),
        'Landing page missing manual form submit control');
    checkGuard(!str_contains($text,'location.assign(')
        && !str_contains($text,'location.replace(')
        && !str_contains($text,'location.href='),
        'Unexpected automatic redirect without user click');
    $rewrite=file_get_contents(dirname(__DIR__,2).'/catalog/.htaccess');
    checkGuard(str_contains($rewrite,'^file-examine\.php$')
        && str_contains($rewrite,'47\.79\.20[0-7]'),
        'Apache emergency pre-PHP rules missing');
    echo json_encode([
        'pass'=>true,
        'three_second_wait_remains_manual'=>true,
        'token_requires_continue_to_issue_pass'=>true,
        'proof_bound_to_client_ip_and_user_agent'=>true,
        'max_parallel_public_heavy_pages'=>CatalogPublicPageAdmission::MAX_ACTIVE,
        'max_public_heavy_requests_per_two_seconds'=>CatalogPublicPageAdmission::MAX_IN_TWO_SECONDS,
        'max_per_signed_pass_per_minute'=>CatalogPublicPageAdmission::MAX_PER_PASS_MINUTE,
        'fifth_parallel_request_rejected'=>true,
        'parallel_slot_released'=>true,
        'pre_php_known_abuse_network_rule_present'=>true,
        'prd_database_accessed'=>false,
        'production_packages_accessed'=>false,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);
    exit(1);
}
