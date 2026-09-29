<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyRebuilder;

$failures = [];
$checks = [];
$check = static function (string $name, bool $ok) use (&$failures, &$checks): void {
    $checks[] = ['check'=>$name,'ok'=>$ok];
    if (!$ok) $failures[] = $name;
};

$imports = [
    ['import_index'=>0,'outer_index'=>0,'object_name'=>'/Game/A'],
    ['import_index'=>1,'outer_index'=>-1,'object_name'=>'OuterA'],
    ['import_index'=>2,'outer_index'=>-2,'object_name'=>'LeafA'],
    ['import_index'=>3,'outer_index'=>0,'object_name'=>'/Game/B'],
    ['import_index'=>4,'outer_index'=>-4,'object_name'=>'LeafB'],
];
$method = new ReflectionMethod(CompactDependencyRebuilder::class, 'ue4TargetedResolutionImports');
$result = $method->invoke(null, $imports, [$imports[2]]);
$indexes = array_map(static fn(array $row): int => (int)$row['import_index'], $result);

$check('target_included', in_array(2, $indexes, true));
$check('parent_included', in_array(1, $indexes, true));
$check('root_included', in_array(0, $indexes, true));
$check('unrelated_root_excluded', !in_array(3, $indexes, true));
$check('unrelated_leaf_excluded', !in_array(4, $indexes, true));

$source = file_get_contents($root . '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php') ?: '';
$check('ue4_targeted_path_uses_ancestor_closure', str_contains(
    $source,
    "\$engineKey === 'UE4' && \$packageKeys !== null"
));
$check('ue4_targeted_path_loads_consumer_exports', str_contains(
    $source,
    "loadDependencySnapshot(\$fileId, \$engineKey === 'UE4')"
));
$check('ue4_targeted_path_passes_full_consumer_import_graph', str_contains(
    $source,
    "\$consumerExports,\n                \$imports"
));

$result = ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
