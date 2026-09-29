<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';
require_once $root . '/lib/CatalogDependencyDiagnostics.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

$options = getopt('', ['file-id:', 'import-index:']);
$fileId = max(0, (int)($options['file-id'] ?? 0));
$importIndex = max(0, (int)($options['import-index'] ?? -1));
if ($fileId < 1 || !isset($options['import-index'])) {
    throw new InvalidArgumentException('Specify --file-id and --import-index.');
}
$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
$loader = new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot);
$consumer = $loader->load($fileId);
$consumerImports = (array)($consumer['imports'] ?? []);
$consumerExports = (array)($consumer['exports'] ?? []);
$import = null;
foreach ($consumerImports as $fallback => $row) {
    if (!is_array($row)) continue;
    $idx = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
    if ($idx === $importIndex) { $import = $row; break; }
}
if (!is_array($import)) throw new RuntimeException('Import not found in UEDB4.');

$importsByIndex = [];
foreach ($consumerImports as $fallback => $row) {
    if (!is_array($row)) continue;
    $idx = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
    $importsByIndex[$idx] = $row;
}
$exportsByIndex = [];
foreach ($consumerExports as $fallback => $row) {
    if (!is_array($row)) continue;
    $idx = isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback;
    $exportsByIndex[$idx] = $row;
}
$outerChain = [];
$currentKind = 'import';
$currentIndex = $importIndex;
$seenResources = [];
while (true) {
    $resourceKey = $currentKind . ':' . $currentIndex;
    if (isset($seenResources[$resourceKey])) {
        $outerChain[] = ['kind'=>'cycle','resource'=>$resourceKey];
        break;
    }
    $seenResources[$resourceKey] = true;
    $row = $currentKind === 'import'
        ? ($importsByIndex[$currentIndex] ?? null)
        : ($exportsByIndex[$currentIndex] ?? null);
    if (!is_array($row)) {
        $outerChain[] = ['kind'=>'missing_resource','resource'=>$resourceKey];
        break;
    }
    $outerChain[] = [
        'kind' => $currentKind,
        'index' => $currentIndex,
        'object_name' => (string)($row['object_name'] ?? ''),
        'class_package' => (string)($row['class_package'] ?? ''),
        'class_name' => (string)($row['class_name'] ?? ''),
        'outer_index' => (int)($row['outer_index'] ?? 0),
        'relative_object_path' => (string)($row['relative_object_path'] ?? ($row['local_path'] ?? '')),
        'full_path' => (string)($row['full_path'] ?? ''),
    ];
    $outerIndex = (int)($row['outer_index'] ?? 0);
    if ($outerIndex === 0) break;
    if ($outerIndex < 0) {
        $currentKind = 'import';
        $currentIndex = -$outerIndex - 1;
    } else {
        $currentKind = 'export';
        $currentIndex = $outerIndex - 1;
    }
}
$dep = catalog_one($db,
    'SELECT l.status,l.resolved_file_id,l.resolved_export_index,'
    . 'CONVERT(pkg.value_prefix USING utf8mb4) required_package,'
    . 'CONVERT(obj.value_prefix USING utf8mb4) required_object_path,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,'
    . 'CONVERT(cn.value_prefix USING utf8mb4) class_name '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'JOIN ue_terms obj ON obj.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE l.file_id=? AND l.import_index=? LIMIT 1',
    [$fileId, $importIndex]
);
if (!is_array($dep)) throw new RuntimeException('Dependency row not found.');
$dep['file_id'] = $fileId;
$dep['import_index'] = $importIndex;
$dep['import_object_name'] = (string)($import['object_name'] ?? '');
$dep['import_class_package'] = (string)($import['class_package'] ?? ($dep['class_package'] ?? ''));
$dep['import_class_name'] = (string)($import['class_name'] ?? ($dep['class_name'] ?? ''));
$package = (string)($dep['required_package'] ?? '');
$providers = catalog_dependency_provider_candidates($db, 7, $fileId, $package);
$outProviders = [];
foreach ($providers as $provider) {
    $providerId = (int)($provider['file_id'] ?? 0);
    $outcome = PdoUe4VerifyImportProjectionResolver::resolveProviderOutcome($db, $providerId, $consumerImports, $consumerExports);
    $matches = (array)($outcome['matches'] ?? []);
    $redirectors = (array)($outcome['redirectors'] ?? []);
    $candidates = catalog_dependency_export_candidates($db, $dep, $providerId);
    $best = null;
    $bestScore = -1;
    foreach ($candidates as $candidate) {
        $outerPath = catalog_dependency_export_outer_path(
            $db,
            $providerId,
            (int)($candidate['outer_index'] ?? 0),
            $package
        );
        $summary = catalog_dependency_candidate_summary($dep, $candidate, $outerPath);
        $score = (int)$summary['object_match']
            + (int)$summary['class_package_match']
            + (int)$summary['class_name_match']
            + (int)$summary['outer_match']
            + (int)$summary['public'];
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'export_index' => (int)($candidate['export_index'] ?? -1),
                'outer_index' => (int)($candidate['outer_index'] ?? 0),
                'object_flags' => $candidate['object_flags'] ?? null,
                'candidate' => $candidate,
                'summary' => $summary,
            ];
        }
    }
    $outProviders[] = [
        'provider' => $provider,
        'resolver_match_for_import' => array_key_exists($importIndex, $matches)
            ? (int)$matches[$importIndex]
            : null,
        'resolver_total_matches' => count($matches),
        'resolver_redirector_for_import' => array_key_exists($importIndex, $redirectors)
            ? (int)$redirectors[$importIndex]
            : null,
        'resolver_total_redirectors' => count($redirectors),
        'best_candidate' => $best,
    ];
}
$file = catalog_one(
    $db,
    'SELECT id,game_id,package_name,original_name,package_version FROM ue_files WHERE id=? LIMIT 1',
    [$fileId]
) ?: [];

echo json_encode([
    'ok' => true,
    'read_only' => true,
    'file' => $file,
    'dependency' => $dep,
    'serialized_import' => [
        'import_index' => $importIndex,
        'class_package' => (string)($import['class_package'] ?? ''),
        'class_name' => (string)($import['class_name'] ?? ''),
        'object_name' => (string)($import['object_name'] ?? ''),
        'outer_index' => (int)($import['outer_index'] ?? 0),
        'root_package' => (string)($import['root_package'] ?? ''),
        'relative_object_path' => (string)($import['relative_object_path'] ?? ''),
        'full_path' => (string)($import['full_path'] ?? ''),
    ],
    'serialized_outer_chain' => $outerChain,
    'private_import_allowed_by_consumer_graph' => PdoUe4VerifyImportProjectionResolver::privateImportAllowedInMemory(
        $importIndex,
        $consumerImports,
        $consumerExports
    ),
    'provider_count' => count($outProviders),
    'providers' => $outProviders,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
