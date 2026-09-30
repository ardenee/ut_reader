#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5StagingIsolationContract;

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$allowed = Uedb5StagingIsolationContract::allowedWriteTables();
$forbidden = Uedb5StagingIsolationContract::forbiddenLiveWriteTables();
$check('all_staging_write_tables_are_v5_namespaced', $allowed !== []
    && count(array_filter($allowed, static fn(string $t): bool => !str_starts_with($t, 'ue_uedb5_'))) === 0);
$check('live_file_registration_is_forbidden_write_target', in_array('ue_file_metadata', $forbidden, true));
$check('v4_export_projection_is_forbidden_write_target', in_array('ue_export_lookup', $forbidden, true)
    && in_array('ue_export_path_lookup', $forbidden, true));
$check('v4_dependency_projection_is_forbidden_write_target', in_array('ue_dependency_links', $forbidden, true));
$check('allowed_and_forbidden_tables_do_not_overlap', array_intersect($allowed, $forbidden) === []);

$paths = Uedb5StagingIsolationContract::assertContainerPathIsolation('C:\\staging-root', 7, 123456);
$check('v4_and_v5_paths_are_distinct', $paths['v4'] !== $paths['v5']);
$check('v4_path_keeps_uedb4_extension', str_ends_with(strtolower($paths['v4']), '.uedb4'));
$check('v5_path_uses_uedb5_extension', str_ends_with(strtolower($paths['v5']), '.uedb5'));
$check('v4_and_v5_share_only_parent_shard', dirname($paths['v4']) === dirname($paths['v5']));

$metadataDir = $root . '/src/Infrastructure/Metadata';
$files = array_merge(
    glob($metadataDir . '/Uedb5*.php') ?: [],
    glob($metadataDir . '/PdoUedb5*.php') ?: []
);
$files = array_values(array_unique($files));
$writeTargets = [];
$unguardedDynamicWrites = [];
foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    if (preg_match_all('/(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-zA-Z0-9_]+)`?/i', $source, $m)) {
        foreach ($m[1] as $table) { $writeTargets[] = strtolower((string)$table); }
    }
    if (preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s*[\'\"]\s*\.\s*\$/i', $source)
        && !str_contains($source, 'Uedb5StagingIsolationContract::assertWriteTable')) {
        $unguardedDynamicWrites[] = basename($file);
    }
}
$writeTargets = array_values(array_unique($writeTargets));
$illegal = array_values(array_diff($writeTargets, $allowed));
$check('literal_v5_staging_dml_targets_are_allowed', $illegal === []);
$check('dynamic_v5_staging_dml_is_guarded', $unguardedDynamicWrites === []);

$rejected = false;
try {
    Uedb5StagingIsolationContract::assertWriteTable('ue_file_metadata');
} catch (RuntimeException) {
    $rejected = true;
}
$check('guard_rejects_live_registration_write', $rejected);

$publisher = (string)file_get_contents($metadataDir . '/PdoUedb5BaseProjectionPublisher.php');
$registration = (string)file_get_contents($metadataDir . '/PdoUedb5StagingRegistrationRepository.php');
$writer = (string)file_get_contents($metadataDir . '/Uedb5MetadataSnapshotWriter.php');
$check('base_projection_publisher_calls_write_guard', str_contains($publisher, 'Uedb5StagingIsolationContract::assertWriteTable'));
$check('staging_registration_calls_write_guard', str_contains($registration, 'Uedb5StagingIsolationContract::assertWriteTable'));
$check('snapshot_writer_calls_path_guard', str_contains($writer, 'assertContainerPathIsolation'));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'literal_write_targets' => $writeTargets,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
