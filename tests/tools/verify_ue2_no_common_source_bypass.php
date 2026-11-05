<?php
declare(strict_types=1);
/**
 * Original UE2 packages. The legacy common-package list must never promote
 * an unselected provider to satisfied in Unreal II's dependency pass.
 * Snapshots are copied read-only from a current UEDB5 source installation.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;

$storage = trim((string)(getenv('UE2_COMMON_ORIGINAL_FIXTURE_ROOT') ?: ''));
if ($storage === '') { fwrite(STDERR, "UE2_COMMON_ORIGINAL_FIXTURE_ROOT is required.\n"); exit(2); }
$reader = new Uedb5MetadataReader($storage);
$all = [];
$ok = true;
foreach ([111205 => 'Core', 1360642 => 'GamePlay'] as $id => $target) {
    $snapshot = $reader->snapshot(2, $id);
    $sourceImports = Uedb5ClassicDependencyResolver::importCoverageRows($snapshot);
    $sourceRootIndexes = [];
    foreach ($sourceImports as $entry) {
        if (strcasecmp((string)($entry['root_package'] ?? ''), $target) === 0) {
            $sourceRootIndexes[(int)$entry['import_index']] = true;
        }
    }
    $results = Uedb5ClassicDependencyResolver::resolve(
        $snapshot, [], ['common_packages' => ['Core','Engine','GamePlay','Fire','IpDrv']]
    );
    $previousCommon = 0;
    foreach ((array)($snapshot['sections']['dependency_results'] ?? []) as $entry) {
        if (($entry['outcome'] ?? '') === 'common'
            && strcasecmp((string)($entry['required_package_identity']['value'] ?? ''), $target) === 0) {
            $previousCommon++;
        }
    }
    $changed = 0;
    foreach (array_keys($sourceRootIndexes) as $index) {
        if ((string)($results[$index]['status'] ?? '') === 'unresolved') { $changed++; }
    }
    $commonTotal = count(array_filter(
        $results, static fn(array $entry): bool => ($entry['status'] ?? '') === 'common'
    ));
    $pass = count($sourceRootIndexes) > 0 && $previousCommon > 0
        && $changed === count($sourceRootIndexes) && $commonTotal === 0;
    $ok = $ok && $pass;
    $all[] = [
        'pass' => $pass, 'file_id' => $id, 'legacy_common_package' => $target,
        'previously_common_references' => $previousCommon,
        'source_root_references' => count($sourceRootIndexes),
        'now_unresolved_without_source_selected_provider' => $changed,
        'false_common_after_change' => $commonTotal
    ];
}
// The legacy SQL read path must not resurrect is_common=1 from an
// obsolete persisted projection after the source-correct UE2 fix.
$legacyGuard = new ReflectionMethod(
    \UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver::class,
    'isCommonImport'
);
$legacyPass = $legacyGuard->invoke(null, ['is_common'=>1,'root_package'=>'Engine'], 'UE2') === false
    && $legacyGuard->invoke(null, ['is_common'=>1,'root_package'=>'Engine'], 'UE1') === true;
$ok = $ok && $legacyPass;
$version = Uedb5GameDependencyPassService::policyForEngine('UE2');
$versioned = str_contains($version, 'no-common-v15')
    && Uedb5GameDependencyPassService::policyForEngine('UE1') !== $version;
$ok = $ok && $versioned;
echo json_encode(['pass'=>$ok,'ue2_policy'=>$version,'other_engine_policy_unchanged'=>$versioned,'legacy_ue2_is_common_rejected'=>$legacyPass,'original_cases'=>$all,'production_write'=>false],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), PHP_EOL;
exit($ok ? 0 : 2);
