<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs trusted federation HTTPS requests with the configured test TLS/private-network exception.
 * Why: Federation transport policy should be namespaced and separate from ordinary HTTP source scanning.
 * Role: Infrastructure federation HTTP boundary preserving the historical process-local testing configuration contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use Throwable;
use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpCurlTransport;
use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpEndpointPolicy;

final class CatalogFederationHttpClient
{
    private static ?bool $testingConfigured = null;

    private readonly TrustedHttpEndpointPolicy $endpointPolicy;
    private readonly TrustedHttpCurlTransport $transport;

    public function __construct(private readonly bool $allowTesting = false)
    {
        $this->endpointPolicy = new TrustedHttpEndpointPolicy();
        $this->transport = new TrustedHttpCurlTransport($allowTesting);
    }

    public static function configureTesting(bool $enabled): void
    {
        self::$testingConfigured = $enabled;
    }

    public static function fromRuntime(): self
    {
        if (self::$testingConfigured === null) {
            $enabled = false;
            try {
                if (function_exists('catalog_config') && function_exists('catalog_db') && function_exists('catalog_one')) {
                    $db = \catalog_db(\catalog_config());
                    $row = \catalog_one(
                        $db,
                        'SELECT setting_value FROM ue_federation_settings WHERE setting_name=? LIMIT 1',
                        ['allow_self_signed_federation_certificates']
                    );
                    $enabled = (string)($row['setting_value'] ?? '0') === '1';
                }
            } catch (Throwable $error) {
                error_log('[UnrealDB federation TLS] Could not read test TLS setting: ' . $error->getMessage());
            }
            self::$testingConfigured = $enabled;
        }

        return new self(self::$testingConfigured);
    }

    /** @param list<string> $headers @return array<string,mixed> */
    public function postJson(
        string $url,
        array $headers,
        string $body,
        int $maxResponseBytes = 8388608,
        int $timeout = 60
    ): array {
        $source = $this->endpointPolicy->source($url, $this->allowTesting);
        return $this->transport->postJson(
            $source,
            $url,
            $headers,
            $body,
            $maxResponseBytes,
            $timeout
        );
    }

    /** @param list<string> $headers */
    public function postBodyToFile(
        string $url,
        array $headers,
        string $body,
        string $destination,
        int $maxBytes,
        int $timeout = 300,
        ?callable $progress = null
    ): int {
        $source = $this->endpointPolicy->source($url, $this->allowTesting);
        return $this->transport->postBodyToFile(
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
    public function putFileJson(
        string $url,
        array $headers,
        string $sourceFile,
        int $maxResponseBytes = 1048576,
        int $timeout = 3600,
        ?callable $progress = null
    ): array {
        $source = $this->endpointPolicy->source($url, $this->allowTesting);
        return $this->transport->putFileJson(
            $source,
            $url,
            $headers,
            $sourceFile,
            $maxResponseBytes,
            $timeout,
            $progress
        );
    }
}
