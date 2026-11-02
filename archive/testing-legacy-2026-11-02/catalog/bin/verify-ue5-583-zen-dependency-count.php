<?php
declare(strict_types=1);
/**
 * UE5.8.3 Zen dependency validation count contract. This checks the source
 * fields independently of dependency resolver output and requires no DB.
 * Real Satisfactory fixture evidence: UE583-D033-ZEN-DEPENDENCY-COUNT.md.
 */
require dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;

$validator = (new ReflectionClass(Uedb5MigrationValidator::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Uedb5MigrationValidator::class, 'zenHeaderDependencySourceCount');
$imports = array_fill(0, 28, ['type' => 'PackageImport']);
array_push($imports, ...array_fill(0, 8, ['type' => 'Null']));
$sections = [
    'imported_package_ids' => array_fill(0, 8, ['package_id' => '0123456789ABCDEF']),
    'imports' => $imports,
    'dependency_bundle_entries' => array_merge(
        array_fill(0, 48, ['kind' => 'Export']),
        array_fill(0, 14, ['kind' => 'Import', 'combined_import_index' => 0])
    ),
];
$observed = $method->invoke($validator, $sections);
$headerOnly = $method->invoke($validator, [
    'imported_package_ids' => [['package_id' => '0123456789ABCDEF']],
    'imports' => [['type' => 'Null']],
    'dependency_bundle_entries' => [],
]);
$ignoreInvented = $method->invoke($validator, ['dependency_bundle_entries' => [['target_type' => 'Export']]]);
$ok = $observed === 98 && $headerOnly === 1 && $ignoreInvented === 0;
echo json_encode(['ok' => $ok, 'satisfactory_shaped_expected' => 98,
    'actual' => $observed, 'imported_package_ids_without_object_imports' => $headerOnly,
    'unknown_target_type_is_not_serialized_kind' => $ignoreInvented], JSON_PRETTY_PRINT), PHP_EOL;
exit($ok ? 0 : 2);
