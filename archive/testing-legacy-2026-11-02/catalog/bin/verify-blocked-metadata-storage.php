#!/usr/bin/env php
<?php
/** Read-only V5 metadata integrity check for one catalogue file. */
declare(strict_types=1);
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
$fileId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--file-id=([1-9][0-9]*)$/', $arg, $match)) {
        $fileId = (int)$match[1];
    } else {
        fwrite(STDERR, "Usage: php verify-blocked-metadata-storage.php --file-id=ID\n");
        exit(1);
    }
}
if ($fileId < 1) {
    fwrite(STDERR, "Specify --file-id; no full catalogue scans are performed.\n");
    exit(1);
}
try {
    $config = catalog_config();
    $db = catalog_db($config);
    $query = $db->prepare('SELECT f.id,f.game_id,f.name_count,f.import_count,f.export_count,'
        . 'v.format_version,v.payload_sha256,v.section_counts_json,v.compressed_size '
        . 'FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
        . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1');
    $query->execute([$fileId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int)$row['format_version'] !== Uedb5MetadataContainer::FORMAT_VERSION) {
        throw new RuntimeException('File is not verified and registered as UEDB5.');
    }
    $storage = (string)$config['storage_path'];
    $reader = new Uedb5MetadataReader($storage);
    $verified = $reader->verify((int)$row['game_id'], $fileId, (string)$row['payload_sha256']);
    $sectionCounts = json_decode((string)$row['section_counts_json'], true, 512, JSON_THROW_ON_ERROR);
    foreach (['names'=>'name_count','imports'=>'import_count','exports'=>'export_count'] as $section=>$column) {
        if (isset($sectionCounts[$section]) && (int)$sectionCounts[$section] !== (int)$row[$column]) {
            throw new RuntimeException('Section count mismatch: ' . $section);
        }
    }
    if ((int)$row['compressed_size'] !== (int)$verified['compressed_size']) {
        throw new RuntimeException('UEDB5 container size mismatch.');
    }
    echo json_encode(['ok'=>true,'read_only'=>true,'file_id'=>$fileId,
        'game_id'=>(int)$row['game_id'],'format_version'=>5,
        'section_counts'=>$sectionCounts, 'block_count'=>(int)$verified['block_count']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'UEDB5 storage verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
