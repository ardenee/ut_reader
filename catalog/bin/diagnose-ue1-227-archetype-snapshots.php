#!/usr/bin/env php
<?php
/**
 * Read-only production reconciliation of Unreal v227 archetype metadata.
 * No files, SQL tables, source originals, or UEDB5 snapshots are changed.
 *
 * Run locally on PRD after deploying the UE1 source reader/schema changes:
 *   php catalog/bin/diagnose-ue1-227-archetype-snapshots.php
 *   php catalog/bin/diagnose-ue1-227-archetype-snapshots.php --file-id=1234
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

require_once dirname(__DIR__) . '/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

$options = getopt('', ['file-id::']);
$fileId = max(0, (int)($options['file-id'] ?? 0));
$app = catalog_bootstrap();
$config = catalog_config();
$storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
if ($storage === '') { throw new RuntimeException('Storage path not configured.'); }
$directory = $storage . '/games/unrealgold/verified';
$metadata = new Uedb5MetadataReader($storage);
$app->db->exec('SET SESSION TRANSACTION READ ONLY');
$app->db->beginTransaction();
$checks = ['candidates'=>0,'no_archetype_fields'=>0,'current'=>0,'needs_resnapshot'=>0,'blocked'=>0];
try {
    $statement = $app->db->prepare(
        'SELECT id,stored_name,file_size,md5,sha1 FROM ue_files '
        . 'WHERE game_id=12 AND scan_status="verified" '
        . 'AND package_version=69 AND licensee_version=227 '
        . ($fileId > 0 ? 'AND id=? ' : '')
        . 'ORDER BY id'
    );
    $statement->execute($fileId > 0 ? [$fileId] : []);
    while (($file = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $checks['candidates']++;
        $id = (int)$file['id'];
        try {
            $stored = (string)$file['stored_name'];
            if ($stored === '' || basename($stored) !== $stored) {
                throw new RuntimeException('Invalid verified source name.');
            }
            $source = $directory . '/' . $stored;
            if (!is_file($source) || filesize($source) !== (int)$file['file_size']
                || !hash_equals(strtolower((string)$file['md5']), strtolower((string)md5_file($source)))
                || !hash_equals(strtolower((string)$file['sha1']), strtolower((string)sha1_file($source)))) {
                throw new RuntimeException('Original source bytes do not match catalogued file identity.');
            }
            $reader = new CatalogUE1PackageReader($source, true);
            if ($reader->validatePackage() !== []) {
                throw new RuntimeException(implode('; ', $reader->validatePackage()));
            }
            $exports = $reader->getExports();
            $flagged = [];
            foreach ($exports as $offset => $export) {
                if (((int)$export['objectFlags'] & 0x100) !== 0) {
                    $flagged[] = ['offset'=>$offset, 'archetype'=>(int)$export['archetypeIndex']];
                }
            }
            if ($flagged === []) { $checks['no_archetype_fields']++; continue; }
            if (($reader->getHeader()['unreal227ExportLayout'] ?? '') !==
                'native_227k_archetype_int32_structurally_discriminated') {
                throw new RuntimeException('Archetype layout not structurally confirmed.');
            }

            $schema = '';
            $firstMismatch = null;
            try {
                $manifest = $metadata->manifest(12, $id);
                $schema = (string)($manifest['section_schemas']['exports'] ?? '');
                if ($schema === 'ue1.unreal.object-export.v3') {
                    foreach ($flagged as $entry) {
                        $row = $metadata->page(12, $id, 'exports', $entry['offset'], 1)[0] ?? null;
                        if (!is_array($row) || ($row['archetype_index_present'] ?? false) !== true
                            || (int)($row['archetype_index'] ?? PHP_INT_MIN) !== $entry['archetype']) {
                            $firstMismatch = $entry['offset'];
                            break;
                        }
                    }
                }
            } catch (Throwable $error) {
                $schema = 'missing_or_unreadable';
                $firstMismatch = $error->getMessage();
            }
            $current = $schema === 'ue1.unreal.object-export.v3' && $firstMismatch === null;
            $checks[$current ? 'current' : 'needs_resnapshot']++;
            echo json_encode([
                'file_id'=>$id, 'status'=>$current ? 'CURRENT' : 'NEEDS_PASS1_RESNAPSHOT',
                'flagged_exports'=>count($flagged), 'stored_schema'=>$schema,
                'first_mismatch'=>$firstMismatch,
                'target_command'=>$current ? null
                    : 'php catalog/bin/migrate-uedb5-game.php --game-id=12 --file-id=' . $id . ' --apply',
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        } catch (Throwable $error) {
            $checks['blocked']++;
            echo json_encode(['file_id'=>$id,'status'=>'BLOCKED',
                'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        }
    }
    echo json_encode(['summary'=>$checks, 'mode'=>'READ_ONLY_NO_CHANGES'],
        JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $app->db->rollBack();
}
exit($checks['blocked'] ? 3 : ($checks['needs_resnapshot'] ? 2 : 0));
