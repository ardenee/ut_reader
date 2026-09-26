#!/usr/bin/env php
<?php
/** Read-only diagnostic for game-scoped ClassRemap and unresolved UE1/UE2 imports. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassRemapRepository;
$options = getopt('', ['game-id:', 'max-files::']);
$gameId = (int)($options['game-id'] ?? 0);
$maxFiles = max(1, min(10000, (int)($options['max-files'] ?? 1000)));
if ($gameId < 1) { fwrite(STDERR, "Usage: php catalog/bin/diagnose-class-remap.php --game-id=ID [--max-files=1000]\n"); exit(2); }
$config = catalog_config();
$db = catalog_db($config);
$remaps = (new PdoClassRemapRepository($db))->mappingsForGame($gameId);
$key = static fn(string $v): string => function_exists('mb_strtolower') ? mb_strtolower(trim($v), 'UTF-8') : strtolower(trim($v));
$stmt = $db->prepare('SELECT DISTINCT l.file_id FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 ORDER BY l.file_id LIMIT ' . $maxFiles);
$stmt->execute([$gameId]);
$fileIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
$loader = new BlockedCompressedMetadataSnapshotLoader($db, (string)($config['storage_path'] ?? ($root . '/storage')));
$details = [];
foreach ($fileIds as $fileId) {
    $snapshot = $loader->loadDependencySnapshot($fileId);
    $imports = array_values((array)($snapshot['imports'] ?? []));
    $dependencies = array_values((array)($snapshot['dependencies'] ?? []));
    $missingIndexes = [];
    foreach ($dependencies as $dependency) {
        if (!is_array($dependency)) continue;
        if ((string)($dependency['status'] ?? '') === 'missing') {
            $missingIndexes[(int)($dependency['import_index'] ?? -1)] = true;
        }
    }
    $unresolved = [];
    foreach ($imports as $fallback => $import) {
        if (!is_array($import)) continue;
        $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
        if (!isset($missingIndexes[$importIndex])) continue;
        $className = trim((string)($import['class_name'] ?? ''));
        $objectName = trim((string)($import['object_name'] ?? ''));
        $unresolved[] = [
            'import_id' => (int)($import['id'] ?? 0),
            'import_index' => $importIndex,
            'root_package' => (string)($import['root_package'] ?? ''),
            'relative_object_path' => (string)($import['relative_object_path'] ?? ''),
            'full_path' => (string)($import['full_path'] ?? ''),
            'object_name' => $objectName,
            'class_name' => $className,
            'class_package' => (string)($import['class_package'] ?? ''),
            'outer_index' => (int)($import['outer_index'] ?? 0),
            'object_name_remap' => $remaps[$key($objectName)] ?? null,
            'class_name_remap' => $remaps[$key($className)] ?? null,
        ];
    }
    $details[] = [
        'consumer_file_id' => $fileId,
        'consumer_package' => (string)($snapshot['file']['package_name'] ?? ''),
        'missing_dependency_count' => count($unresolved),
        'unresolved_imports' => $unresolved,
    ];
}
echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'configured_remaps' => $remaps,
    'missing_consumer_files_checked' => count($fileIds),
    'details' => $details,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
