<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation peer secret.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

/**
 * Return the stored peer secret representation expected by fed_http_post_signed().
 *
 * fed_peer_secret() validates/decrypts the value and may migrate legacy plaintext
 * into encrypted storage. Reloading the row afterwards ensures strict encrypted-
 * secret mode never receives the temporary plaintext value.
 */
function federation_peer_stored_signing_secret(PDO $db, array $peer): string
{
    $peerId = (int)($peer['id'] ?? 0);
    if ($peerId <= 0) {
        throw new RuntimeException('Federation peer ID is unavailable.');
    }

    if (fed_peer_secret($db, $peer) === '') {
        throw new RuntimeException('Federation peer has no stored API secret.');
    }

    $current = catalog_one(
        $db,
        'SELECT shared_secret_plain FROM ue_federation_peers WHERE id=? LIMIT 1',
        [$peerId]
    );
    $stored = (string)($current['shared_secret_plain'] ?? '');
    if ($stored === '') {
        throw new RuntimeException('Federation peer has no stored API secret.');
    }

    return $stored;
}
