#!/usr/bin/env php
<?php
/** Read-only contract verifier ensuring SMTP secrets do not inherit federation peer plaintext policy. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/src/Infrastructure/Email/CatalogSmtpSettingsStore.php';
$source = (string)@file_get_contents($path);
$checks = [
    'smtp_uses_encrypted_value_codec_directly' => str_contains($source, 'FederationSecretStore::fromEnvironment()'),
    'smtp_only_decrypts_encrypted_values' => str_contains($source, '$secretStore->isEncrypted($storedPassword)'),
    'smtp_does_not_use_peer_strict_policy' => !str_contains($source, 'CatalogFederationPeerSecretService::forCrypto')
        && !str_contains($source, 'fed_secret_for_crypto'),
    'plaintext_smtp_remains_compatible' => str_contains($source, '$password = $storedPassword;'),
];

$failures = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failures[] = $name;
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
