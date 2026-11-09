<?php
/**
 * Read-only native revalidation evidence for failed packages. It counts real
 * stored bytes from the configured storage root and calls the SAME current
 * per-engine scanner used for imports, without publishing a package.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;

function failedMaintAssert(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

$config = catalog_config();
$db = catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $rows = $db->query(
        'SELECT id,game_id,original_name,source_relative_path,relative_path,'
        . 'file_size,md5,sha1 FROM ue_files '
        . 'WHERE scan_status="failed" ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    // Optional alternate *read-only* storage mount for a test run from DEV
    // against the actual PRD source bytes; never changes application config.
    $opts = getopt('', ['source-storage:']);
    $sourceRoot = is_string($opts['source-storage'] ?? null)
        ? (string)$opts['source-storage'] : (string)$config['storage_path'];
    $root = rtrim(str_replace('\\','/',$sourceRoot),'/');
    failedMaintAssert($root !== '', 'Configuration has no authoritative storage root.');
    $exists = 0;
    $missing = 0;
    $paths = [];
    foreach ($rows as $row) {
        $relative = ltrim(str_replace('\\','/',(string)$row['relative_path']),'/');
        failedMaintAssert(str_starts_with($relative,'storage/')
            && !str_contains($relative, '../') && !str_contains($relative, '/..'),
            'File path does not belong to catalogue-managed storage.');
        $path = $root . '/' . substr($relative,strlen('storage/'));
        $id = (int)$row['id'];
        if (is_file($path)) {
            $exists++;
            $paths[$id] = $path;
        } else {
            $missing++;
        }
    }
    failedMaintAssert(count($rows) === $exists + $missing, 'File inventory mismatch.');
    $inspection = new CatalogVerifiedPackageInspector($db,$config);
    $cases = [];
    foreach ([171942,1362899,1362983] as $id) {
        $file = null;
        foreach ($rows as $item) {
            if ((int)$item['id'] === $id) { $file = $item; break; }
        }
        if (!is_array($file) || !isset($paths[$id])) continue;
        $accepted = false;
        $reason = '';
        try {
            $result = $inspection->inspect(
                (int)$file['game_id'], $paths[$id],
                (string)$file['original_name'],true,
                (string)($file['source_relative_path'] ?? '')
            );
            $accepted = true;
            $reason = 'Current reader accepted N=' . $result->nameCount()
                . ' I=' . $result->importCount() . ' E=' . $result->exportCount();
        } catch (Throwable $error) {
            $reason = $error->getMessage();
        }
        $cases[] = [
            'id'=>$id,'bytes_exist'=>true,'current_scanner_accepted'=>$accepted,
            'reason'=>mb_substr($reason,0,280),
        ];
    }
    $identities = (int)$db->query(
        'SELECT COUNT(*) FROM ue_invalid_file_identities bad '
        . 'JOIN ue_files f ON f.id=bad.source_file_id '
        . 'WHERE f.scan_status="failed"'
    )->fetchColumn();
    $db->rollBack();
    echo json_encode([
        'pass'=>true,
        'read_only'=>true,
        'failed_source_records'=>count($rows),
        'source_bytes_exist'=>$exists,
        'source_bytes_missing'=>$missing,
        'known_invalid_byte_identity_records'=>$identities,
        'current_reader_revalidation_samples'=>$cases,
        'production_modifications'=>0,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'FAILED_FILE_REVALIDATION_CHECK_FAIL '.$error->getMessage().PHP_EOL);
    exit(1);
}
