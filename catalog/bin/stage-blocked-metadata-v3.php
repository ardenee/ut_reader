#!/usr/bin/env php
<?php
/**
 * Build verified format-3 .uedb2 candidates beside the live format-2 catalog.
 *
 * This command never updates ue_file_metadata and never replaces the live
 * metadata container. Production can therefore continue serving v2 while v3
 * candidates are generated and verified.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';
require_once $root . '/lib/CatalogFileMaintenance.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;

$options = getopt('', ['apply','all','limit:','after-id:','game-id:','file-ids:','stop-on-error']);
$apply = array_key_exists('apply', $options);
$all = array_key_exists('all', $options);
$limit = max(1, min(10000, (int)($options['limit'] ?? 500)));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$gameId = max(0, (int)($options['game-id'] ?? 0));
$stopOnError = array_key_exists('stop-on-error', $options);
$rawIds = trim((string)($options['file-ids'] ?? ''));

$config = catalog_config();
$db = catalog_db($config);
if (BlockedCompressedMetadataContainer::FORMAT_VERSION !== 3) {
    throw new RuntimeException('The staging command requires the format-3 container implementation.');
}
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') { throw new RuntimeException('catalog storage_path is not configured.'); }

$where = ['f.scan_status="verified"', 'm.format_version=2', 'f.id>?'];
$args = [$afterId];
if ($gameId > 0) { $where[] = 'f.game_id=?'; $args[] = $gameId; }
if ($rawIds !== '') {
    $ids = [];
    foreach (preg_split('/[\\s,;]+/', $rawIds) ?: [] as $value) {
        $id = (int)$value; if ($id > 0) { $ids[$id] = $id; }
    }
    if ($ids === []) { throw new RuntimeException('No positive --file-ids were supplied.'); }
    $where[] = 'f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    array_push($args, ...array_values($ids));
}
$sql = 'SELECT f.*,m.format_version FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id WHERE '
    . implode(' AND ', $where) . ' ORDER BY f.id' . ($all ? '' : ' LIMIT ' . $limit);
$stmt = $db->prepare($sql); $stmt->execute($args); $files = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$apply) {
    echo json_encode(['ok'=>true,'dry_run'=>true,'selected'=>count($files),
        'first_file_id'=>$files ? (int)$files[0]['id'] : 0,
        'last_file_id'=>$files ? (int)$files[array_key_last($files)]['id'] : 0,
        'staging_suffix'=>'.v3-stage'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$inspector = new CatalogVerifiedPackageInspector($db, $config);
$builder = new CatalogParsedPackageMetadataSnapshotBuilder($db, $config);
$completed=0; $failed=0; $last=$afterId; $errors=[]; $started=microtime(true);

foreach ($files as $n=>$file) {
    $fileId=(int)$file['id']; $label=(string)$file['original_name'];
    fwrite(STDOUT, '['.($n+1).'/'.count($files)."] #{$fileId} {$label} ... ");
    try {
        $source = catalog_file_maintenance_storage_path($config, $file);
        if ($source === null || !is_file($source)) {
            throw new RuntimeException('Authoritative stored package is missing.');
        }
        $sourceRelative = '';
        try {
            $sourceRelative = catalog_file_maintenance_source_relative_path(
                (new \UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport($db,$config))->reimportState($fileId)
            );
        } catch (Throwable) {}
        $inspection = $inspector->inspect(
            (int)$file['game_id'], $source, (string)$file['original_name'], false, $sourceRelative, null
        );
        if (!hash_equals(strtolower((string)$file['md5']), strtolower($inspection->md5))) {
            throw new RuntimeException('Stored package MD5 no longer matches catalog identity.');
        }
        $snapshot = $builder->build(
            $fileId, (int)$file['game_id'], (string)$file['package_name'], (string)$file['original_name'],
            $inspection->names, $inspection->imports, $inspection->exports
        );
        $livePath = BlockedCompressedMetadataContainer::path($storageRoot,(int)$file['game_id'],$fileId);
        $stagePath = $livePath . '.v3-stage';
        $tmp = $stagePath . '.tmp.' . bin2hex(random_bytes(6));
        try {
            $built = BlockedCompressedMetadataContainer::buildToFile($snapshot,$tmp);
            if (!rename($tmp,$stagePath)) { throw new RuntimeException('Could not publish staged v3 container.'); }
        } finally { @unlink($tmp); }
        $verified = BlockedCompressedMetadataContainer::verifyFile(
            $stagePath,$fileId,(string)$built['payload_sha256'],3
        );
        $sidecar = [
            'file_id'=>$fileId,'game_id'=>(int)$file['game_id'],'source_md5'=>(string)$file['md5'],
            'format_version'=>3,'compressed_size'=>(int)$built['compressed_size'],
            'uncompressed_size'=>(int)$built['uncompressed_size'],
            'payload_sha256_hex'=>bin2hex((string)$built['payload_sha256']),
            'block_count'=>(int)$verified['block_count'],'created_at'=>gmdate('c')
        ];
        file_put_contents($stagePath.'.json', json_encode($sidecar,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
        $completed++; $last=$fileId; fwrite(STDOUT,"v3 STAGED\n");
    } catch (Throwable $e) {
        $failed++; $errors[]=['file_id'=>$fileId,'file'=>$label,'error'=>$e->getMessage()];
        fwrite(STDOUT,'FAILED: '.$e->getMessage()."\n"); if ($stopOnError) break;
    }
}
$elapsed=max(.001,microtime(true)-$started);
echo json_encode(['ok'=>$failed===0,'selected'=>count($files),'completed'=>$completed,'failed'=>$failed,
    'last_completed_id'=>$last,'elapsed_seconds'=>round($elapsed,2),
    'files_per_second'=>round($completed/$elapsed,3),'errors'=>$errors],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed===0?0:3);
