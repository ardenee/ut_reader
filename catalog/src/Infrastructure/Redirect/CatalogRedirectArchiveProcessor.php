<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Redirect;

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogRedirectArchiveStream;

/**
 * Single production entry point for Unreal redirect decompression.
 *
 * HTTP upload handlers only stage data. This processor is called by CLI job
 * handlers and dispatches each wrapper to its engine-defined implementation:
 * UE1 .uz uses the signed FCodec chain, UE2 .uz2 uses independent 32 KiB zlib
 * records, and UE3 .uz3 uses one tagged whole-file zlib stream.
 */
final class CatalogRedirectArchiveProcessor
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchivePayload.php';
    }

    public function supports(string $sourceName): bool
    {
        return \catalog_redirect_archive_is_supported_filename($sourceName);
    }

    /**
     * @param callable(array<string,int|string|bool>):void|null $progress
     * @return array{
     *   path:string,
     *   filename:string,
     *   bytes:int,
     *   compressed_bytes:int,
     *   source_extension:string,
     *   decoder:string,
     *   chunks:int,
     *   expected_bytes:int,
     *   wrapper_signature?:int,
     *   is_unreal_package:bool
     * }
     */
    public function decompressToTemp(
        string $sourcePath,
        string $sourceName,
        ?callable $progress = null,
        bool $requirePackageTag = false
    ): array {
        if (!$this->supports($sourceName)) {
            throw new \InvalidArgumentException('Not an Unreal redirect compressed file: ' . basename($sourceName));
        }

        $extension = \catalog_redirect_archive_extension($sourceName);
        if ($extension === 'uz2') {
            return CatalogRedirectArchiveStream::decompressUz2(
                $sourcePath,
                $sourceName,
                $this->outputLimit(),
                $progress,
                $requirePackageTag
            );
        }

        $decoded = \catalog_redirect_archive_decompress_payload_to_temp(
            $sourcePath,
            $sourceName,
            $this->outputLimit()
        );
        if ($requirePackageTag && empty($decoded['is_unreal_package'])) {
            if (is_file((string)($decoded['path'] ?? ''))) {
                @unlink((string)$decoded['path']);
            }
            throw new \RuntimeException(
                'Redirect archive decoded, but its output is not an Unreal package: ' . basename($sourceName)
            );
        }
        return $decoded;
    }

    public function outputLimit(): int
    {
        $configured = (int)($this->config['max_redirect_output_bytes'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        $ingress = (int)($this->config['ingress_max_upload_bytes'] ?? $this->config['max_upload_bytes'] ?? 0);
        if ($ingress > 0) {
            return $ingress > intdiv(PHP_INT_MAX, 8)
                ? PHP_INT_MAX
                : max($ingress, $ingress * 8);
        }

        return PHP_INT_SIZE >= 8 ? 2 * 1024 * 1024 * 1024 : PHP_INT_MAX;
    }
}
