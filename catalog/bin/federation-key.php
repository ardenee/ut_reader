<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/FederationAuth.php';

/** @return array<string,string> */
function federation_key_options(array $arguments): array
{
    $options = [];
    for ($index = 0; $index < count($arguments); $index++) {
        $argument = (string)$arguments[$index];
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $argument = substr($argument, 2);
        if (str_contains($argument, '=')) {
            [$name, $value] = explode('=', $argument, 2);
            $options[$name] = $value;
            continue;
        }
        $next = $arguments[$index + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $options[$argument] = $next;
            $index++;
        } else {
            $options[$argument] = '1';
        }
    }
    return $options;
}

function federation_key_usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php catalog/bin/federation-key.php generate\n");
    fwrite(STDERR, "  php catalog/bin/federation-key.php show\n");
    fwrite(STDERR, "  php catalog/bin/federation-key.php set-peer --peer-id=1 --public-key=BASE64URL [--key-id=ID]\n");
    fwrite(STDERR, "  php catalog/bin/federation-key.php use-hmac --peer-id=1\n");
    fwrite(STDERR, "  php catalog/bin/federation-key.php revoke-peer --peer-id=1\n");
    exit(2);
}

$command = strtolower(trim((string)($argv[1] ?? '')));
$options = federation_key_options(array_slice($argv, 2));
if ($command === '') {
    federation_key_usage();
}
if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "PHP sodium is required for federation signing keys.\n");
    exit(1);
}

try {
    if ($command === 'generate') {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = sodium_crypto_sign_publickey($pair);
        fwrite(STDOUT, json_encode([
            'private_key_environment_value' => fed_base64url_encode($seed),
            'public_key' => fed_base64url_encode($public),
            'key_id' => fed_ed25519_key_id($public),
            'environment_name' => 'UNREALDB_FEDERATION_ED25519_PRIVATE_KEY',
            'signature_algorithm_environment' => 'UNREALDB_FEDERATION_SIGNATURE_ALGORITHM=ed25519',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'show') {
        $public = fed_ed25519_public_key();
        if ($public === '') {
            throw new RuntimeException('UNREALDB_FEDERATION_ED25519_PRIVATE_KEY is not configured.');
        }
        fwrite(STDOUT, json_encode([
            'public_key' => fed_base64url_encode($public),
            'key_id' => fed_ed25519_key_id($public),
            'outgoing_algorithm' => fed_outgoing_signature_algorithm(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $peerId = (int)($options['peer-id'] ?? 0);
    if ($peerId < 1) {
        federation_key_usage();
    }
    $application = catalog_bootstrap();
    $peer = catalog_one($application->db, 'SELECT id,peer_name,peer_site_id FROM ue_federation_peers WHERE id=?', [$peerId]);
    if (!$peer) {
        throw new RuntimeException('Federation peer was not found.');
    }

    if ($command === 'set-peer') {
        $encoded = trim((string)($options['public-key'] ?? ''));
        $public = fed_base64url_decode($encoded);
        if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Peer public key must encode exactly 32 bytes.');
        }
        $keyId = trim((string)($options['key-id'] ?? ''));
        if ($keyId === '') {
            $keyId = fed_ed25519_key_id($public);
        }
        if (strlen($keyId) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $keyId) !== 1) {
            throw new RuntimeException('Peer key ID is invalid.');
        }
        $statement = $application->db->prepare(
            'UPDATE ue_federation_peers SET signature_algorithm="ed25519",signing_public_key=?,signing_key_id=?,signing_rotated_at=NOW(),signing_revoked_at=NULL WHERE id=?'
        );
        $statement->execute([fed_base64url_encode($public), $keyId, $peerId]);
        fed_log($application->db, $peerId, null, 'INFO', 'PEER_SIGNING_KEY_SET', 'Ed25519 key ' . $keyId . ' installed by CLI.');
        fwrite(STDOUT, json_encode(['peer_id' => $peerId, 'algorithm' => 'ed25519', 'key_id' => $keyId], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'use-hmac') {
        $statement = $application->db->prepare('UPDATE ue_federation_peers SET signature_algorithm="hmac-sha256" WHERE id=?');
        $statement->execute([$peerId]);
        fed_log($application->db, $peerId, null, 'INFO', 'PEER_SIGNATURE_HMAC', 'Peer returned to HMAC compatibility mode by CLI.');
        fwrite(STDOUT, json_encode(['peer_id' => $peerId, 'algorithm' => 'hmac-sha256'], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'revoke-peer') {
        $statement = $application->db->prepare('UPDATE ue_federation_peers SET signing_revoked_at=NOW() WHERE id=? AND signing_public_key IS NOT NULL');
        $statement->execute([$peerId]);
        fed_log($application->db, $peerId, null, 'WARN', 'PEER_SIGNING_KEY_REVOKED', 'Peer Ed25519 key revoked by CLI.');
        fwrite(STDOUT, json_encode(['peer_id' => $peerId, 'revoked' => $statement->rowCount() > 0], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($statement->rowCount() > 0 ? 0 : 1);
    }

    federation_key_usage();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
