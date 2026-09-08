#!/usr/bin/env php
<?php
/**
 * Read-only generated-package preflight diagnostic.
 *
 * Usage:
 *   php catalog/bin/diagnose-generated-package.php --file-id=155357 --format=dependency_zip --dependencies=1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportSettingsService;
use UnrealDb\Catalog\Infrastructure\Downloads\PdoCatalogPackageExportPlanner;

$options = getopt('', ['file-id:', 'format::', 'dependencies::', 'allow-incomplete::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php catalog/bin/diagnose-generated-package.php --file-id=<id> [--format=dependency_zip] [--dependencies=1] [--allow-incomplete=0]\n";
    exit(0);
}

$fileId = max(0, (int)($options['file-id'] ?? 0));
if ($fileId < 1) {
    fwrite(STDERR, "--file-id is required.\n");
    exit(1);
}

$format = strtolower(trim((string)($options['format'] ?? 'dependency_zip')));
$dependencies = (string)($options['dependencies'] ?? '1') !== '0';
$allowIncompleteRequested = (string)($options['allow-incomplete'] ?? '0') === '1';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $settingsService = new CatalogPackageExportSettingsService($db);
    $settings = $settingsService->settings();

    $file = catalog_one(
        $db,
        'SELECT id,game_id,package_name,original_name,sha1,scan_status,file_size FROM ue_files WHERE id=?',
        [$fileId]
    );
    if (!$file) {
        throw new RuntimeException('File #' . $fileId . ' was not found.');
    }
    if ((string)$file['scan_status'] !== 'verified') {
        throw new RuntimeException(
            'File #' . $fileId . ' is not verified; scan_status=' . (string)$file['scan_status'] . '.'
        );
    }

    $game = $settingsService->game((int)$file['game_id']);
    if (!$game) {
        throw new RuntimeException('Game #' . (int)$file['game_id'] . ' was not found.');
    }

    $available = $settingsService->availableFormats($game, $settings);
    if (!in_array($format, $available, true)) {
        throw new RuntimeException(
            'Format ' . $format . ' is not enabled for this game. Available: ' . implode(', ', $available)
        );
    }

    $plan = (new PdoCatalogPackageExportPlanner($db, $config))->plan(
        $fileId,
        $format,
        $dependencies,
        $settings
    );

    $allowIncomplete = !empty($settings['allow_incomplete']) && $allowIncompleteRequested;
    $output = [
        'ok' => true,
        'file' => [
            'id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'file_size' => (int)$file['file_size'],
        ],
        'game' => [
            'id' => (int)($game['id'] ?? 0),
            'name' => (string)($game['name'] ?? ''),
            'profile_name' => (string)($game['profile_name'] ?? ''),
            'engine_key' => (string)($game['engine_key'] ?? ''),
        ],
        'request' => [
            'format' => $format,
            'dependencies' => $dependencies,
            'allow_incomplete_requested' => $allowIncompleteRequested,
            'allow_incomplete_effective' => $allowIncomplete,
        ],
        'plan' => [
            'file_count' => (int)$plan['file_count'],
            'total_bytes' => (int)$plan['total_bytes'],
            'missing_dependencies' => count((array)$plan['missing']),
            'package_only_dependencies' => count((array)$plan['package_only']),
            'base_game_files_excluded' => count((array)$plan['blocked']),
            'common_dependencies' => count((array)$plan['common']),
        ],
        'would_queue' => count((array)$plan['missing']) === 0 || $allowIncomplete,
    ];

    if (!$output['would_queue']) {
        $output['queue_block_reason'] = count((array)$plan['missing'])
            . ' genuinely missing dependency object(s).';
    }

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $output = [
        'ok' => false,
        'file_id' => $fileId,
        'format' => $format,
        'dependencies' => $dependencies,
        'error_class' => get_class($error),
        'error' => $error->getMessage(),
        'source_file' => $error->getFile(),
        'source_line' => $error->getLine(),
    ];
    fwrite(STDERR, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
