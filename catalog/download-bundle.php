<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';

function bundle_safe_name(string $name): string
{
    $name = catalog_clean_unreal_filename($name);
    $name = trim(str_replace(["\0", '/', '\\'], ['', '_', '_'], $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'file';
    return $name !== '' ? $name : 'file';
}

function bundle_storage_path(array $config, array $file): string
{
    $path = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
        throw new RuntimeException('Stored file missing for ' . $file['original_name']);
    }
    return $path;
}

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is not available. Enable/install zip support for bundle downloads.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $mode = external_public_download_mode($db);

    if ($mode === 'disabled') {
        throw new RuntimeException('Public downloads are disabled.');
    }
    if ($mode === 'external_mirror') {
        throw new RuntimeException('Bundle ZIP downloads are not available when public download mode is external mirror only. Download individual mirrored files instead.');
    }

    $id = (int)($_GET['id'] ?? 0);
    $main = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status<>"failed"', [$id]);
    if (!$main) {
        throw new RuntimeException('File not found');
    }

    $rows = [$main];
    $deps = catalog_all($db, 'SELECT DISTINCT rf.* FROM ue_dependencies d JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? AND d.status="resolved" AND rf.scan_status<>"failed" ORDER BY rf.package_name, rf.original_name', [$id]);
    foreach ($deps as $dep) {
        $rows[] = $dep;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ue_bundle_');
    if (!$tmp) {
        throw new RuntimeException('Could not create temporary bundle file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary ZIP bundle.');
    }

    $manifest = [];
    $addedNames = [];
    foreach ($rows as $file) {
        $path = bundle_storage_path($config, $file);
        $baseName = bundle_safe_name((string)$file['original_name']);
        $zipName = $baseName;
        if (isset($addedNames[strtolower($zipName)])) {
            $folder = 'duplicate-file-' . (int)$file['id'];
            $guid = preg_replace('/[^A-Za-z0-9-]+/', '', (string)($file['package_guid'] ?? '')) ?? '';
            if ($guid !== '') {
                $folder .= '-' . $guid;
            }
            $zipName = $folder . '/' . $baseName;
        }
        $addedNames[strtolower($zipName)] = true;
        $zip->addFile($path, $zipName);
        $manifest[] = [
            'zip_name' => $zipName,
            'package_name' => (string)$file['package_name'],
            'original_name' => catalog_clean_unreal_filename((string)$file['original_name']),
            'md5' => (string)$file['md5'],
            'sha1' => (string)$file['sha1'],
            'package_guid' => (string)$file['package_guid'],
            'size' => (int)$file['file_size'],
        ];
    }

    $zip->addFromString('catalog_manifest.json', json_encode([
        'generated_at' => date('c'),
        'selected_file_id' => $id,
        'selected_package' => (string)$main['package_name'],
        'public_download_mode' => $mode,
        'file_count' => count($manifest),
        'files' => $manifest,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $downloadName = catalog_clean_unreal_package_stem((string)$main['package_name']) . '_with_dependencies.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Bundle download error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
