<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical static trusted-HTTP API and federation testing switch.
 * Why: Endpoint trust policy and cURL transport now have focused namespaced owners while existing callers retain the
 *      established static contract and process-local federation testing state.
 * Role: Thin compatibility/coordinator layer; do not add network-policy or transfer implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpCurlTransport;
use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpEndpointPolicy;

final class TrustedHttpSourceClient
{
    private static bool $allowUntrustedTls = false;
    private static bool $allowPrivateNetwork = false;
    private static ?bool $federationTestingConfigured = null;

    public static function configureFederationTesting(bool $enabled): void
    {
        self::$allowUntrustedTls = $enabled;
        self::$allowPrivateNetwork = $enabled;
        self::$federationTestingConfigured = $enabled;
    }

    private static function configureFromFederationSetting(): void
    {
        if (self::$federationTestingConfigured !== null) {
            return;
        }

        $enabled = false;
        try {
            if (function_exists('catalog_config') && function_exists('catalog_db') && function_exists('catalog_one')) {
                $db = catalog_db(catalog_config());
                $row = catalog_one(
                    $db,
                    'SELECT setting_value FROM ue_federation_settings WHERE setting_name=? LIMIT 1',
                    ['allow_self_signed_federation_certificates']
                );
                $enabled = (string)($row['setting_value'] ?? '0') === '1';
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB federation TLS] Could not read test TLS setting: ' . $error->getMessage());
        }

        self::configureFederationTesting($enabled);
    }

    /** @return array{base:string,host:string,ip:string} */
    public static function source(string $baseUrl): array
    {
        return self::endpointPolicy()->source($baseUrl, self::$allowPrivateNetwork);
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public static function relativeUrl(array $source, string $relative): string
    {
        return self::endpointPolicy()->relativeUrl($source, $relative);
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public static function bytes(array $source, string $url, int $maxBytes, string $label): string
    {
        return self::transport()->bytes($source, $url, $maxBytes, $label);
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public static function toFile(
        array $source,
        string $url,
        string $destination,
        int $maxBytes,
        string $label
    ): int {
        return self::transport()->toFile($source, $url, $destination, $maxBytes, $label);
    }

    /** @param list<string> $headers @return array<string,mixed> */
    public static function postJson(
        string $url,
        array $headers,
        string $body,
        int $maxResponseBytes = 8388608,
        int $timeout = 60
    ): array {
        self::configureFromFederationSetting();
        $source = self::source($url);
        return self::transport()->postJson(
            $source,
            $url,
            $headers,
            $body,
            $maxResponseBytes,
            $timeout
        );
    }

    /** @param list<string> $headers */
    public static function postBodyToFile(
        string $url,
        array $headers,
        string $body,
        string $destination,
        int $maxBytes,
        int $timeout = 300,
        ?callable $progress = null
    ): int {
        self::configureFromFederationSetting();
        $source = self::source($url);
        return self::transport()->postBodyToFile(
            $source,
            $url,
            $headers,
            $body,
            $destination,
            $maxBytes,
            $timeout,
            $progress
        );
    }

    /** @param list<string> $headers @return array<string,mixed> */
    public static function putFileJson(
        string $url,
        array $headers,
        string $sourceFile,
        int $maxResponseBytes = 1048576,
        int $timeout = 3600,
        ?callable $progress = null
    ): array {
        self::configureFromFederationSetting();
        $source = self::source($url);
        return self::transport()->putFileJson(
            $source,
            $url,
            $headers,
            $sourceFile,
            $maxResponseBytes,
            $timeout,
            $progress
        );
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public static function headSize(array $source, string $url): ?int
    {
        return self::transport()->headSize($source, $url);
    }

    private static function endpointPolicy(): TrustedHttpEndpointPolicy
    {
        static $policy = null;
        return $policy ??= new TrustedHttpEndpointPolicy();
    }

    private static function transport(): TrustedHttpCurlTransport
    {
        return new TrustedHttpCurlTransport(self::$allowUntrustedTls);
    }
}
