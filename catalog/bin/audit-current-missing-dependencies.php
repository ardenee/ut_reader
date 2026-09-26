#!/usr/bin/env php
<?php
/**
 * Read-only classifier for persisted missing dependencies using current metadata.
 * Consumer Import rows are the only source of requirements. Provider Exports are
 * used only as evidence for answering those requirements.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageObjectCoverageResolver;

$options = getopt('', ['game-id:', 'examples::']);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$exampleLimit = max(0, min(100, (int)($options['examples'] ?? 10)));
if ($gameId < 1) throw new InvalidArgumentException('Usage: php catalog/bin/audit-current-missing-dependencies.php --game-id=ID [--examples=10]');

$config = catalog_config();
$db = catalog_db($config);
$formatVersion = BlockedCompressedMetadataContainer::FORMAT_VERSION;

$sql = 'SELECT l.file_id,l.import_index,'
    . 'CONVERT(pt.value_prefix USING utf8mb4) required_package,'
    . 'CONVERT(ot.value_prefix USING utf8mb4) required_object_path,'
    . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,'
    . 'CONVERT(cn.value_prefix USING utf8mb4) class_name,'
    . 'l.status dependency_status,f.original_name,f.package_name '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=' . (int)$formatVersion . ' '
    . 'JOIN ue_terms pt ON pt.id=l.required_package_term_id '
    . 'JOIN ue_terms ot ON ot.id=l.required_object_term_id '
    . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
    . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" '
    . 'AND EXISTS (SELECT 1 FROM ue_dependency_links missing '
    . 'WHERE missing.file_id=l.file_id AND missing.required_package_term_id=l.required_package_term_id AND missing.status=0) '
    . 'ORDER BY l.file_id,l.required_package_term_id,l.import_index';
$stmt = $db->prepare($sql);
$stmt->execute([$gameId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groups = [];
$missingRows = 0;
foreach ($rows as $row) {
    $fileId = (int)$row['file_id'];
    $package = trim((string)$row['required_package']);
    if ($fileId < 1 || $package === '') continue;
    $key = $fileId . "\0" . strtolower($package);
    $groups[$key]['file_id'] = $fileId;
    $groups[$key]['consumer_file'] = (string)$row['original_name'];
    $groups[$key]['consumer_package'] = (string)$row['package_name'];
    $groups[$key]['required_package'] = $package;
    $groups[$key]['imports'][] = [
        'status' => (int)$row['dependency_status'],
        'import_index' => (int)$row['import_index'],
        'object_path' => trim((string)$row['required_object_path']),
        'class_package' => trim((string)$row['class_package']),
        'class_name' => trim((string)$row['class_name']),
    ];
    if ((int)$row['dependency_status'] === 0) $missingRows++;
}

$counts = [
    'no_package_provider' => 0,
    'provider_missing_object' => 0,
    'provider_class_mismatch' => 0,
    'suspicious_provider_has_required_set' => 0,
    'package_only_missing_row' => 0,
];
$examples = [];
$processed = 0;

foreach ($groups as $group) {
    $paths = [];
    $classes = [];
    foreach ($group['imports'] as $import) {
        $path = trim((string)$import['object_path']);
        if ($path === '') continue;
        $paths[] = $path;
        if ((string)$import['class_name'] !== '') {
            $classes[$path] = ['class_package'=>(string)$import['class_package'],'class_name'=>(string)$import['class_name']];
        }
    }
    $paths = array_values(array_unique($paths));
    if ($paths === []) {
        $classification = 'package_only_missing_row';
        $coverage = [];
    } else {
        $coverage = PdoPackageObjectCoverageResolver::evaluate($db,$gameId,(string)$group['required_package'],$paths,(int)$group['file_id'],$classes);
        if ($coverage === []) {
            $classification = 'no_package_provider';
        } else {
            $complete = array_filter($coverage, static fn(array $p): bool => (string)($p['status'] ?? '') === 'fully_satisfies');
            if ($complete !== []) {
                $classification = 'suspicious_provider_has_required_set';
            } else {
                $pathOnly = PdoPackageObjectCoverageResolver::evaluate($db,$gameId,(string)$group['required_package'],$paths,(int)$group['file_id'],[]);
                $pathComplete = array_filter($pathOnly, static fn(array $p): bool => (string)($p['status'] ?? '') === 'fully_satisfies');
                $classification = $pathComplete !== [] ? 'provider_class_mismatch' : 'provider_missing_object';
            }
        }
    }
    $counts[$classification]++;
    if ($exampleLimit > 0 && count($examples[$classification] ?? []) < $exampleLimit) {
        $examples[$classification][] = [
            'consumer_file_id'=>(int)$group['file_id'],
            'consumer_file'=>(string)$group['consumer_file'],
            'consumer_package'=>(string)$group['consumer_package'],
            'required_package'=>(string)$group['required_package'],
            'required_object_paths'=>$paths,
            'imports'=>$group['imports'],
            'providers'=>array_map(static fn(array $p): array => [
                'file_id'=>(int)($p['file_id'] ?? 0),
                'status'=>(string)($p['status'] ?? ''),
                'required_count'=>(int)($p['required_count'] ?? 0),
                'matched_count'=>(int)($p['matched_count'] ?? 0),
                'missing_paths'=>array_values((array)($p['missing_paths'] ?? [])),
            ], $coverage),
        ];
    }
    $processed++;
    if (($processed % 250) === 0) fwrite(STDERR, "Classified {$processed}/" . count($groups) . PHP_EOL);
}

arsort($counts);
echo json_encode([
    'ok'=>true,
    'read_only'=>true,
    'game_id'=>$gameId,
    'metadata_format_version'=>$formatVersion,
    'missing_dependency_rows'=>$missingRows,
    'missing_package_groups'=>count($groups),
    'classifications'=>$counts,
    'examples_per_classification'=>$examples,
    'interpretation'=>[
        'suspicious_provider_has_required_set'=>'Inspect first: a current verified provider appears to satisfy every serialized Import requirement for this consumer/package group.',
        'provider_class_mismatch'=>'Provider has the requested object paths, but class package/name evidence does not satisfy the request.',
        'provider_missing_object'=>'A package provider exists, but no single provider contains the complete requested object set.',
        'no_package_provider'=>'No verified game-local provider exists for the serialized Import package.',
        'package_only_missing_row'=>'A package-only Import remains stored as missing and should be inspected separately.',
    ],
    'invariant'=>'Only consumer Imports create dependency requirements; provider Exports only answer those requests.',
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
