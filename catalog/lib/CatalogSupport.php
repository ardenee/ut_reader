<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/CatalogUnverifiedAutoIndex.php';

// Load the unverified queue presentation and queue-only maintenance controls.
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'unverified-files.php') {
    $unverifiedScripts = [
        'assets/unverified-files-layout.js' => __DIR__ . '/../assets/unverified-files-layout.js',
        'assets/unverified-duplicate-cleanup.js' => __DIR__ . '/../assets/unverified-duplicate-cleanup.js',
    ];
    $unverifiedVersions = [];
    foreach ($unverifiedScripts as $src => $path) {
        $unverifiedVersions[$src] = is_file($path) ? (string)filemtime($path) : '1';
    }

    ob_start(static function (string $output) use ($unverifiedVersions): string {
        if (!str_contains($output, '</head>')) {
            return $output;
        }

        $scripts = '';
        foreach ($unverifiedVersions as $src => $version) {
            if (!str_contains($output, $src)) {
                $scripts .= '<script src="' . catalog_h($src . '?v=' . rawurlencode($version)) . '" defer></script>';
            }
        }
        return $scripts === '' ? $output : (preg_replace('/<\/head>/', $scripts . '</head>', $output, 1) ?? $output);
    });
}

// Keep the normal file-info.php URL usable for database-staged files.
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'file-info.php') {
    $stagedFileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $stagedFileId = $stagedFileId === false || $stagedFileId === null ? 0 : (int)$stagedFileId;
    if ($stagedFileId > 0) {
        try {
            $stagedDb = catalog_db(catalog_config());
            $stagedRow = catalog_one($stagedDb, 'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1', [$stagedFileId]);
            if ($stagedRow && (string)$stagedRow['scan_status'] === 'unverified') {
                header('Location: unverified-file-details.php?id=' . $stagedFileId, true, 302);
                exit;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB file info routing] ' . $error->getMessage());
        }
    }
}
