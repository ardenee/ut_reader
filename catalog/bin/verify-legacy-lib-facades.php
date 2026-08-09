#!/usr/bin/env php
<?php
/**
 * Purpose: Guards extracted catalog/lib compatibility facades against business-logic regrowth.
 * Role: Read-only architecture close-out verification. Intentional parser/archive compatibility code is not moved merely for directory purity.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$facades = [
    'lib/FederationAuth.php' => 320,
    'lib/FederationTransferAuth.php' => 180,
    'lib/FederationPairing.php' => 120,
    'lib/FederationBaseGamePolicy.php' => 180,
    'lib/FederationDependencyDownloads.php' => 140,
    'lib/FederationPackageAvailability.php' => 220,
    'lib/FederationRequestLifecycle.php' => 180,
    'lib/BaseGameProtection.php' => 180,
    'lib/CatalogSmtpMailer.php' => 180,
    'lib/DownloadActivity.php' => 220,
    'lib/CatalogPublicResponseCache.php' => 220,
];

foreach ($facades as $relative => $maxLines) {
    $path = $root . '/' . $relative;
    $source = @file_get_contents($path);
    $lines = is_string($source) ? substr_count($source, "\n") + 1 : 0;
    $record('present:' . $relative, is_string($source) && $source !== '', $relative);
    if (!is_string($source) || $source === '') continue;
    $record(
        'facade_size:' . $relative,
        $lines <= $maxLines,
        $lines . ' lines; maximum ' . $maxLines . ' for this compatibility surface'
    );
    $record(
        'facade_role:' . $relative,
        str_contains($source, 'compatibility facade') || str_contains($source, 'Compatibility facade'),
        'extracted legacy files must be explicitly documented as compatibility facades'
    );
}

$federationAuth = (string)@file_get_contents($root . '/lib/FederationAuth.php');
$record(
    'federation_auth_no_implementation',
    !str_contains($federationAuth, 'php://input')
        && !str_contains($federationAuth, 'INSERT INTO ue_federation_transfer_logs')
        && !str_contains($federationAuth, 'SELECT setting_value FROM ue_federation_settings')
        && !str_contains($federationAuth, 'hash_hmac(')
        && !str_contains($federationAuth, 'sodium_crypto_sign_detached('),
    'FederationAuth must remain a compatibility API only'
);

$intentional = [
    'CatalogLegacyUz.php',
    'CatalogPakArchive.php',
    'CatalogRedirectArchive.php',
    'CatalogScanner.php',
    'CatalogRuntimeSqlCompatibility.php',
    'CatalogSupport.php',
    'CatalogSupportCore.php',
    'GameProfiles.php',
];
$inventory = [];
foreach (glob($root . '/lib/*.php') ?: [] as $path) {
    $name = basename($path);
    $source = (string)@file_get_contents($path);
    $lines = substr_count($source, "\n") + 1;
    if ($lines >= 500) {
        $inventory[] = [
            'file' => 'lib/' . $name,
            'lines' => $lines,
            'intentional_low_level' => in_array($name, $intentional, true),
            'facade_documented' => str_contains($source, 'compatibility facade') || str_contains($source, 'Compatibility facade'),
        ];
    }
}
usort($inventory, static fn(array $a, array $b): int => $b['lines'] <=> $a['lines']);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'large_legacy_inventory' => $inventory,
    'note' => 'Large low-level parser/archive compatibility files are inventoried, not relocated without a behavior-driven reason.',
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
