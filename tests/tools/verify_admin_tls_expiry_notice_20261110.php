<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
$cert = getenv('UNREALDB_TEST_CERT_PATH');
if (!is_string($cert) || !is_file($cert)) {
    throw new RuntimeException('Set UNREALDB_TEST_CERT_PATH to a local test certificate.');
}
$contents = file_get_contents($cert);
$meta = openssl_x509_parse($contents);
$expiry=(int)$meta['validTo_time_t'];
$cases = [
    ['far', $expiry-(4*86400), null],
    ['three-days', $expiry-(3*86400), 3],
    ['two-days', $expiry-(2*86400), 2],
    ['one-day', $expiry-86400, 1],
    ['last-hour', $expiry-3600, 1],
    ['expired', $expiry+60, -1],
];
foreach($cases as [$name,$now,$expected]) {
    $value=catalog_admin_tls_expiry_notice($cert,$now);
    if (($value['days']??null) !== $expected) {
        throw new RuntimeException($name.' incorrect: '.json_encode($value));
    }
    if($value!==null && ($value['fingerprint']??'')!==hash('sha256',$contents)){
        throw new RuntimeException('Certificate identity mismatch');
    }
}
if(catalog_admin_tls_expiry_notice($cert.'-missing',$expiry-100)!==null) {
    throw new RuntimeException('Missing certificate should not generate a notice');
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/catalog/lib/CatalogSupportCore.php');
if(!str_contains($source,"(\$_SESSION['user']['role'] ?? '') === 'admin'")
    || !str_contains($source,"data-admin=")
    || !str_contains($source,"catalog-tls-expiry-notice.js")) {
    throw new RuntimeException('Admin-only shared layout integration missing');
}
echo json_encode(['pass'=>true,'tested'=>array_column($cases,0),
'notice_only_for_admin'=>true,'certificate_identity_bound'=>true],JSON_PRETTY_PRINT).PHP_EOL;
