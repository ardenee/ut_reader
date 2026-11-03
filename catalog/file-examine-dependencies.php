<?php
/**
 * Lightweight Uses / Used By relationships for file-examine.php.
 *
 * Uses only the compact SQL dependency projection. It deliberately does not
 * hydrate per-import dependency details from .uedb3; the examiner needs file
 * relationships here, not the full dependency-detail payload used by file-info.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDependencySchema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_dependency_schema_ensure($db);
    $fileId = max(0, (int)($_GET['id'] ?? 0));

    $file = catalog_one($db, 'SELECT id,game_id FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1', [$fileId]);
    if (!$file) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Verified file not found.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $health = catalog_one(
        $db,
        'SELECT p.engine_key,v.source_policy,v.payload_sha256,s.dependency_policy,s.dependency_payload_sha256 '
        . 'FROM ue_uedb5_files v JOIN ue_games g ON g.id=v.game_id '
        . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
        . 'LEFT JOIN ue_uedb5_migration_status s '
        . 'ON s.file_id=v.file_id AND s.game_id=v.game_id '
        . 'WHERE v.file_id=? AND v.game_id=? LIMIT 1',
        [$fileId, (int)$file['game_id']]
    );
    $policyClass = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService::class;
    $expectedPolicy = is_array($health)
        ? $policyClass::policyForEngine((string)($health['engine_key'] ?? '')) : '';
    if (!is_array($health)
        || (string)($health['dependency_policy']??'') !== $expectedPolicy
        || !hash_equals((string)($health['payload_sha256']??''),(string)($health['dependency_payload_sha256']??''))) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'stale_dependency_projection'=>true,'requires'=>[],'required_by'=>[],
            'error'=>'Dependency results need source-correct regeneration; old resolved/missing links are not verified.'],
            JSON_THROW_ON_ERROR);
        exit;
    }

    // Required By spans other games. The publishing game profile, not a
    // serialized source_policy prefix, selects its source-correct epoch.
    // This matters for UE2's own UE1-wire compatibility packages.
    $enginePolicySql = 'CASE UPPER(ep.engine_key)';
    foreach (['UE1','UE2','UE3','UE4','UE5'] as $engine) {
        $enginePolicySql .= ' WHEN ' . $db->quote($engine) . ' THEN '
            . $db->quote($policyClass::policyForEngine($engine));
    }
    $enginePolicySql .= ' ELSE ' . $db->quote($policyClass::DEPENDENCY_POLICY) . ' END';

    $requires = catalog_all(
        $db,
        'SELECT DISTINCT dst.id,dst.original_name file,dst.package_name package,dst.file_size size,dst.package_guid guid,dst.md5'
        . ' FROM ue_uedb5_dependency_edges l'
        . ' JOIN ue_uedb5_files m ON m.file_id=l.file_id AND m.format_version=5'
        . ' JOIN ue_games mg ON mg.id=m.game_id'
        . ' JOIN ue_game_profiles ep ON ep.id=mg.profile_id AND ep.is_active=1'
        . ' JOIN ue_uedb5_migration_status ms ON ms.file_id=m.file_id AND ms.game_id=m.game_id '
        . 'AND ms.dependency_payload_sha256=m.payload_sha256 AND ms.dependency_policy='
        . $enginePolicySql
        . ' JOIN ue_files dst ON dst.id=l.resolved_file_id AND dst.scan_status="verified"'
        . ' WHERE l.file_id=? AND l.resolved_file_id IS NOT NULL AND l.resolved_file_id<>?'
        . ' ORDER BY dst.original_name,dst.id LIMIT 5000',
        [$fileId, $fileId]
    );
    $requiredBy = catalog_all(
        $db,
        'SELECT DISTINCT src.id,src.original_name file,src.package_name package,src.file_size size,src.package_guid guid,src.md5'
        . ' FROM ue_uedb5_dependency_edges l'
        . ' JOIN ue_uedb5_files m ON m.file_id=l.file_id AND m.format_version=5'
        . ' JOIN ue_games mg ON mg.id=m.game_id'
        . ' JOIN ue_game_profiles ep ON ep.id=mg.profile_id AND ep.is_active=1'
        . ' JOIN ue_uedb5_migration_status ms ON ms.file_id=m.file_id AND ms.game_id=m.game_id '
        . 'AND ms.dependency_payload_sha256=m.payload_sha256 AND ms.dependency_policy='
        . $enginePolicySql
        . ' JOIN ue_files src ON src.id=l.file_id AND src.scan_status="verified"'
        . ' WHERE l.resolved_file_id=? AND l.file_id<>?'
        . ' ORDER BY src.original_name,src.id LIMIT 5000',
        [$fileId, $fileId]
    );

    foreach ($requires as &$row) {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['size_text'] = catalog_bytes((int)$row['size']);
    }
    unset($row);
    foreach ($requiredBy as &$row) {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['size_text'] = catalog_bytes((int)$row['size']);
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'requires' => $requires,
        'required_by' => $requiredBy,
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
