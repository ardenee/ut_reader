#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the federation streaming-transfer authentication compatibility boundary.
 * Why: Cryptography, HTTP credential validation and replay persistence must not drift back into the legacy helper facade.
 * Role: Read-only CLI architecture/regression verification; never mutates schema or application data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$files = [
    'facade' => 'lib/FederationTransferAuth.php',
    'signatures' => 'src/Infrastructure/Federation/CatalogFederationTransferSignatureService.php',
    'authenticator' => 'src/Infrastructure/Federation/CatalogFederationStreamingUploadAuthenticator.php',
];
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

$contents = [];
foreach ($files as $key => $relative) {
    $contents[$key] = $read($relative);
    $record($key . '_present', $contents[$key] !== '', $relative);
}

if (function_exists('proc_open')) {
    $syntaxFailures = [];
    foreach ($files as $relative) {
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
        if (proc_close($process) !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
} else {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually.');
}

$facade = $contents['facade'];
$record(
    'facade_boundary',
    str_contains($facade, 'CatalogFederationTransferSignatureService')
        && str_contains($facade, 'CatalogFederationStreamingUploadAuthenticator')
        && str_contains($facade, 'CatalogFederationJsonApi::respond')
        && !str_contains($facade, "'/FederationAuth.php'")
        && !str_contains($facade, 'hash_hmac(')
        && !str_contains($facade, 'sodium_crypto_sign_detached(')
        && !str_contains($facade, '$_SERVER')
        && !str_contains($facade, 'ue_federation_nonces')
        && !str_contains($facade, 'UPDATE ue_federation_peers'),
    'legacy helper must delegate cryptography, inbound authentication and JSON responses'
);

$signatures = $contents['signatures'];
$signatureContracts = [
    'strtoupper($method)',
    'strtolower($sha256)',
    "hash_hmac(\n            'sha256'",
    'CatalogFederationPeerSecretService::forCrypto',
    'CatalogFederationKeyMaterial::ed25519SecretKey',
    'CatalogFederationKeyMaterial::base64UrlEncode',
    'CatalogFederationKeyMaterial::base64UrlDecode',
    'sodium_crypto_sign_detached',
    'sodium_crypto_sign_verify_detached',
    'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES',
    'SODIUM_CRYPTO_SIGN_BYTES',
    'Ed25519 federation transfer signing is not configured.',
];
$missingSignatureContracts = [];
foreach ($signatureContracts as $needle) {
    if (!str_contains($signatures, $needle)) {
        $missingSignatureContracts[] = $needle;
    }
}
$record(
    'signature_wire_contract',
    $missingSignatureContracts === [],
    $missingSignatureContracts === []
        ? 'canonical payload and HMAC/Ed25519 contracts retained'
        : 'missing: ' . implode(', ', $missingSignatureContracts)
);

$authenticator = $contents['authenticator'];
$authContracts = [
    'Invalid streaming upload headers',
    'Unknown or inactive peer',
    'Expired or reused transfer credentials',
    'Peer signing key is unavailable or revoked',
    'Invalid transfer signature',
    'Nonce already used',
    'api_nonce_ttl_seconds',
    'TRANSFER_SIGNATURE_FAIL',
    'INSERT INTO ue_federation_nonces(peer_id, nonce)',
    'UPDATE ue_federation_peers SET last_seen_at=NOW()',
    "'hmac' || \$algorithm === 'hmac-sha256'",
];
$missingAuthContracts = [];
foreach ($authContracts as $needle) {
    if (!str_contains($authenticator, $needle)) {
        $missingAuthContracts[] = $needle;
    }
}
$record(
    'streaming_auth_contract',
    $missingAuthContracts === []
        && !str_contains($authenticator, "'/lib/FederationAuth.php'")
        && !str_contains($authenticator, '\\fed_setting(')
        && !str_contains($authenticator, '\\fed_log('),
    $missingAuthContracts === []
        ? 'header, peer, replay, signature and activity contracts retained'
        : 'missing: ' . implode(', ', $missingAuthContracts)
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
