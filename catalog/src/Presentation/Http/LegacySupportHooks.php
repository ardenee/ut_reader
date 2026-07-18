<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use Throwable;

/**
 * Compatibility boundary for the remaining include-time HTTP behaviour.
 *
 * Legacy pages still include catalog/lib/CatalogSupport.php. Keeping these hooks
 * in one presentation-layer class makes that behaviour explicit and prevents new
 * database, routing, or rendering side effects from leaking into support helpers.
 */
final class LegacySupportHooks
{
    public static function register(): void
    {
        self::registerUnverifiedQueueAssets();
        self::redirectStagedFileInformation();
    }

    private static function currentScript(): string
    {
        return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    }

    private static function registerUnverifiedQueueAssets(): void
    {
        if (self::currentScript() !== 'unverified-files.php') {
            return;
        }

        $scripts = [
            'assets/unverified-files-layout.js' => dirname(__DIR__, 3) . '/assets/unverified-files-layout.js',
            'assets/unverified-duplicate-cleanup.js' => dirname(__DIR__, 3) . '/assets/unverified-duplicate-cleanup.js',
        ];
        $versions = [];
        foreach ($scripts as $source => $path) {
            $versions[$source] = is_file($path) ? (string)filemtime($path) : '1';
        }

        ob_start(static function (string $output) use ($versions): string {
            if (!str_contains($output, '</head>')) {
                return $output;
            }

            $html = '';
            foreach ($versions as $source => $version) {
                if (!str_contains($output, $source)) {
                    $html .= '<script src="'
                        . \catalog_h($source . '?v=' . rawurlencode($version))
                        . '" defer></script>';
                }
            }

            if ($html === '') {
                return $output;
            }

            return preg_replace('/<\/head>/', $html . '</head>', $output, 1) ?? $output;
        });
    }

    private static function redirectStagedFileInformation(): void
    {
        if (self::currentScript() !== 'file-info.php') {
            return;
        }

        $fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $fileId = $fileId === false || $fileId === null ? 0 : (int)$fileId;
        if ($fileId < 1) {
            return;
        }

        try {
            $db = \catalog_db(\catalog_config());
            $row = \catalog_one(
                $db,
                'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1',
                [$fileId]
            );
            if ($row && (string)$row['scan_status'] === 'unverified') {
                header('Location: unverified-file-details.php?id=' . $fileId, true, 302);
                exit;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB file info routing] ' . $error->getMessage());
        }
    }
}
