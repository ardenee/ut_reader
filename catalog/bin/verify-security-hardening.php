#!/usr/bin/env php
<?php
/**
 * Read-only regression checks for the August 2026 security hardening pass.
 *
 * Covers federation secret policy, administrator login throttling, CSP/proxy
 * safeguards, generic 5xx responses and deployment supply-chain defaults.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = realpath(dirname($catalogRoot)) ?: dirname($catalogRoot);
require_once $catalogRoot . '/bootstrap/autoload.php';
require_once $catalogRoot . '/lib/CatalogSecurity.php';

use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationPeerSecretService;
use UnrealDb\Catalog\Infrastructure\Security\FileLoginRateLimiter;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $path): string {
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$removeTree = static function (string $directory) use (&$removeTree): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            $removeTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
};

$originalStrict = getenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS');
try {
    putenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS');
    $defaultStrict = CatalogFederationPeerSecretService::requireEncryptedSecrets();
    putenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=0');
    $explicitCompatibility = !CatalogFederationPeerSecretService::requireEncryptedSecrets();
    $record(
        'federation_secret_policy_fail_closed',
        $defaultStrict && $explicitCompatibility,
        'encryption is default; plaintext compatibility requires an explicit opt-out'
    );
} finally {
    if ($originalStrict === false) {
        putenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS');
    } else {
        putenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=' . $originalStrict);
    }
}

$rateRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-security-' . bin2hex(random_bytes(6));
$now = 1_000_000;
$clock = static function () use (&$now): int {
    return $now;
};
try {
    $distributed = new FileLoginRateLimiter($rateRoot . '/distributed', 8, 900, 900, $clock);
    $distributedRetry = 0;
    for ($i = 1; $i <= 8; $i++) {
        $distributedRetry = $distributed->recordFailure('admin', '192.0.2.' . $i);
    }
    $record(
        'login_account_bucket_blocks_rotating_ips',
        $distributedRetry > 0,
        'same account is blocked after eight failures across distinct IP addresses'
    );

    $rotatingAccounts = new FileLoginRateLimiter($rateRoot . '/ip', 8, 900, 900, $clock);
    $ipRetry = 0;
    for ($i = 1; $i <= 20; $i++) {
        $ipRetry = $rotatingAccounts->recordFailure('admin-' . $i, '198.51.100.10');
    }
    $record(
        'login_ip_bucket_blocks_rotating_accounts',
        $ipRetry > 0,
        'same IP is blocked after the broader twenty-failure threshold'
    );

    $pair = new FileLoginRateLimiter($rateRoot . '/pair', 8, 900, 900, $clock);
    $pairRetry = 0;
    for ($i = 1; $i <= 5; $i++) {
        $pairRetry = $pair->recordFailure('admin', '203.0.113.25');
    }
    $record(
        'login_pair_bucket_trips_early',
        $pairRetry > 0,
        'repeated failures for one account+IP pair trip after five attempts'
    );
} finally {
    $removeTree($rateRoot);
}

$policy = catalog_security_content_security_policy();
$nonce = catalog_security_csp_nonce();
$record(
    'csp_nonce_policy',
    str_contains($policy, "script-src-elem 'self' 'nonce-{$nonce}'")
        && str_contains($policy, "script-src-attr 'unsafe-inline'")
        && str_contains($policy, "object-src 'none'")
        && str_contains($policy, "frame-ancestors 'none'"),
    'script elements are nonce restricted while legacy event attributes remain isolated'
);

$securitySource = $read($catalogRoot . '/lib/CatalogSecurity.php');
$transformSource = $read($catalogRoot . '/src/Presentation/Http/CatalogPageResponseTransform.php');
$record(
    'trusted_proxy_boundary',
    str_contains($securitySource, 'UNREALDB_TRUST_PROXY_HEADERS')
        && str_contains($securitySource, 'UNREALDB_TRUSTED_PROXY_IPS')
        && str_contains($securitySource, 'catalog_security_forwarded_proto_trusted()'),
    'X-Forwarded-Proto is accepted only through the explicit trusted-proxy boundary'
);
$record(
    'script_nonce_transform',
    str_contains($transformSource, "preg_replace_callback(")
        && str_contains($transformSource, "'<script nonce=\"'")
        && str_contains($transformSource, 'catalog_security_csp_nonce'),
    'shared HTML response transform attaches the request nonce to script elements'
);

$jsonSource = $read($catalogRoot . '/src/Presentation/Http/JsonResponse.php');
$record(
    'generic_server_error_boundary',
    str_contains($jsonSource, 'if ($status >= 500)')
        && str_contains($jsonSource, 'The request could not be completed. Reference:')
        && str_contains($jsonSource, "$details = ['request_id' => $requestId]") ,
    '5xx responses retain internal logging but expose only a request reference'
);

$compose = $read($repoRoot . '/compose.yaml');
$composeSecurity = $read($repoRoot . '/compose.security.yaml');
$record(
    'compose_fail_closed_defaults',
    !str_contains($compose, 'change-this-database-password')
        && !str_contains($compose, 'change-this-root-password')
        && str_contains($compose, 'UNREALDB_SESSION_COOKIE_SECURE:-1')
        && str_contains($compose, 'UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS:-1')
        && str_contains($composeSecurity, 'UNREALDB_SECURITY_MASTER_KEY:?')
        && str_contains($composeSecurity, 'UNREALDB_FEDERATION_MASTER_KEY:?'),
    'container deployment no longer falls back to known credentials or plaintext-secret mode'
);

$containerWorkflow = $read($repoRoot . '/.github/workflows/container-release.yml');
$deployWorkflow = $read($repoRoot . '/.github/workflows/deploy-production.yml');
$workflowSource = $containerWorkflow . "\n" . $deployWorkflow;
$record(
    'workflow_dependencies_pinned',
    preg_match('/uses:\s+[^\s]+@v\d+/i', $workflowSource) !== 1
        && !str_contains($workflowSource, 'version: latest')
        && str_contains($workflowSource, 'version: v1.36.3')
        && str_contains($containerWorkflow, 'sbom: true')
        && str_contains($containerWorkflow, 'provenance: mode=max'),
    'GitHub Actions use immutable SHAs and kubectl/SBOM/provenance are fixed explicitly'
);

$dockerfile = $read($repoRoot . '/Dockerfile');
$record(
    'php_redis_dependency_pinned',
    str_contains($dockerfile, 'pecl install redis-6.3.0')
        && !str_contains($dockerfile, 'pecl install redis \\'),
    'PECL Redis extension uses a fixed release'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
