#!/usr/bin/env php
<?php
/**
 * Atomic database cutover from staged .uedb3 files to v3-only runtime metadata.
 *
 * Default is read-only. --apply requires --confirm-v3-only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/lib/CatalogSupport.php';
require_once __DIR__ . '/MetadataContainerV3.php';

use UnrealDb\Catalog\MigrationV3\MetadataContainerV3;

$options = getopt('', ['apply', 'confirm-v3-only', 'max-errors::']);
$apply = array_key_exists('apply', $options);
$confirmed = array_key_exists('confirm-v3-only', $options);
$maxErrors = max(1, min(1000, (int)($options['max-errors'] ?? 100)));

if ($apply && !$confirmed) {
    fwrite(STDERR, "--apply requires --confirm-v3-only.\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    fwrite(STDERR, "catalog storage_path is not configured.\n");
    exit(2);
}

$runningJobs = (int)$db->query(
    'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
)->fetchColumn();
if ($runningJobs > 0) {
    fwrite(STDERR, "Cutover refused: {$runningJobs} background job(s) are still running. Stop workers/jobs first.\n");
    exit(3);
}

$db->exec(
    'CREATE TEMPORARY TABLE tmp_v3_cutover ('
    . 'file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
    . 'compressed_size BIGINT UNSIGNED NOT NULL,'
    . 'uncompressed_size BIGINT UNSIGNED NOT NULL,'
    . 'payload_sha256 BINARY(32) NOT NULL,'
    . 'name_count INT UNSIGNED NOT NULL,'
    . 'import_count INT UNSIGNED NOT NULL,'
    . 'export_count INT UNSIGNED NOT NULL'
    . ') ENGINE=InnoDB'
);
$insert = $db->prepare(
    'INSERT INTO tmp_v3_cutover '
    . '(file_id,compressed_size,uncompressed_size,payload_sha256,name_count,import_count,export_count) '
    . 'VALUES (?,?,?,?,?,?,?)'
);
$files = $db->query(
    'SELECT f.id,f.game_id,f.original_name,f.name_count,f.import_count,f.export_count,'
    . 'COALESCE(m.format_version,0) format_version '
    . 'FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
    . 'WHERE f.scan_status="verified" ORDER BY f.id'
);

$checked = 0;
$errors = [];
while (($file = $files->fetch(PDO::FETCH_ASSOC)) !== false) {
    $fileId = (int)$file['id'];
    $gameId = (int)$file['game_id'];
    try {
        $path = MetadataContainerV3::path($storageRoot, $gameId, $fileId);
        $verified = MetadataContainerV3::verifyFile($path, $fileId);
        $manifest = (array)($verified['manifest'] ?? []);
        if ((int)($manifest['format_version'] ?? 0) !== 3) {
            throw new RuntimeException('manifest format_version is not 3');
        }
        if ((int)($manifest['file']['game_id'] ?? 0) !== $gameId) {
            throw new RuntimeException('manifest game_id mismatch');
        }
        $counts = (array)($manifest['counts'] ?? []);
        $nameCount = (int)($counts['names'] ?? -1);
        $importCount = (int)($counts['imports'] ?? -1);
        $exportCount = (int)($counts['exports'] ?? -1);
        $dependencyCount = (int)($counts['dependencies'] ?? -1);
        if ($nameCount !== (int)$file['name_count']
            || $importCount !== (int)$file['import_count']
            || $exportCount !== (int)$file['export_count']
            || $dependencyCount !== $importCount) {
            throw new RuntimeException(
                'manifest count mismatch: names=' . $nameCount . '/' . (int)$file['name_count']
                . ', imports=' . $importCount . '/' . (int)$file['import_count']
                . ', exports=' . $exportCount . '/' . (int)$file['export_count']
                . ', dependencies=' . $dependencyCount . '/' . $importCount
            );
        }
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('could not reopen v3 container header');
        }
        try {
            $headerBytes = fread($stream, 20);
        } finally {
            fclose($stream);
        }
        if (!is_string($headerBytes) || strlen($headerBytes) !== 20) {
            throw new RuntimeException('could not read complete v3 container header');
        }
        $header = unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', $headerBytes);
        $uncompressedSize = (int)($header['manifest_length'] ?? 0);
        foreach ((array)($manifest['sections'] ?? []) as $blocks) {
            foreach ((array)$blocks as $block) {
                $uncompressedSize += (int)($block['uncompressed_length'] ?? 0);
            }
        }
        $insert->execute([
            $fileId,
            (int)$verified['compressed_size'],
            $uncompressedSize,
            (string)$verified['payload_sha256'],
            $nameCount,
            $importCount,
            $exportCount,
        ]);
        $checked++;
    } catch (Throwable $error) {
        if (count($errors) < $maxErrors) {
            $errors[] = [
                'file_id' => $fileId,
                'game_id' => $gameId,
                'file' => (string)$file['original_name'],
                'error' => $error->getMessage(),
            ];
        }
    }
}

$verifiedCount = (int)$db->query(
    'SELECT COUNT(*) FROM ue_files WHERE scan_status="verified"'
)->fetchColumn();
$stagedCount = (int)$db->query('SELECT COUNT(*) FROM tmp_v3_cutover')->fetchColumn();
$complete = $errors === [] && $checked === $verifiedCount && $stagedCount === $verifiedCount;

$result = [
    'ok' => $complete,
    'dry_run' => !$apply,
    'verified_files' => $verifiedCount,
    'v3_verified' => $checked,
    'v3_staged_rows' => $stagedCount,
    'running_jobs' => $runningJobs,
    'errors' => $errors,
];

if (!$complete) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(4);
}
if (!$apply) {
    $result['next_command'] = 'php catalog/bin/v3-migration/cutover.php --apply --confirm-v3-only';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $updated = $db->exec(
        'UPDATE ue_file_metadata m JOIN tmp_v3_cutover t ON t.file_id=m.file_id SET '
        . 'm.format_version=3,m.codec=2,m.compressed_size=t.compressed_size,'
        . 'm.uncompressed_size=t.uncompressed_size,m.payload_sha256=t.payload_sha256,'
        . 'm.name_count=t.name_count,m.import_count=t.import_count,m.export_count=t.export_count,'
        . 'm.updated_at=NOW()'
    );
    $remaining = (int)$db->query(
        'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>3)'
    )->fetchColumn();
    if ($remaining !== 0) {
        throw new RuntimeException('Atomic cutover left ' . $remaining . ' verified file(s) without v3 registration.');
    }
    $db->commit();
    $result['database_rows_updated'] = (int)$updated;
    $result['cutover_complete'] = true;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $result['ok'] = false;
    $result['cutover_complete'] = false;
    $result['error'] = $error->getMessage();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(5);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
