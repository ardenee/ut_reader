#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies signed federation JSON request authentication and outgoing transport boundaries.
 * Why: Authentication, signature canonicalization, replay persistence and signed HTTP transport must not drift back into the legacy FederationAuth facade.
 * Role: Read-only architecture/protocol regression verification; never mutates schema or application data.
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
    'src/Infrastructure/Federation/CatalogFederationOutgoingSignaturePolicy.php',
    'src/Infrastructure/Federation/CatalogFederationRequestSignatureService.php',
    'src/Infrastructure/Federation/CatalogFederationSignedJsonClient.php',
    'src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php',
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
$signaturePolicy = $read('src/Infrastructure/Federation/CatalogFederationOutgoingSignaturePolicy.php');
$signatureService = $read('src/Infrastructure/Federation/CatalogFederationRequestSignatureService.php');
$signedClient = $read('src/Infrastructure/Federation/CatalogFederationSignedJsonClient.php');
$authenticator = $read('src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php');

$requireStart = strpos($auth, 'function fed_require_signed_peer(');
$requireEnd = strpos($auth, 'function fed_outgoing_signature_algorithm(', $requireStart === false ? 0 : $requireStart);
$requireSegment = $requireStart !== false && $requireEnd !== false
    ? substr($auth, $requireStart, $requireEnd - $requireStart)
    : '';

$record(
    'signed_peer_facade_boundary',
    str_contains($requireSegment, 'CatalogFederationSignedRequestAuthenticator')
        && str_contains($requireSegment, 'CatalogFederationApiException')
        && !str_contains($requireSegment, 'ue_federation_peers')
        && !str_contains($requireSegment, 'ue_federation_nonces')
        && !str_contains($requireSegment, 'signing_public_key')
        && !str_contains($requireSegment, 'hash_equals('),
    'fed_require_signed_peer must only adapt service exceptions into the legacy JSON response contract'
);

$signatureWrappers = [
    'CatalogFederationRequestSignatureService::bodyHash',
    'CatalogFederationRequestSignatureService::payload',
    'CatalogFederationRequestSignatureService::hmac',
    'CatalogFederationRequestSignatureService::verifyHmac',
    'CatalogFederationRequestSignatureService::ed25519',
    'CatalogFederationRequestSignatureService::verifyEd25519',
];
$missingWrappers = array_values(array_filter(
    $signatureWrappers,
    static fn(string $needle): bool => !str_contains($auth, $needle)
));
$record(
    'signature_facade_boundary',
    $missingWrappers === [],
    $missingWrappers === [] ? 'legacy signature helpers delegate to Infrastructure' : 'missing: ' . implode(', ', $missingWrappers)
);

$outgoingStart = strpos($auth, 'function fed_outgoing_signature_algorithm(');
$outgoingEnd = strpos($auth, 'function fed_public_status(', $outgoingStart === false ? 0 : $outgoingStart);
$outgoingSegment = $outgoingStart !== false && $outgoingEnd !== false
    ? substr($auth, $outgoingStart, $outgoingEnd - $outgoingStart)
    : '';
$record(
    'outgoing_signed_transport_facade_boundary',
    str_contains($outgoingSegment, 'CatalogFederationOutgoingSignaturePolicy::resolve')
        && str_contains($outgoingSegment, 'CatalogFederationSignedJsonClient::post')
        && !str_contains($outgoingSegment, 'json_encode(')
        && !str_contains($outgoingSegment, 'X-Site-Id:')
        && !str_contains($outgoingSegment, 'X-Signature:')
        && !str_contains($outgoingSegment, 'TrustedHttpSourceClient::')
        && !str_contains($auth, "require_once __DIR__ . '/TrustedHttpSourceClient.php'"),
    'outgoing signed JSON serialization/header/transport work must stay outside FederationAuth'
);

