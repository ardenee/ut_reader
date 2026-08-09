#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the federation key-material and peer-secret architecture boundary.
 * Why: Key loading, encoding, secret encryption/migration and signature consumers must not drift back into FederationAuth.php.
 * Role: Read-only architecture/security regression verification; never mutates schema or application data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];

$read = static function (string $relative) use ($catalogRoot): string {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$criticalPhp = [
    'lib/FederationAuth.php',
    'lib/FederationPeerSecret.php',
    'src/Infrastructure/Security/CatalogFederationKeyMaterial.php',
    'src/Infrastructure/Security/CatalogFederationPeerSecretService.php',
    'src/Infrastructure/Federation/CatalogFederationRequestSignatureService.php',
    'src/Infrastructure/Federation/CatalogFederationTransferSignatureService.php',
    'src/Infrastructure/Federation/CatalogFederationSignedJsonClient.php',
    'src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php',
    'src/Infrastructure/Federation/CatalogFederationStreamingUploadAuthenticator.php',
    'src/Infrastructure/Email/CatalogSmtpSettingsStore.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on the guarded PHP files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

$auth = $read('lib/FederationAuth.php');
$keyMaterial = $read('src/Infrastructure/Security/CatalogFederationKeyMaterial.php');
$peerSecrets = $read('src/Infrastructure/Security/CatalogFederationPeerSecretService.php');
$requestSignature = $read('src/Infrastructure/Federation/CatalogFederationRequestSignatureService.php');
$transferSignature = $read('src/Infrastructure/Federation/CatalogFederationTransferSignatureService.php');
$signedJson = $read('src/Infrastructure/Federation/CatalogFederationSignedJsonClient.php');
$signedAuthenticator = $read('src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php');
$streamAuthenticator = $read('src/Infrastructure/Federation/CatalogFederationStreamingUploadAuthenticator.php');
$peerSecretFacade = $read('lib/FederationPeerSecret.php');
$smtpStore = $read('src/Infrastructure/Email/CatalogSmtpSettingsStore.php');

$secretSegmentStart = strpos($auth, 'function fed_random_secret(');
$secretSegmentEnd = strpos($auth, 'function fed_setting(', $secretSegmentStart === false ? 0 : $secretSegmentStart);
$secretSegment = $secretSegmentStart !== false && $secretSegmentEnd !== false
    ? substr($auth, $secretSegmentStart, $secretSegmentEnd - $secretSegmentStart)
    : '';

$facadeDelegates = [
    'CatalogFederationKeyMaterial::randomSecret',
    'CatalogFederationKeyMaterial::base64UrlEncode',
    'CatalogFederationKeyMaterial::base64UrlDecode',
    'CatalogFederationKeyMaterial::ed25519SecretKey',
    'CatalogFederationKeyMaterial::ed25519PublicKey',
    'CatalogFederationKeyMaterial::ed25519KeyId',
    'CatalogFederationPeerSecretService::store',
    'CatalogFederationPeerSecretService::requireEncryptedSecrets',
    'CatalogFederationPeerSecretService::prepare',
    'CatalogFederationPeerSecretService::forCrypto',
    'CatalogFederationPeerSecretService($db))->peerSecret',
    'CatalogFederationPeerSecretService($db))->migrateAll',
];
$missingDelegates = array_values(array_filter(
    $facadeDelegates,
    static fn(string $needle): bool => !str_contains($secretSegment, $needle)
));
$record(
    'secret_facade_boundary',
    $secretSegment !== ''
        && $missingDelegates === []
        && !str_contains($secretSegment, 'UNREALDB_FEDERATION_ED25519_PRIVATE_KEY')
        && !str_contains($secretSegment, 'UNREALDB_FEDERATION_MASTER_KEY')
        && !str_contains($secretSegment, 'UPDATE ue_federation_peers SET shared_secret_plain=')
        && !str_contains($secretSegment, 'sodium_crypto_sign_seed_keypair'),
    $missingDelegates === [] ? 'key/secret helpers delegate to Infrastructure' : 'missing: ' . implode(', ', $missingDelegates)
);

$keyContracts = [
    'Invalid federation key encoding.',
    'Ed25519 federation signing requires the PHP sodium extension.',
    'UNREALDB_FEDERATION_ED25519_PRIVATE_KEY must encode a 32-byte seed or 64-byte secret key.',
    '/^[A-Za-z0-9_+\\/-]+={0,2}$/',
    'sodium_crypto_sign_seed_keypair',
    'sodium_crypto_sign_publickey_from_secretkey',
];
$missingKeyContracts = array_values(array_filter(
    $keyContracts,
    static fn(string $needle): bool => !str_contains($keyMaterial, $needle)
));
$record(
    'key_material_contract',
    $missingKeyContracts === [],
    $missingKeyContracts === [] ? 'base64url and Ed25519 key contracts retained' : 'missing: ' . implode(', ', $missingKeyContracts)
);

$secretContracts = [
    'Federation shared secrets must contain between 1 and 64 bytes.',
    'Federation secret encryption is required, but UNREALDB_FEDERATION_MASTER_KEY is not configured.',
    'A plaintext federation peer secret remains. Run catalog/bin/encrypt-federation-secrets.php',
    'UNREALDB_FEDERATION_MASTER_KEY must be configured before migrating peer secrets.',
    'UPDATE ue_federation_peers SET shared_secret_plain=? WHERE id=? AND shared_secret_plain=?',
    'PEER_SECRET_ENCRYPTED',
    'Legacy plaintext peer secret encrypted at first authenticated use.',
];
$missingSecretContracts = array_values(array_filter(
    $secretContracts,
    static fn(string $needle): bool => !str_contains($peerSecrets, $needle)
));
$record(
    'peer_secret_contract',
    $missingSecretContracts === [],
    $missingSecretContracts === [] ? 'encryption/migration/error contracts retained' : 'missing: ' . implode(', ', $missingSecretContracts)
);

$legacySecretCalls = [
    '\\fed_secret_for_crypto(',
    '\\fed_ed25519_secret_key(',
    '\\fed_ed25519_public_key(',
    '\\fed_ed25519_key_id(',
    '\\fed_base64url_encode(',
    '\\fed_base64url_decode(',
];
$signatureLegacyHits = [];
foreach (['request' => $requestSignature, 'transfer' => $transferSignature, 'json' => $signedJson] as $name => $content) {
    foreach ($legacySecretCalls as $needle) {
        if (str_contains($content, $needle)) {
            $signatureLegacyHits[] = $name . ':' . $needle;
        }
    }
}
$record(
    'signature_services_no_legacy_secret_calls',
    $signatureLegacyHits === []
        && str_contains($requestSignature, 'CatalogFederationPeerSecretService::forCrypto')
        && str_contains($transferSignature, 'CatalogFederationPeerSecretService::forCrypto')
        && str_contains($requestSignature, 'CatalogFederationKeyMaterial::ed25519SecretKey')
        && str_contains($transferSignature, 'CatalogFederationKeyMaterial::ed25519SecretKey')
        && str_contains($signedJson, 'CatalogFederationKeyMaterial::randomSecret'),
    $signatureLegacyHits === [] ? 'signature/JSON services depend directly on security infrastructure' : implode(', ', $signatureLegacyHits)
);

$record(
    'authenticators_no_legacy_peer_secret_call',
    !str_contains($signedAuthenticator, '\\fed_peer_secret(')
        && !str_contains($streamAuthenticator, '\\fed_peer_secret(')
        && str_contains($signedAuthenticator, 'CatalogFederationPeerSecretService')
        && str_contains($streamAuthenticator, 'CatalogFederationPeerSecretService'),
    'inbound authenticators must resolve peer secrets through the security service'
);

$record(
    'peer_secret_facade_dependency',
    !str_contains($peerSecretFacade, "require_once __DIR__ . '/FederationAuth.php'")
        && !str_contains($peerSecretFacade, 'fed_peer_secret(')
        && str_contains($peerSecretFacade, 'CatalogFederationPeerSecretService'),
    'stored-signing-secret facade must not load FederationAuth for secret migration'
);

$record(
    'smtp_secret_dependency',
    !str_contains($smtpStore, 'FederationAuth.php')
        && !str_contains($smtpStore, 'fed_secret_for_crypto')
        && str_contains($smtpStore, 'CatalogFederationPeerSecretService::forCrypto'),
    'SMTP secret decryption must use security infrastructure directly'
);

require_once $catalogRoot . '/bootstrap/autoload.php';

$sample = "\x00UnrealDB federation key\xff";
$encoded = \UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial::base64UrlEncode($sample);
$decoded = \UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial::base64UrlDecode($encoded);
$keyId = \UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial::ed25519KeyId('public-key-bytes');
$nonce = \UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial::randomSecret();

$record(
    'key_material_pure_contract',
    $decoded === $sample
        && $keyId === strtoupper(substr(hash('sha256', 'public-key-bytes'), 0, 24))
        && preg_match('/^[A-Za-z0-9_-]{43}$/', $nonce) === 1,
    'base64url round-trip, key-id and 32-byte random-secret encoding retained'
);

$invalidRejected = false;
try {
    \UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial::base64UrlDecode('bad value!');
} catch (InvalidArgumentException $error) {
    $invalidRejected = $error->getMessage() === 'Invalid federation key encoding.';
}
$record('invalid_key_encoding_contract', $invalidRejected, 'invalid base64url input retains the historical exception');

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
