#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for retained Upload Bucket PAK container handling. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $data = @file_get_contents($path);
    return is_string($data) ? $data : '';
};

$phpFiles = [
    'migrations/202608170001_unverified_pak_members.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketPakContainerStore.php',
    'src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php',
    'src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php',
    'src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedPakCleanupService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedPakAssignmentService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedImporterAdapter.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedQueueMutationService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedGameMatchRefreshQueue.php',
    'src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php',
    'src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
    'src/Infrastructure/Unverified/PdoUnverifiedFileDetailsQuery.php',
    'src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php',
];
$syntaxFailures = [];
foreach ($phpFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$migration = $read('migrations/202608170001_unverified_pak_members.php');
$policy = $read('src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php');
$container = $read('src/Infrastructure/Import/CatalogBucketPakContainerStore.php');
$pakHandler = $read('src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php');
$router = $read('src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php');
$archive = $read('src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php');
$cleanup = $read('src/Infrastructure/Unverified/CatalogUnverifiedPakCleanupService.php');
$assignment = $read('src/Infrastructure/Unverified/CatalogUnverifiedPakAssignmentService.php');
$adapter = $read('src/Infrastructure/Unverified/CatalogUnverifiedImporterAdapter.php');
$mutation = $read('src/Infrastructure/Unverified/CatalogUnverifiedQueueMutationService.php');
$matchQueue = $read('src/Infrastructure/Unverified/CatalogUnverifiedGameMatchRefreshQueue.php');
$matchCache = $read('src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php');
$matchHandler = $read('src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php');
$pageQuery = $read('src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php');
$detailsQuery = $read('src/Infrastructure/Unverified/PdoUnverifiedFileDetailsQuery.php');
$workerVersion = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$strictIndexer = $read('src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$systemErrors = $read('src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php');

$record(
    'pak_is_container_extension_not_package_extension',
    str_contains($policy, 'if ($extension !== \'\' && $extension !== \'pak\')')
        && str_contains($policy, '$extensions[\'pak\'] = true;')
        && str_contains($policy, 'PAK container upload requires at least one active UE4 or UE5 game profile.')
        && str_contains($policy, 'isPakContainer'),
    'PAK must be admitted as a UE4/UE5 container capability, never by pretending it is an ordinary package-table extension.'
);

$routeCheck = strpos($router, 'isBucketPakWork($job)');
$innerCheck = strpos($router, 'return $this->inner->handle($job, $context);');
$record(
    'pak_is_routed_before_package_indexer',
    $routeCheck !== false
        && $innerCheck !== false
        && $routeCheck < $innerCheck
        && str_contains($router, 'new CatalogBucketPakJobHandler'),
    'A .pak in either direct Upload Bucket or archive-member staging must be diverted before the ordinary package reader.'
);

$record(
    'pak_parent_is_retained_container_not_package',
    str_contains($container, '"pak","PAK"')
        && !str_contains($container, 'N/A')
        && str_contains($container, 'CatalogUploadBucketStorage')
        && !str_contains($container, 'CatalogUnverifiedPackageIndexer')
        && !str_contains($container, 'required package GUID'),
    'The parent row must represent retained PAK identity/storage, not fake Unreal package tables or a fake GUID.'
);

$record(
    'pak_members_use_strict_package_path',
    str_contains($pakHandler, 'CatalogBucketIdentityProcessor')
        && str_contains($pakHandler, 'process_bucket_pak_entry')
        && str_contains($pakHandler, "'bucket_pak_member_id'")
        && str_contains($pakHandler, "'rejected'")
        && str_contains($pakHandler, '$memberId = 0;'),
    'Each supported extracted package must enter the existing strict package admission path and keep per-member failures isolated.'
);

$record(
    'pak_membership_tracks_safe_ownership',
    str_contains($migration, 'ue_unverified_pak_members')
        && str_contains($migration, 'owns_child_file')
        && str_contains($migration, 'ON DELETE SET NULL')
        && str_contains($cleanup, 'owns_child_file=1')
        && str_contains($cleanup, 'unverified_queue_game_id=0'),
    'The PAK may remove only extracted child rows/files it owns; links to pre-existing duplicates must survive parent cleanup.'
);

$record(
    'pak_parent_never_enters_dependency_matcher',
    str_contains($matchQueue, "=== 'pak'")
        && str_contains($matchCache, 'LOWER(COALESCE(extension,""))<>"pak"')
        && str_contains($matchCache, 'LOWER(COALESCE(f.extension,""))<>"pak"')
        && str_contains($matchHandler, 'PAK container dependency evidence is provided by its extracted package children')
        && substr_count($matchHandler, 'LOWER(COALESCE(extension,""))<>"pak"') >= 2,
    'PAK containers must be excluded from automatic/manual dependency matching; only extracted package children are match candidates.'
);

$record(
    'pak_parent_rolls_up_child_metadata_for_display',
    str_contains($pageQuery, 'rollUpPakChildren')
        && str_contains($pageQuery, 'N/A (PAK container)')
        && str_contains($pageQuery, 'ue_unverified_pak_members')
        && str_contains($pageQuery, '$item[\'pak_container\'] = true;')
        && str_contains($detailsQuery, '$pakContainer ? [] : $this->matches->one($fileId)')
        && str_contains($detailsQuery, 'N/A (PAK container)')
        && str_contains($detailsQuery, 'pak_members'),
    'The retained parent should display child N/I/E/evidence without itself being parsed as a package, and its details page must never invoke the package matcher.'
);

$record(
    'pak_assignment_reuses_existing_game_pak_workflow',
    str_contains($assignment, 'CatalogProfiledUploadQueue')
        && str_contains($assignment, 'enqueueStaged(')
        && str_contains($adapter, 'CatalogUnverifiedPakAssignmentService')
        && str_contains($adapter, 'PAK containers require one explicit UE4/UE5 target game'),
    'Assigning a bucket PAK must hand a durable copy to the established selected-game IMPORT_STAGED_PAK workflow rather than duplicating game PAK import logic.'
);

$record(
    'pak_delete_and_move_keep_container_atomic',
    str_contains($mutation, 'CatalogUnverifiedPakCleanupService')
        && str_contains($mutation, 'PAK containers cannot be moved as a single unverified package')
        && str_contains($cleanup, 'children_removed')
        && str_contains($cleanup, 'parent_removed'),
    'Delete must clean the PAK plus owned children; raw move must be blocked so the container cannot be separated from its contents.'
);

$record(
    'archive_contained_pak_uses_same_bucket_workflow',
    str_contains($archive, '$allowed[\'pak\'] = true;')
        && str_contains($archive, 'PROCESS_BUCKET_STAGED_PACKAGE')
        && str_contains($archive, 'CatalogBucketPakJobHandler'),
    'A .pak discovered inside ZIP/7z/RAR must reach the same retained PAK workflow rather than the ordinary package parser.'
);

$record(
    'non_unreal_pak_magic_miss_is_informational_exclusion',
    str_contains($pakHandler, 'isNonUnrealPakResource')
        && str_contains($pakHandler, "'status' => 'excluded'")
        && str_contains($pakHandler, "'classification' => 'non_unreal_pak'")
        && str_contains($pakHandler, 'resolveNonUnrealPakJob')
        && str_contains($systemErrors, 'public static function resolveNonUnrealPakJob')
        && str_contains($systemErrors, 'Informational exclusion: .pak source has no Unreal PAK magic footer.'),
    '.pak-named resources without Unreal FPakInfo magic must be bypassed as informational exclusions; genuine Unreal PAK parse failures still use the normal error path.'
);

$record(
    'strict_ordinary_package_admission_is_unchanged',
    str_contains($strictIndexer, 'does not contain a supported Unreal package header')
        && str_contains($strictIndexer, 'missing the required package GUID')
        && str_contains($strictIndexer, 'Reading the Names table')
        && str_contains($strictIndexer, 'Reading the Imports table')
        && str_contains($strictIndexer, 'Reading the Exports table'),
    'Adding PAK container handling must not weaken strict admission for ordinary Unreal package files.'
);

$record(
    'worker_fingerprint_includes_pak_router',
    str_contains($workerVersion, 'CatalogUnsupportedRedirectExclusionJobHandler.php')
        && str_contains($workerVersion, 'CatalogBucketPakJobHandler.php')
        && str_contains($workerVersion, 'CatalogBucketPakContainerStore.php'),
    'Running detached workers must be detected as stale after deploying the new PAK routing/handler code.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