$authContracts = [
    'Missing federation auth headers',
    'Unknown or inactive peer',
    'Timestamp outside allowed window',
    'Nonce already used',
    'Peer signing key is unavailable or revoked',
    'Peer has no API secret stored.',
    'Unsupported signature algorithm',
    'Invalid signature',
    'SIGNING_KEY_REJECTED',
    'SIGNATURE_FAIL',
    'INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)',
    'UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?',
];
$missingAuthContracts = array_values(array_filter(
    $authContracts,
    static fn(string $needle): bool => !str_contains($authenticator, $needle)
));
$record(
    'signed_request_auth_contract',
    $missingAuthContracts === [],
    $missingAuthContracts === [] ? 'status/error/log/replay contracts retained' : 'missing: ' . implode(', ', $missingAuthContracts)
);

$record(
    'signature_wire_contract',
    str_contains($signatureService, 'return hash(\'sha256\', $body);')
        && str_contains($signatureService, 'strtoupper($method) . "\\n"')
        && str_contains($signatureService, "hash_hmac(\n            'sha256'")
        && str_contains($signatureService, 'sodium_crypto_sign_detached')
        && str_contains($signatureService, 'sodium_crypto_sign_verify_detached'),
    'SHA-256 body hash and canonical HMAC/Ed25519 wire format retained'
);

$outgoingContracts = [
    'Could not encode federation payload.',
    'Content-Type: application/json',
    'User-Agent: UnrealFileCatalogFederation/2.0',
    'X-Site-Id: ',
    'X-Timestamp: ',
    'X-Nonce: ',
    'X-Signature-Algorithm: ',
    'X-Key-Id: ',
    'X-Signature: ',
    'Ed25519 outgoing federation signing is selected but no private key is configured.',
    'CatalogFederationHttpClient::fromRuntime()->postJson',
];
$missingOutgoingContracts = array_values(array_filter(
    $outgoingContracts,
    static fn(string $needle): bool => !str_contains($signedClient, $needle)
));
$record(
    'outgoing_signed_transport_contract',
    $missingOutgoingContracts === []
        && str_contains($signedClient, 'CatalogFederationRequestSignatureService::ed25519')
        && str_contains($signedClient, 'CatalogFederationRequestSignatureService::hmac'),
    $missingOutgoingContracts === []
        ? 'JSON/header/signature/HTTP contracts retained'
        : 'missing: ' . implode(', ', $missingOutgoingContracts)
);

$record(
    'outgoing_algorithm_policy_boundary',
    str_contains($signaturePolicy, 'UNREALDB_FEDERATION_SIGNATURE_ALGORITHM')
        && str_contains($signaturePolicy, "=== 'ed25519' ? 'ed25519' : 'hmac-sha256'"),
    'outgoing algorithm selection retains the existing Ed25519-or-HMAC fallback'
);

require_once $catalogRoot . '/bootstrap/autoload.php';

$body = '{"ok":true,"value":42}';
$method = 'post';
$path = '/api/federation/test.php';
$timestamp = '2026-08-09T12:00:00+00:00';
$nonce = 'nonce-example';
$bodyHash = hash('sha256', $body);
$expectedPayload = "POST\n{$path}\n{$timestamp}\n{$nonce}\n{$bodyHash}";

$record(
    'signature_pure_contract',
    \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestSignatureService::bodyHash($body) === $bodyHash
        && \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestSignatureService::payload(
            $method,
            $path,
            $timestamp,
            $nonce,
            $bodyHash
        ) === $expectedPayload,
    'body hash and canonical payload bytes match the legacy protocol'
);

$record(
    'outgoing_algorithm_pure_contract',
    \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationOutgoingSignaturePolicy::resolve('ED25519') === 'ed25519'
        && \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationOutgoingSignaturePolicy::resolve('hmac-sha256') === 'hmac-sha256'
        && \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationOutgoingSignaturePolicy::resolve('hmac') === 'hmac-sha256'
        && \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationOutgoingSignaturePolicy::resolve('unsupported') === 'hmac-sha256',
    'algorithm normalization matches the legacy Ed25519-only opt-in contract'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
