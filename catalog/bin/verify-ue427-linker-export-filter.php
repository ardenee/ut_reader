<?php
declare(strict_types=1);

/**
 * Exact C++ branch check for FLinker::FilterExport, Epic UE4.27.2:
 * CoreUObject/Public/UObject/Linker.h:528-553.
 *
 * Linker flags are explicit inputs because they cannot be inferred from
 * package bytes. This is NOT native runtime equivalence certification.
 */
require_once dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';

$class = new ReflectionClass(UnrealPackageReader4::class);
$reader = $class->newInstanceWithoutConstructor();
$exportsField = $class->getProperty('exports');
$baseExport = [
    'ref' => 1, // SerializeExportMap: FPackageIndex::FromExport(0)
    'notForEditorGame' => true, // Original FObjectExport ctor default
    'notForClient' => false,
    'notForServer' => false,
];
$state = [
    'withEditor' => false,
    'filterClientButNotServer' => false,
    'filterServerButNotClient' => false,
    'exportLoadFailed' => false,
    'wasFiltered' => false,
];
$checks = [];
$run = static function (string $name, array $exportChanges, array $stateChanges, bool $expected)
    use ($reader, $exportsField, $baseExport, $state, &$checks): void {
    $exportsField->setValue($reader, [array_replace($baseExport, $exportChanges)]);
    $actual = $reader->filterExportForLinkerContext(0, array_replace($state, $stateChanges));
    $checks[$name] = $actual === $expected;
};

$run('default_loaded_export', [], [], false);
$run('export_failed_precedes_everything', ['notForEditorGame'=>false], [
    'withEditor'=>true, 'exportLoadFailed'=>true
], true);
$run('previously_filtered_precedes_everything', ['notForEditorGame'=>false], [
    'withEditor'=>true, 'wasFiltered'=>true
], true);
$run('editor_always_load_short_circuits_server_filter', [
    'notForEditorGame'=>false, 'notForServer'=>true
], ['withEditor'=>true, 'filterClientButNotServer'=>true], false);
$run('non_editor_does_not_take_editor_short_circuit', [
    'notForEditorGame'=>false, 'notForServer'=>true
], ['withEditor'=>false, 'filterClientButNotServer'=>true], true);
$run('editor_not_always_loaded_enters_client_filter', [
    'notForEditorGame'=>true, 'notForClient'=>true
], ['withEditor'=>true, 'filterServerButNotClient'=>true], true);
$run('client_only_filter', ['notForClient'=>true],
    ['filterServerButNotClient'=>true], true);
$run('dedicated_server_filter', ['notForServer'=>true],
    ['filterClientButNotServer'=>true], true);
$run('flag_does_not_filter_when_matching_linker_flag_unset', [
    'notForClient'=>true, 'notForServer'=>true
], [], false);
$run('null_this_index', ['ref'=>0], [], true);

$exportsField->setValue($reader, [$baseExport]);
foreach (['withEditor', 'filterClientButNotServer', 'filterServerButNotClient',
          'exportLoadFailed', 'wasFiltered'] as $required) {
    $incomplete = $state;
    unset($incomplete[$required]);
    $threw = false;
    try {
        $reader->filterExportForLinkerContext(0, $incomplete);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), $required);
    }
    $checks['requires_explicit_' . $required] = $threw;
}
$invalid = false;
try {
    $reader->filterExportForLinkerContext(1, $state);
} catch (OutOfBoundsException $e) {
    $invalid = true;
}
$checks['rejects_missing_export'] = $invalid;

// Check the *actual* UE4 reader's pre-version-gate deserialization path.
// Epic FObjectExport() supplies these defaults before ObjectResource.cpp
// reads any version-gated optional fields; 64 bytes is the original
// v214 FObjectExport record (with all named refs and flags zero).
$legacyReader = $class->newInstanceWithoutConstructor();
$stream = fopen('php://temp', 'w+b');
fwrite($stream, "\0" . str_repeat("\0", 64));
rewind($stream);
$class->getProperty('reader')->setValue($legacyReader, new UE4BinaryReader($stream, 65));
$class->getProperty('fileSize')->setValue($legacyReader, 65);
$class->getProperty('header')->setValue($legacyReader, [
    'version'=>214, 'exportOffset'=>1, 'exportCount'=>1
]);
$class->getProperty('names')->setValue($legacyReader, [
    ['index'=>0, 'name'=>'None'],
]);
$class->getMethod('readExports')->invoke($legacyReader);
fclose($stream);
$legacyExport = $legacyReader->getExports()[0] ?? [];
$checks['epic_pre365_default_editor_game_true'] =
    ($legacyExport['notForEditorGamePresent'] ?? null) === false
    && ($legacyExport['notForEditorGame'] ?? null) === true;
$checks['epic_pre485_default_asset_false'] =
    ($legacyExport['isAssetPresent'] ?? null) === false
    && ($legacyExport['isAsset'] ?? null) === false;
$checks['epic_pre507_default_preload_counters'] =
    ($legacyExport['preloadPresent'] ?? null) === false
    && ($legacyExport['preload']['firstExportDependency'] ?? null) === -1
    && ($legacyExport['preload']['serializationBeforeSerializationDependencies'] ?? null) === 0
    && ($legacyExport['preload']['createBeforeSerializationDependencies'] ?? null) === 0
    && ($legacyExport['preload']['serializationBeforeCreateDependencies'] ?? null) === 0
    && ($legacyExport['preload']['createBeforeCreateDependencies'] ?? null) === 0;

$passed = count(array_filter($checks));
foreach ($checks as $name => $ok) {
    fwrite(STDOUT, ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
}
fwrite(STDOUT, sprintf("Result: %d/%d\n", $passed, count($checks)));
exit($passed === count($checks) ? 0 : 1);
