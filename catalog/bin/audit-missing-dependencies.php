#!/usr/bin/env php
<?php
/**
 * Read-only audit of currently missing dependency rows for one game.
 *
 * Replays package/object coverage against the current v3 provider projection
 * without reparsing packages or mutating dependency state.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageObjectCoverageResolver;

$options = getopt('', ['game-id:', 'max-details::']);
$gameId = (int)($options['game-id'] ?? 0);
$maxDetails = max(1, min(10000, (int)($options['max-details'] ?? 1000)));
if ($gameId < 1) {
    fwrite(STDERR, "Usage: php catalog/bin/audit-missing-dependencies.php --game-id=ID [--max-details=1000]\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$gameStmt = $db->prepare('SELECT id,name FROM ue_games WHERE id=? LIMIT 1');
$gameStmt->execute([$gameId]);
$game = $gameStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "Game not found: {$gameId}\n");
    exit(3);
}

$verifiedStmt = $db->prepare('SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified"');
$verifiedStmt->execute([$gameId]);
$verifiedFiles = (int)$verifiedStmt->fetchColumn();

$sql = 'SELECT l.file_id,l.import_index,'
    . 'CONVERT(pt.value_prefix USING utf8mb4) required_package,'
    . 'CONVERT(ot.value_prefix USING utf8mb4) required_object_path,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,'
    . 'CONVERT(cn.value_prefix USING utf8mb4) class_name,'
    . 'f.original_name,f.package_name '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3 '
    . 'JOIN ue_terms pt ON pt.id=l.required_package_term_id '
    . 'JOIN ue_terms ot ON ot.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'ORDER BY l.file_id,l.import_index';
try {
    $stmt = $db->prepare($sql);
    $stmt->execute([$gameId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $error) {
    // Older dependency-link schemas may not project class term IDs. Coverage is
    // still useful without class diagnostics.
    $sql = 'SELECT l.file_id,l.import_index,'
        . 'CONVERT(pt.value_prefix USING utf8mb4) required_package,'
        . 'CONVERT(ot.value_prefix USING utf8mb4) required_object_path,'
        . '"" class_package,"" class_name,f.original_name,f.package_name '
        . 'FROM ue_dependency_links l '
        . 'JOIN ue_files f ON f.id=l.file_id '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3 '
        . 'JOIN ue_terms pt ON pt.id=l.required_package_term_id '
        . 'JOIN ue_terms ot ON ot.id=l.required_object_term_id '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
        . 'ORDER BY l.file_id,l.import_index';
    $stmt = $db->prepare($sql);
    $stmt->execute([$gameId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$groups = [];
foreach ($rows as $row) {
    $fileId = (int)$row['file_id'];
    $package = trim((string)$row['required_package']);
    $object = trim((string)$row['required_object_path']);
    if ($fileId < 1 || $package === '') {
        continue;
    }
    $key = $fileId . "\0" . strtolower($package);
    $groups[$key]['file_id'] = $fileId;
    $groups[$key]['original_name'] = (string)$row['original_name'];
    $groups[$key]['consumer_package'] = (string)$row['package_name'];
    $groups[$key]['required_package'] = $package;
    $groups[$key]['imports'][] = [
        'import_index' => (int)$row['import_index'],
        'object_path' => $object,
        'class_package' => trim((string)$row['class_package']),
        'class_name' => trim((string)$row['class_name']),
    ];
}

$counts = [
    'no_package_provider' => 0,
    'provider_missing_object' => 0,
    'provider_has_required_set' => 0,
    'provider_class_mismatch' => 0,
    'package_only_missing_row' => 0,
];
$details = [];
foreach ($groups as $group) {
    $paths = [];
    $classes = [];
    foreach ($group['imports'] as $import) {
        $path = trim((string)$import['object_path']);
        if ($path === '') {
            continue;
        }
        $paths[] = $path;
        if ((string)$import['class_name'] !== '') {
            $classes[$path] = [
                'class_package' => (string)$import['class_package'],
                'class_name' => (string)$import['class_name'],
            ];
        }
    }
    $paths = array_values(array_unique($paths));

    $coverage = PdoPackageObjectCoverageResolver::evaluate(
        $db,
        $gameId,
        (string)$group['required_package'],
        $paths,
        (int)$group['file_id'],
        $classes
    );
    // Keep a path-only comparison in the audit so a class mismatch cannot be
    // mistaken for a resolver/publication failure.
    $pathOnlyCoverage = $classes === [] ? $coverage : PdoPackageObjectCoverageResolver::evaluate(
        $db,
        $gameId,
        (string)$group['required_package'],
        $paths,
        (int)$group['file_id'],
        []
    );

    if ($paths === []) {
        $classification = 'package_only_missing_row';
    } elseif ($coverage === []) {
        $classification = 'no_package_provider';
    } else {
        $complete = array_values(array_filter(
            $coverage,
            static fn(array $p): bool => (string)($p['status'] ?? '') === 'fully_satisfies'
        ));
        if ($complete !== []) {
            $classification = 'provider_has_required_set';
        } else {
            $pathOnlyComplete = array_values(array_filter(
                $pathOnlyCoverage,
                static fn(array $p): bool => (string)($p['status'] ?? '') === 'fully_satisfies'
            ));
            $classification = $pathOnlyComplete !== [] ? 'provider_class_mismatch' : 'provider_missing_object';
        }
    }
    $counts[$classification]++;

    if (count($details) < $maxDetails) {
        $details[] = [
            'classification' => $classification,
            'consumer_file_id' => (int)$group['file_id'],
            'consumer_file' => (string)$group['original_name'],
            'consumer_package' => (string)$group['consumer_package'],
            'required_package' => (string)$group['required_package'],
            'missing_import_rows' => count($group['imports']),
            'required_object_paths' => $paths,
            'imports' => $group['imports'],
            'providers' => array_map(
                static fn(array $p): array => [
                    'file_id' => (int)($p['file_id'] ?? 0),
                    'source' => (string)($p['source'] ?? ''),
                    'status' => (string)($p['status'] ?? ''),
                    'required_count' => (int)($p['required_count'] ?? 0),
                    'matched_count' => (int)($p['matched_count'] ?? 0),
                    'missing_count' => (int)($p['missing_count'] ?? 0),
                    'matched_paths' => array_values((array)($p['matched_paths'] ?? [])),
                    'missing_paths' => array_values((array)($p['missing_paths'] ?? [])),
                    'matched_exports' => (array)($p['matched_exports'] ?? []),
                ],
                $coverage
            ),
        ];
    }
}

$result = [
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'game_name' => (string)$game['name'],
    'verified_files' => $verifiedFiles,
    'missing_dependency_rows' => count($rows),
    'missing_package_groups' => count($groups),
    'classifications' => $counts,
    'details_truncated' => count($groups) > $maxDetails,
    'details' => $details,
    'interpretation' => [
        'provider_has_required_set' => 'Suspicious: current game-local v3 provider coverage satisfies the missing requirement set; inspect resolver/publication state.',
        'provider_class_mismatch' => 'A provider has every required path, but the current Import class constraint rejects at least one matched Export.',
        'provider_missing_object' => 'Package provider exists, but no single current provider satisfies the required object set.',
        'no_package_provider' => 'No current game-local v3 provider is available for the required package.',
        'package_only_missing_row' => 'Missing dependency row has no object path; inspect package-only resolution/provider projection.',
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
