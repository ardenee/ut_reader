<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Security\TotpService;
use UnrealDb\Catalog\Infrastructure\Security\ApplicationSecretStore;

function security_service_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
security_service_expect(TotpService::code($secret, 1) === '287082', 'RFC 6238-compatible six-digit code changed.');
security_service_expect(TotpService::verify($secret, '287082', 59, 0), 'Known TOTP code was rejected.');
security_service_expect(!TotpService::verify($secret, '287083', 59, 0), 'Invalid TOTP code was accepted.');

$generated = TotpService::generateSecret();
security_service_expect(preg_match('/^[A-Z2-7]{16,64}$/', $generated) === 1, 'Generated TOTP secret is invalid.');
$uri = TotpService::provisioningUri('UnrealDB Test', 'admin@example.test', $generated);
security_service_expect(str_starts_with($uri, 'otpauth://totp/'), 'Provisioning URI is invalid.');
security_service_expect(str_contains($uri, 'secret=' . rawurlencode($generated)), 'Provisioning URI omits the secret.');

$store = new ApplicationSecretStore(random_bytes(32));
$encrypted = $store->encrypt('fixture-secret');
security_service_expect(str_starts_with($encrypted, 'sec1:'), 'Encrypted application secret prefix changed.');
security_service_expect($store->decrypt($encrypted) === 'fixture-secret', 'Application secret round-trip failed.');

$tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');
$rejected = false;
try {
    $store->decrypt($tampered);
} catch (Throwable) {
    $rejected = true;
}
security_service_expect($rejected, 'Tampered application secret was accepted.');

fwrite(STDOUT, "Security service tests passed.\n");
