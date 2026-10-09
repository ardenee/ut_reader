#!/usr/bin/env php
<?php
/** Repair only a missing V5 SQL object projection for an explicitly selected file. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';
$options = getopt('', ['file-id:', 'apply']);
$fileId = (int)($options['file-id'] ?? 0);
if ($fileId < 1) {
    fwrite(STDERR, "Usage: php repair-uedb5-object-projection.php --file-id=N --apply\n");
    exit(1);
}
$app = catalog_bootstrap();
$db = $app->db;
$q = $db->prepare(
    'SELECT v.game_id,s.status,(SELECT COUNT(*) FROM ue_uedb5_object_candidates o WHERE o.file_id=v.file_id) object_count '
    . 'FROM ue_uedb5_files v JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id WHERE v.file_id=?'
);
$q->execute([$fileId]);
$before = $q->fetch(PDO::FETCH_ASSOC);
if (!is_array($before)) {
    fwrite(STDERR, "No V5 registration and status for requested file.\n");
    exit(2);
}
if (!isset($options['apply'])) {
    echo json_encode(['ok'=>true,'read_only'=>true,'file_id'=>$fileId,'registration'=>$before],JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}
try {
    $result = (new \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MissingObjectProjectionRepair($db,catalog_config()))
        ->repairFile($fileId);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['ok'=>false,'file_id'=>$fileId,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(2);
}
