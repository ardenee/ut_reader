<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Security\FederationSecretStore;

function federation_secret_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$key = random_bytes(32);
$store = new FederationSecretStore($key);
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$encrypted = $store->encrypt($secret);

federation_secret_expect(str_starts_with($encrypted, 'enc1:'), 'Encrypted peer secret lacks the version marker.');
federation_secret_expect(strlen($encrypted) <= 128, 'Encrypted peer secret no longer fits the existing schema column.');
federation_secret_expect(!str_contains($encrypted, $secret), 'Encrypted peer secret contains plaintext.');
federation_secret_expect($store->decrypt($encrypted) === $secret, 'Encrypted peer secret did not round-trip.');
federation_secret_expect($store->decrypt($secret) === $secret, 'Legacy plaintext compatibility changed.');

$wrongKeyRejected = false;
try {
    (new FederationSecretStore(random_bytes(32)))->decrypt($encrypted);
} catch (RuntimeException) {
    $wrongKeyRejected = true;
}
federation_secret_expect($wrongKeyRejected, 'Encrypted peer secret was accepted with the wrong master key.');

putenv('UNREALDB_FEDERATION_MASTER_KEY=base64:' . base64_encode($key));
putenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=1');
require_once __DIR__ . '/../lib/FederationAuth.php';

$prepared = fed_prepare_peer_secret($secret);
federation_secret_expect($store->isEncrypted((string)$prepared['stored']), 'New peer secret was not encrypted under strict policy.');
federation_secret_expect(password_verify($secret, (string)$prepared['hash']), 'Peer verification hash no longer matches the plaintext secret.');
$signature = fed_sign_request((string)$prepared['stored'], 'POST', '/api/federation/test', '2026-07-18T00:00:00+00:00', 'nonce', '{}');
federation_secret_expect(strlen($signature) === 64, 'Encrypted peer secret could not be used for HMAC signing.');

$plaintextRejected = false;
try {
    fed_sign_request($secret, 'POST', '/api/federation/test', '2026-07-18T00:00:00+00:00', 'nonce', '{}');
} catch (RuntimeException) {
    $plaintextRejected = true;
}
federation_secret_expect($plaintextRejected, 'Strict policy accepted a plaintext peer secret.');

$joinReview = file_get_contents(__DIR__ . '/../federation/join-requests.php');
federation_secret_expect(is_string($joinReview), 'join-requests.php could not be read.');
federation_secret_expect(!str_contains($joinReview, 'PAIRING_SECRET:'), 'Join approval still stores pairing material in admin notes.');
federation_secret_expect(str_contains($joinReview, 'fed_prepare_peer_secret'), 'Join approval bypasses encrypted peer-secret storage.');

$claimEndpoint = file_get_contents(__DIR__ . '/../api/federation/join-claim.php');
federation_secret_expect(is_string($claimEndpoint), 'join-claim.php could not be read.');
federation_secret_expect(str_contains($claimEndpoint, "REQUEST_METHOD'] ?? 'GET')) !== false, 'Join claim does not enforce an HTTP method.');
federation_secret_expect(!str_contains($claimEndpoint, "_GET['token']"), 'Join claim still accepts a bearer token from the query string.');
federation_secret_expect(str_contains($claimEndpoint, 'fed_peer_secret'), 'Join claim no longer reads pairing material from the encrypted peer store.');

$statusEndpoint = file_get_contents(__DIR__ . '/../api/federation/join-request-status.php');
federation_secret_expect(is_string($statusEndpoint), 'join-request-status.php could not be read.');
federation_secret_expect(!str_contains($statusEndpoint, 'PAIRING_SECRET:'), 'Join status still parses pairing material from admin notes.');
federation_secret_expect(!str_contains($statusEndpoint, "'parent' => ["), 'Join status still returns parent pairing material.');

echo "Federation secret store tests passed.\n";
