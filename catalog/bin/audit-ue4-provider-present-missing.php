<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

$options = getopt('', ['game-id::', 'top::']);
$gameId = max(1, (int)($options['game-id'] ?? 7));
$top = max(1, min(100, (int)($options['top'] ?? 30)));
$db = catalog_db(catalog_config());
$formatVersion = BlockedCompressedMetadataContainer::FORMAT_VERSION;

$packages = catalog_all(
    $db,
    'SELECT l.required_package_term_id,CONVERT(pkg.value_prefix USING utf8mb4) required_package,'
    . 'COUNT(*) missing_rows,COUNT(DISTINCT l.file_id) affected_files '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'AND EXISTS (SELECT 1 FROM ue_package_providers p JOIN ue_files pf ON pf.id=p.file_id '
    . 'WHERE p.game_id=f.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci) '
    . 'GROUP BY l.required_package_term_id,CONVERT(pkg.value_prefix USING utf8mb4) '
    . 'ORDER BY missing_rows DESC LIMIT ' . $top,
    [$formatVersion, $gameId]
);

$sample = $db->prepare(
    'SELECT l.file_id,l.import_index,f.original_name,f.package_version,'
    . 'CONVERT(obj.value_prefix USING utf8mb4) required_object_path,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,'
    . 'CONVERT(cn.value_prefix USING utf8mb4) class_name '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms obj ON obj.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'AND l.required_package_term_id=? ORDER BY l.file_id,l.import_index LIMIT 1'
);

$rows = [];
foreach ($packages as $package) {
    $sample->execute([$formatVersion, $gameId, (int)$package['required_package_term_id']]);
    $example = $sample->fetch(PDO::FETCH_ASSOC) ?: [];
    $rows[] = [
        'required_package' => (string)$package['required_package'],
        'missing_rows' => (int)$package['missing_rows'],
        'affected_files' => (int)$package['affected_files'],
        'example' => $example,
    ];
}

echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'metadata_format_version' => $formatVersion,
    'top_provider_present_missing_packages' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
