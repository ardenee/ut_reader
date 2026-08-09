#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\CompactMetadataCutoverService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

const COMPACT_CUTOVER_CONVERT_TOKEN = 'CONVERT_VERIFIED_METADATA';
const COMPACT_CUTOVER_CLEANUP_TOKEN = 'PURGE_VERIFIED_LEGACY_METADATA';

function compact_cutover_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/compact-metadata-cutover.php status\n");
    fwrite(STDOUT, "  php catalog/bin/compact-metadata-cutover.php convert --apply=" . COMPACT_CUTOVER_CONVERT_TOKEN . " [--batch-size=25] [--max-files=25] [--continue-on-error]\n");
    fwrite(STDOUT, "  php catalog/bin/compact-metadata-cutover.php cleanup --apply=" . COMPACT_CUTOVER_CLEANUP_TOKEN . " [--batch-size=10] [--max-files=10] [--continue-on-error]\n");
    fwrite(STDOUT, "\nUse --max-files=0 to process all currently eligible files in one resumable run.\n");
}

/** @return array{phase:string,apply:string,batch_size:int,max_files:int,continue_on_error:bool} */
function compact_cutover_arguments(array $argv): array
{
    $phase = strtolower(trim((string)($argv[1] ?? 'status')));
    if (in_array($phase, ['--help', '-h', 'help'], true)) {
        compact_cutover_usage();
        exit(0);
    }
    if (!in_array($phase, ['status', 'convert', 'cleanup'], true)) {
        throw new InvalidArgumentException('Phase must be status, convert, or cleanup.');
    }

    $defaults = $phase === 'cleanup'
        ? ['batch_size' => 10, 'max_files' => 10]
        : ['batch_size' => 25, 'max_files' => 25];
    $result = [
        'phase' => $phase,
        'apply' => '',
        'batch_size' => $defaults['batch_size'],
        'max_files' => $defaults['max_files'],
        'continue_on_error' => false,
    ];

    foreach (array_slice($argv, 2) as $argument) {
        $argument = trim((string)$argument);
        if ($argument === '--continue-on-error') {
            $result['continue_on_error'] = true;
            continue;
        }
        foreach (['apply', 'batch-size', 'max-files'] as $name) {
            $prefix = '--' . $name . '=';
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }
            $value = substr($argument, strlen($prefix));
            if ($name === 'apply') {
                $result['apply'] = trim($value);
            } else {
                $result[str_replace('-', '_', $name)] = max(0, (int)$value);
            }
            continue 2;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    if ($phase === 'convert' && $result['apply'] !== COMPACT_CUTOVER_CONVERT_TOKEN) {
        throw new RuntimeException(
            'Conversion requires --apply=' . COMPACT_CUTOVER_CONVERT_TOKEN . '. No metadata was changed.'
        );
    }
    if ($phase === 'cleanup' && $result['apply'] !== COMPACT_CUTOVER_CLEANUP_TOKEN) {
        throw new RuntimeException(
            'Cleanup requires --apply=' . COMPACT_CUTOVER_CLEANUP_TOKEN . '. No legacy rows were changed.'
        );
    }

    return $result;
}

try {
    set_time_limit(0);
    $arguments = compact_cutover_arguments($argv);
    $config = catalog_config();
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }

    $service = new CompactMetadataCutoverService(catalog_db($config), $storageRoot);
    $progress = static function (array $event): void {
        $attempted = (int)($event['attempted'] ?? 0);
        $failed = !empty($event['failed']);
        if (!$failed && $attempted > 1 && ($attempted % 10) !== 0) {
            return;
        }

        $phase = (string)($event['phase'] ?? 'cutover');
        $fileId = (int)($event['file_id'] ?? 0);
        $message = trim((string)($event['message'] ?? ''));
        fwrite(
            STDERR,
            '[' . $phase . ']'
            . ($attempted > 0 ? ' processed=' . $attempted : '')
            . ($fileId > 0 ? ' file=#' . $fileId : '')
            . ($message !== '' ? ' ' . $message : '')
            . PHP_EOL
        );
    };

    if ($arguments['phase'] === 'status') {
        $result = $service->status(true);
        $result['recommended_first_conversion_command'] =
            'php catalog/bin/compact-metadata-cutover.php convert --apply=' . COMPACT_CUTOVER_CONVERT_TOKEN
            . ' --batch-size=25 --max-files=25';
        $result['unlimited_conversion_command'] =
            'php catalog/bin/compact-metadata-cutover.php convert --apply=' . COMPACT_CUTOVER_CONVERT_TOKEN
            . ' --batch-size=25 --max-files=0 --continue-on-error';
        $result['recommended_first_cleanup_command'] =
            'php catalog/bin/compact-metadata-cutover.php cleanup --apply=' . COMPACT_CUTOVER_CLEANUP_TOKEN
            . ' --batch-size=10 --max-files=10';
        $result['unlimited_cleanup_command'] =
            'php catalog/bin/compact-metadata-cutover.php cleanup --apply=' . COMPACT_CUTOVER_CLEANUP_TOKEN
            . ' --batch-size=10 --max-files=0 --continue-on-error';
    } elseif ($arguments['phase'] === 'convert') {
        $result = $service->convert(
            $arguments['batch_size'],
            $arguments['max_files'],
            $arguments['continue_on_error'],
            $progress
        );
    } else {
        $result = $service->cleanup(
            $arguments['batch_size'],
            $arguments['max_files'],
            $arguments['continue_on_error'],
            $progress
        );
    }

    fwrite(
        STDOUT,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . PHP_EOL
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact metadata cutover failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
