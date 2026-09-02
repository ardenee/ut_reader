<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Retains failed Unreal package uploads in database-backed unverified storage.
 * Why: Scanner support helpers should not own database lookup, staging fallback and filesystem retention policy.
 * Role: Infrastructure failure-retention service used by verified package import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

final class CatalogFailedUploadRetention
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/Scanner/CatalogScannerPath.php';
    }

    public function preserve(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason,
        ?int $uploadedBy = null
    ): void {
        if (!is_file($temporaryPath)) {
            return;
        }
        if (!self::hasUnrealPackageMagic($temporaryPath)) {
            @unlink($temporaryPath);
            return;
        }

        $normalizedSlug = \scanner_slug_text($gameSlug);
        try {
            $db = \catalog_db($this->config);
            $game = \catalog_one(
                $db,
                'SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1',
                [$gameSlug]
            );
            if (!$game) {
                foreach (\catalog_all($db, 'SELECT id,name,slug,profile_id FROM ue_games') as $candidate) {
                    if (\scanner_slug_text((string)$candidate['slug']) === $normalizedSlug) {
                        $game = $candidate;
                        break;
                    }
                }
            }
            if (!$game) {
                throw new RuntimeException('Target unverified queue game was not found.');
            }

            $sourceRelativePath = str_contains($originalName, '/') || str_contains($originalName, '\\')
                ? \scanner_normalize_source_relative_path($originalName)
                : '';
            (new LegacyUnverifiedFileStager($db, $this->config))->stageFailedUpload(
                (int)$game['id'],
                $temporaryPath,
                $originalName,
                $reason,
                $uploadedBy,
                $sourceRelativePath
            );
            return;
        } catch (Throwable $error) {
            error_log('[UnrealDB failed upload staging] ' . $originalName . ': ' . $error->getMessage());
            if (!is_file($temporaryPath)) {
                return;
            }
        }

        // Last-resort retention if database staging itself is unavailable.
        // Queue reconciliation can recover this filesystem-only entry later.
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . '/games/' . $normalizedSlug . '/unverified';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $cleanName = \scanner_clean_original_filename($originalName);
        $queueName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_'
            . preg_replace('/[^A-Za-z0-9._ +\-]+/', '_', basename($cleanName));
        $destination = $directory . '/' . $queueName;
        if (@rename($temporaryPath, $destination)) {
            @file_put_contents(
                $destination . '.txt',
                $reason . "\nDatabase staging was unavailable; run unverified queue reconciliation."
            );
        }
    }

    public static function hasUnrealPackageMagic(string $path): bool
    {
        $bytes = @file_get_contents($path, false, null, 0, 4);
        if (!is_string($bytes) || strlen($bytes) !== 4) {
            return false;
        }
        $tag = (int)(unpack('V', $bytes)[1] ?? 0);
        return \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag);
    }
}
