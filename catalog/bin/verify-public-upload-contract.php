#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for the public contribution upload boundary.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];

$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$migration = $read('migrations/202608280001_public_uploads.php');
$migrationGuard = $read('migrations/202608280002_public_upload_active_identity.php');
$settings = $read('src/Infrastructure/Settings/CatalogPublicUploadSettingsStore.php');
$preflight = $read('src/Infrastructure/Import/CatalogPublicUploadBatchPreflight.php');
$transfer = $read('src/Infrastructure/Import/CatalogPublicUploadTransferStore.php');
$duplicateDetector = $read('src/Infrastructure/Import/CatalogUploadDuplicateDetector.php');
$handler = $read('src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php');
$maintenance = $read('src/Infrastructure/Jobs/CatalogPublicUploadMaintenanceJobHandler.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$resource = $read('src/Domain/Jobs/JobResourcePolicy.php');
$workerVersion = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$preflightApi = $read('api/v1/public-upload-preflight.php');
$uploadApi = $read('api/v1/public-upload.php');
$page = $read('public-upload.php');
$client = $read('assets/public-upload.js');
$programSettings = $read('program-settings.php');
$nav = $read('lib/CatalogSupportCore.php');
$landing = $read('../index.php');
$install = $read('install.sql');

$check(
    'public_upload_schema',
    str_contains($migration, "'ue_public_uploads'")
        && str_contains($migration, 'active_identity_key CHAR(64) NULL')
        && str_contains($migration, 'UNIQUE KEY uq_ue_public_uploads_active_identity')
        && str_contains($migration, 'idx_ue_public_uploads_identity')
        && str_contains($migration, 'submitter_ip VARBINARY(16)')
        && str_contains($migrationGuard, "version' => '202608280002'")
        && str_contains($migrationGuard, 'ensureColumn(')
        && str_contains($migrationGuard, 'ensureIndex('),
    'Public quarantine/reservation state and the concurrent-identity guard must be migration-backed.'
);

$check(
    'fresh_install_schema',
    str_contains($install, 'CREATE TABLE ue_program_settings')
        && str_contains($install, 'CREATE TABLE ue_public_uploads')
        && str_contains($install, 'uq_ue_public_uploads_active_identity')
        && str_contains($install, 'idx_ue_public_uploads_ip_time'),
    'Fresh installs must contain the program settings and public upload ledger.'
);

$check(
    'public_settings_are_admin_configurable',
    str_contains($settings, "'enabled' => true")
        && str_contains($settings, "'files_per_hour' => 2000")
        && str_contains($settings, "'bytes_per_hour' => 50 * 1024 * 1024 * 1024")
        && str_contains($settings, "'max_outstanding' => 100")
        && str_contains($settings, "'min_free_bytes' => 20 * 1024 * 1024 * 1024")
        && str_contains($programSettings, 'settings_section" value="public_upload"')
        && str_contains($programSettings, 'public_upload_min_free_gib')
        && str_contains($programSettings, 'public_upload_files_per_hour')
        && str_contains($programSettings, 'public_upload_bytes_per_hour_gib'),
    'Public ingress must have an administrator kill switch, volume limits and a disk reserve.'
);

$check(
    'preflight_is_true_100_file_batch',
    str_contains($preflight, 'public const MAX_FILES = 100;')
        && str_contains($preflight, 'WHERE md5 IN (')
        && str_contains($preflight, 'WHERE package_guid IN (')
        && str_contains($preflight, 'active_identity_key IN (')
        && str_contains($preflight, 'INSERT IGNORE INTO ue_public_uploads')
        && !str_contains($preflight, '->inspect('),
    'The server must use set-based identity queries and one multi-row reservation insert, never the per-file duplicate inspector.'
);

$check(
    'preflight_skips_only_physically_present_exact_bytes',
    str_contains($duplicateDetector, 'public function locatePhysicalPath(array $row): ?string')
        && str_contains($preflight, '$locator->locatePhysicalPath($row)')
        && str_contains($preflight, '$physicalSize = filesize($physicalPath)')
        && str_contains($preflight, '$md5 . "\\0" . $sha1 . "\\0" . $size'),
    'Stale database identities must not suppress a contribution whose physical file is missing.'
);

$check(
    'guid_is_advisory_not_rejection',
    str_contains($preflight, '\'guid_match\' => $guidInfo')
        && str_contains($preflight, 'the physical hashes differ; it will be retained for admin review')
        && !str_contains($preflight, "reason' => 'guid_duplicate'"),
    'Matching GUID with different bytes must be flagged but still uploadable.'
);

$check(
    'public_surface_is_restricted_and_anonymous',
    !str_contains($page, 'catalog_require_admin')
        && !str_contains($preflightApi, 'catalog_api_require_admin')
        && !str_contains($uploadApi, 'catalog_api_require_admin')
        && str_contains($preflightApi, "catalog_api_require_csrf('public_upload')")
        && str_contains($uploadApi, "catalog_api_require_csrf('public_upload')")
        && str_contains($preflight, '$policy->isArchive($name) || $policy->isPakContainer($name)')
        && str_contains($page, 'ZIP, 7z, RAR, UMOD-family archives and PAK containers are intentionally excluded'),
    'The public route must not expose admin authorization/actions or high-risk archive/container ingestion.'
);

$check(
    'client_checks_and_batches_before_upload',
    str_contains($client, 'const BATCH_FILES = 100;')
        && str_contains($client, 'new Worker(workerUrl)')
        && str_contains($client, 'md5: String(inspection.md5 ||')
        && str_contains($client, 'sha1: String(inspection.sha1 ||')
        && str_contains($client, 'window.showDirectoryPicker')
        && str_contains($client, 'const guidOffset = version < 68 ? 44 : 36;')
        && str_contains($client, "JSON.stringify({files: checked.map")
        && str_contains($client, 'for (; index < accepted.length; index++)'),
    'Browser inspection must stay off the UI thread, send 100-file manifests, and transfer accepted files sequentially.'
);

$check(
    'stop_releases_current_batch_reservations',
    str_contains($client, 'async function cancelReservation(token)')
        && str_contains($client, "await postAction('cancel', token, true)")
        && str_contains($client, 'for (let pending = index; pending < accepted.length; pending++)')
        && str_contains($transfer, 'status="cancelled",active_identity_key=NULL'),
    'Stop/fatal aborts must release the active and not-yet-started reservations immediately.'
);

$check(
    'transport_is_sequential_token_bound_and_disk_safe',
    str_contains($transfer, 'Only one public upload may transfer at a time from this address.')
        && str_contains($transfer, 'Public upload chunk order mismatch: expected=')
        && str_contains($transfer, '$free !== false && (int)$free - (int)$chunkBytes < (int)$settings[\'min_free_bytes\']')
        && str_contains($transfer, 'received_bytes=?')
        && str_contains($transfer, 'next_chunk_index=?')
        && str_contains($transfer, 'public-uploads')
        && str_contains($transfer, 'submitter_ip=?'),
    'Public bytes must use a token/IP-bound secondary quarantine with ordered chunks and a hard free-space reserve.'
);

$check(
    'completion_and_worker_handoff_are_idempotent',
    str_contains($transfer, 'in_array($status, [\'uploaded\', \'processing\', \'unverified\', \'duplicate\'], true)')
        && str_contains($uploadApi, '$existingJobId = max(0, (int)($row[\'background_job_id\'] ?? 0));')
        && str_contains($uploadApi, 'if ($existingJobId > 0)')
        && str_contains($uploadApi, 'JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($uploadApi, '\'public-upload:\' . $publicUploadId')
        && str_contains($uploadApi, 'CatalogQueueWorkerStarter'),
    'Lost complete responses must reuse the same durable upload/job rather than creating duplicate processing.'
);

$check(
    'background_processing_is_authoritative',
    str_contains($handler, 'JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($handler, 'scanner_file_has_unreal_package_magic')
        && str_contains($handler, "hash_init('md5')")
        && str_contains($handler, "hash_init('sha1')")
        && str_contains($handler, 'CatalogRedirectArchiveProcessor')
        && str_contains($handler, 'stageBucketUpload(')
        && str_contains($handler, 'Public contribution upload; awaiting administrator review.')
        && str_contains($handler, 'JobType::REFRESH_UNVERIFIED_GAME_MATCHES'),
    'HTTP completion must only queue work; authoritative hashes, redirect handling, unverified staging and expensive matching belong to workers.'
);

$check(
    'worker_recovers_publish_gap',
    str_contains($transfer, 'public function ledgerForJob(')
        && str_contains($handler, 'recoverPublishedStage(')
        && str_contains($handler, 'WHERE md5=? AND sha1=? AND scan_status IN ("verified","unverified")')
        && str_contains($handler, "'quarantine_relative_path' => null"),
    'A crash after moving bytes into unverified storage but before ledger publication must recover by authoritative hashes.'
);

$check(
    'expired_quarantine_is_bounded_background_cleanup',
    str_contains($preflight, 'status="uploaded" AND background_job_id IS NULL')
        && str_contains($preflightApi, 'JobType::PRUNE_PUBLIC_UPLOADS')
        && str_contains($maintenance, 'private const BATCH_SIZE = 500;')
        && str_contains($maintenance, '$store->removeQuarantine($token)')
        && str_contains($maintenance, '$context->defer(1, $progress, false)'),
    'Expired partial/orphaned public uploads must release their identity and be pruned in bounded worker batches.'
);

$check(
    'job_routing_and_worker_reload',
    str_contains($jobType, "PROCESS_PUBLIC_UPLOAD = 'catalog.process_public_upload'")
        && str_contains($jobType, "PRUNE_PUBLIC_UPLOADS = 'catalog.prune_public_uploads'")
        && str_contains($factory, 'JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($factory, 'new CatalogPublicUploadJobHandler')
        && str_contains($factory, 'JobType::PRUNE_PUBLIC_UPLOADS')
        && str_contains($resource, "self::positiveKey('public-upload:'")
        && str_contains($workerVersion, 'CatalogPublicUploadTransferStore.php')
        && str_contains($workerVersion, 'CatalogPublicUploadJobHandler.php')
        && str_contains($workerVersion, 'CatalogPublicUploadMaintenanceJobHandler.php'),
    'Public processing/pruning must be registered, resource-limited and part of worker code-version reloads.'
);

$check(
    'landing_and_navigation_match_public_contribution_request',
    str_contains($nav, "catalog_nav_link('Contribute!',")
        && str_contains($landing, 'catalog/public-upload.php')
        && str_contains($landing, '<h2>Active development</h2>')
        && !str_contains(strtolower($landing), 'dns migration')
        && !str_contains($landing, 'Main app path:')
        && str_contains($landing, 'Contribute Unreal files')
        && str_contains($landing, 'Hard-drive donations are especially welcome.')
        && str_contains($landing, 'adding redundancy')
        && str_contains($landing, 'ue_game_catalog_stats')
        && str_contains($landing, 'verified_count')
        && str_contains($landing, 'verified_size')
        && str_contains($landing, 'Storage figures are the summed sizes of verified catalog files, not free disk space.'),
    'Public landing/menu must advertise contributions, redundancy/capacity support and cached per-game storage usage without the DNS migration/main-path text.'
);

$syntaxFailures = [];
foreach ([
    'migrations/202608280001_public_uploads.php',
    'migrations/202608280002_public_upload_active_identity.php',
    'src/Infrastructure/Settings/CatalogPublicUploadSettingsStore.php',
    'src/Infrastructure/Import/CatalogPublicUploadBatchPreflight.php',
    'src/Infrastructure/Import/CatalogPublicUploadTransferStore.php',
    'src/Infrastructure/Import/CatalogUploadDuplicateDetector.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadMaintenanceJobHandler.php',
    'src/Domain/Jobs/JobType.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'api/v1/public-upload-preflight.php',
    'api/v1/public-upload.php',
    'public-upload.php',
    'program-settings.php',
    'lib/CatalogSupportCore.php',
    '../index.php',
] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
$check('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
