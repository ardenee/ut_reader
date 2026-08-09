#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the federation settings and local-site identity boundary.
 * Why: Persistent settings, identity generation and public-status composition must not drift back into the legacy FederationAuth facade or federation state service.
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
    'src/Infrastructure/Federation/CatalogFederationSettingsStore.php',
    'src/Infrastructure/Federation/CatalogFederationIdentityService.php',
    'src/Infrastructure/Federation/CatalogFederationStateService.php',
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
$settings = $read('src/Infrastructure/Federation/CatalogFederationSettingsStore.php');
$identity = $read('src/Infrastructure/Federation/CatalogFederationIdentityService.php');
$state = $read('src/Infrastructure/Federation/CatalogFederationStateService.php');

$facadeDelegates = [
    'CatalogFederationIdentityService::randomId',
    'CatalogFederationSettingsStore($db))->get',
    'CatalogFederationSettingsStore($db))->set',
    'CatalogFederationSettingsStore($db))->all',
    'CatalogFederationIdentityService::fingerprint',
    'CatalogFederationIdentityService($db))->ensure',
    'CatalogFederationIdentityService($db))->publicStatus',
];
$missingFacadeDelegates = array_values(array_filter(
    $facadeDelegates,
    static fn(string $needle): bool => !str_contains($auth, $needle)
));
$record(
    'facade_delegation',
    $missingFacadeDelegates === []
        && !str_contains($auth, 'SELECT setting_value FROM ue_federation_settings')
        && !str_contains($auth, 'INSERT INTO ue_federation_settings')
        && !str_contains($auth, 'SELECT setting_name, setting_value FROM ue_federation_settings'),
    $missingFacadeDelegates === []
        ? 'legacy setting/identity helpers delegate to Infrastructure'
        : 'missing: ' . implode(', ', $missingFacadeDelegates)
);

$settingsContracts = [
    'SELECT setting_value FROM ue_federation_settings WHERE setting_name=?',
    'INSERT INTO ue_federation_settings(setting_name, setting_value) VALUES(?,?)',
    'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
    'SELECT setting_name, setting_value FROM ue_federation_settings ORDER BY setting_name',
];
$missingSettingsContracts = array_values(array_filter(
    $settingsContracts,
    static fn(string $needle): bool => !str_contains($settings, $needle)
));
$record(
    'settings_store_contract',
    $missingSettingsContracts === [],
    $missingSettingsContracts === [] ? 'read/write/all SQL contracts retained' : 'missing: ' . implode(', ', $missingSettingsContracts)
);

$identityContracts = [
    "get('site_id', '')",
    'set(\'site_id\', $siteId)',
    'set(\'site_url\', $siteUrl)',
    "get('site_url', '')",
    'set(\'site_name\', $siteName)',
    "get('site_name', '')",
    'set(\'site_fingerprint\', $fingerprint)',
    "'signature_algorithms' => ['hmac-sha256', 'ed25519']",
    "get('site_role', 'standalone')",
    "get('parent_enabled', '0')",
    "get('child_enabled', '0')",
    "'server_time' => date('c')",
];
$missingIdentityContracts = array_values(array_filter(
    $identityContracts,
    static fn(string $needle): bool => !str_contains($identity, $needle)
));
$record(
    'identity_contract',
    $missingIdentityContracts === [],
    $missingIdentityContracts === [] ? 'identity persistence/defaults/public status retained' : 'missing: ' . implode(', ', $missingIdentityContracts)
);

$record(
    'state_service_boundary',
    str_contains($state, 'CatalogFederationSettingsStore')
        && !str_contains($state, '\\fed_setting(')
        && !str_contains($state, '\\fed_set_setting(')
        && !str_contains($state, "'/lib/FederationAuth.php'"),
    'federation state transitions must use the settings store directly'
);

require_once $catalogRoot . '/bootstrap/autoload.php';

$identityClass = \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationIdentityService::class;
$siteUrl = ' HTTPS://Example.COM/Federation/ ';
$siteId = 'ABC-123';
$expectedFingerprint = strtoupper(substr(
    hash('sha256', 'https://example.com/federation|' . strtolower($siteId)),
    0,
    32
));
$actualFingerprint = $identityClass::fingerprint($siteUrl, $siteId);
$record(
    'fingerprint_pure_contract',
    $actualFingerprint === $expectedFingerprint,
    'fingerprint normalization and SHA-256 truncation match the legacy algorithm'
);

$randomId = $identityClass::randomId();
$record(
    'random_id_contract',
    preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
        $randomId
    ) === 1,
    'site IDs retain RFC-4122 version-4 shape'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
