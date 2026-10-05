#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

$options = getopt('', ['game:', 'file-id:', 'source-index:', 'storage-path::']);
$slug = trim((string)($options['game'] ?? ''));
$fileId = (int)($options['file-id'] ?? 0);
$sourceIndex = (int)($options['source-index'] ?? -1);
if ($slug === '' || $fileId < 1 || $sourceIndex < 0) {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-uedb5-provider-parity.php --game=ut2004 --file-id=140318 --source-index=9\n");
    exit(2);
}

$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storageOverride = trim((string)($options['storage-path'] ?? ''));
if ($storageOverride !== '') { $config['storage_path'] = $storageOverride; }
$storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
$reader = new Uedb5MetadataReader($storage);
$v5Reader = new Uedb5ParityV5ReadService($db, $config);

try {
    $game = $db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1');
    $game->execute([$slug]);
    $gameRow = $game->fetch(PDO::FETCH_ASSOC);
    if (!is_array($gameRow)) { throw new RuntimeException('Unknown game slug: ' . $slug); }
    $gameId = (int)$gameRow['id'];

    $v4 = $db->prepare(
        'SELECT l.status,l.resolved_file_id,l.resolved_export_index,'
        . 'CONVERT(p.value_prefix USING utf8mb4) required_package,'
        . 'CONVERT(o.value_prefix USING utf8mb4) import_object '
        . 'FROM ue_dependency_links l '
        . 'LEFT JOIN ue_terms p ON p.id=l.required_package_term_id '
        . 'LEFT JOIN ue_terms o ON o.id=l.import_object_term_id '
        . 'WHERE l.file_id=? AND l.import_index=? LIMIT 1'
    );
    $v4->execute([$fileId, $sourceIndex]);
    $v4Row = $v4->fetch(PDO::FETCH_ASSOC);
    if (!is_array($v4Row)) { throw new RuntimeException('V4 dependency row was not found.'); }

    $v5 = $db->prepare(
        'SELECT source_kind,source_index,outcome,required_package_key_kind,required_package_key,'
        . 'required_object_key_kind,required_object_key,resolved_file_id,resolved_object_kind,resolved_object_index '
        . 'FROM ue_uedb5_dependency_edges WHERE file_id=? AND source_kind=1 AND source_index=? LIMIT 1'
    );
    $v5->execute([$fileId, $sourceIndex]);
    $v5Edge = $v5->fetch(PDO::FETCH_ASSOC);
    if (!is_array($v5Edge)) { throw new RuntimeException('V5 dependency edge was not found.'); }
    $v5Hydrated = (array)($v5Reader->dependenciesByIndex($gameId, $fileId)[$sourceIndex] ?? []);

    $requiredPackage = trim((string)($v4Row['required_package'] ?? ''));
    $v4Candidates = [];
    if ($requiredPackage !== '') {
        $q = $db->prepare(
            'SELECT p.file_id,p.source_kind,p.source_id,p.provider_created_at,f.uploaded_at,f.package_name,f.original_name '
            . 'FROM ue_package_providers p JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias" AND a.id=p.source_id '
            . 'AND a.file_id=p.file_id AND a.game_id=p.game_id AND a.package_name=p.package_name '
            . 'WHERE p.game_id=? AND f.scan_status="verified" AND p.package_name=? '
            . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
            . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
            . 'AND ((p.source_kind="primary" AND f.package_name=p.package_name) OR (p.source_kind="alias" AND a.id IS NOT NULL)) '
            . 'ORDER BY (p.source_kind="primary") DESC,(p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC'
        );
        $q->execute([$gameId, $requiredPackage, $fileId]);
        $seen = [];
        while (($row = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int)$row['file_id'];
            if ($id < 1 || isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $row['rank'] = count($v4Candidates) + 1;
            $v4Candidates[] = $row;
        }
    }

    $v5Candidates = [];
    $kind = (int)($v5Edge['required_package_key_kind'] ?? 0);
    $key = (string)($v5Edge['required_package_key'] ?? '');
    if ($kind > 0 && $key !== '') {
        $q = $db->prepare(
            'SELECT p.file_id,p.source_kind,p.source_id,f.uploaded_at,f.package_name,f.original_name '
            . 'FROM ue_uedb5_provider_keys p '
            . 'JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'WHERE p.game_id=? AND p.package_key_kind=? AND p.package_key=? AND f.scan_status="verified" '
            . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
            . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
            . 'ORDER BY (p.source_kind=1) DESC,(p.file_id=?) DESC,f.uploaded_at DESC,p.source_id ASC,p.file_id ASC'
        );
        $q->execute([$gameId, $kind, $key, $fileId]);
        $seen = [];
        while (($row = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int)$row['file_id'];
            if ($id < 1 || isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $row['rank'] = count($v5Candidates) + 1;
            $v5Candidates[] = $row;
        }
    }

    $candidateIds = [];
    foreach (array_merge($v4Candidates, $v5Candidates) as $row) { $candidateIds[(int)$row['file_id']] = true; }
    foreach ([$v4Row['resolved_file_id'] ?? null, $v5Edge['resolved_file_id'] ?? null] as $id) {
        if ($id !== null && (int)$id > 0) { $candidateIds[(int)$id] = true; }
    }
    $details = [];
    $fileQuery = $db->prepare(
        'SELECT id,package_name,original_name,uploaded_at,file_size,md5,sha1,package_version,scan_status '
        . 'FROM ue_files WHERE id=? AND game_id=? LIMIT 1'
    );
    foreach (array_keys($candidateIds) as $candidateId) {
        $fileQuery->execute([(int)$candidateId, $gameId]);
        $file = $fileQuery->fetch(PDO::FETCH_ASSOC) ?: ['id'=>(int)$candidateId];
        try {
            $manifest = $reader->manifest($gameId, (int)$candidateId);
            $file['uedb5_source_policy'] = (string)($manifest['source_policy'] ?? '');
            $file['uedb5_counts'] = (array)($manifest['counts'] ?? []);
        } catch (Throwable $error) {
            $file['uedb5_error'] = $error->getMessage();
        }
        $details[] = $file;
    }

    echo json_encode([
        'ok'=>true,
        'read_only'=>true,
        'game'=>$gameRow,
        'consumer_file_id'=>$fileId,
        'source_index'=>$sourceIndex,
        'v4_dependency'=>[
            'outcome'=>(int)$v4Row['status'],
            'resolved_file_id'=>$v4Row['resolved_file_id'] !== null ? (int)$v4Row['resolved_file_id'] : null,
            'resolved_export_index'=>$v4Row['resolved_export_index'] !== null ? (int)$v4Row['resolved_export_index'] : null,
            'required_package'=>$requiredPackage,
            'import_object'=>(string)($v4Row['import_object'] ?? ''),
        ],
        'v5_edge'=>[
            'outcome'=>(int)$v5Edge['outcome'],
            'resolved_file_id'=>$v5Edge['resolved_file_id'] !== null ? (int)$v5Edge['resolved_file_id'] : null,
            'resolved_object_index'=>$v5Edge['resolved_object_index'] !== null ? (int)$v5Edge['resolved_object_index'] : null,
            'required_package_key_kind'=>$kind,
            'required_package_key_hex'=>$key === '' ? null : strtoupper(bin2hex($key)),
        ],
        'v5_hydrated_dependency'=>$v5Hydrated,
        'v4_provider_cache_order'=>$v4Candidates,
        'v5_provider_key_order'=>$v5Candidates,
        'candidate_files'=>$details,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
