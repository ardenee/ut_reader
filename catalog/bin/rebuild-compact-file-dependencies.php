#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyRebuilder;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return array{file_id:int,apply:bool} */
function compact_dependency_rebuild_arguments(array $arguments): array
{
    $result = ['file_id' => 0, 'apply' => false];
    foreach ($arguments as $argument) {
        $argument = trim((string)$argument);
        if ($argument === '--apply') {
            $result['apply'] = true;
            continue;
        }
        if (str_starts_with($argument, '--file-id=')) {
            $result['file_id'] = max(0, (int)substr($argument, strlen('--file-id=')));
            continue;
        }
        if (in_array($argument, ['--help', '-h', 'help'], true)) {
            fwrite(STDOUT, "Usage:\n");
            fwrite(STDOUT, "  php catalog/bin/rebuild-compact-file-dependencies.php [--file-id=ID] [--apply]\n\n");
            fwrite(STDOUT, "Without --apply, the command only reports the selected compact file.\n");
            exit(0);
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }
    return $result;
}

try {
    $arguments = compact_dependency_rebuild_arguments(array_slice($argv, 1));
    $config = catalog_config();
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }
    $db = catalog_db($config);

    if ($arguments['file_id'] > 0) {
        $statement = $db->prepare(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.import_count,f.export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$arguments['file_id']]);
    } else {
        $statement = $db->query(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.import_count,f.export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND f.import_count BETWEEN 1 AND 100 '
            . 'ORDER BY f.import_count,f.export_count,f.id LIMIT 1'
        );
    }
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) {
        throw new RuntimeException('No matching verified format-2 file was found.');
    }

    $selection = [
        'selected_file_id' => (int)$file['id'],
        'game_id' => (int)$file['game_id'],
        'package_name' => (string)$file['package_name'],
        'original_name' => (string)$file['original_name'],
        'import_count' => (int)$file['import_count'],
        'export_count' => (int)$file['export_count'],
        'apply_requested' => $arguments['apply'],
    ];

    if (!$arguments['apply']) {
        $selection['changed'] = false;
        $selection['next_command'] = 'php catalog/bin/rebuild-compact-file-dependencies.php --file-id=' . (int)$file['id'] . ' --apply';
        fwrite(STDOUT, json_encode($selection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $result = (new CompactDependencyRebuilder($db, $storageRoot))->rebuild((int)$file['id']);
    fwrite(
        STDOUT,
        json_encode(array_merge($selection, $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact dependency rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
