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
$inspector = $read('assets/upload-file-inspector-worker.js');
$legacyUzDecoder = $read('assets/legacy-uz-decoder.js');
$compatibleInspector = $read('assets/upload-file-inspector-worker-compatible.js');
$archiveWorker = $read('assets/public-upload-archive-worker.js');
$archiveInstaller = $read('bin/install-browser-archive-decoder.php');
$archiveVendorReadme = $read('assets/vendor/7z-wasm/README.md');
$programSettings = $read('program-settings.php');
$unverifiedPage = $read('unverified-files.php');
$unverifiedQuery = $read('src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php');
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
    'preflight_mysql_lock_name_stays_within_limit',
    str_contains($preflight, '$lockName = \'udb-pubup-ip-\' . substr(hash(\'sha256\', $packedIp), 0, 40);')
        && strlen('udb-pubup-ip-' . str_repeat('a', 40)) <= 64
        && str_contains($preflight, 'SELECT GET_LOCK(?,5)')
        && str_contains($preflight, 'SELECT RELEASE_LOCK(?)'),
    'Public-upload per-IP MySQL lock names must remain at or below MySQL\'s 64-character user-lock limit.'
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
        && str_contains($preflight, '$md5 . "\\0" . $sha1 . "\\0" . $size')
        && str_contains($preflight, "'confirmed' => \$matches")
        && str_contains($preflight, "'unconfirmed' => \$unconfirmed")
        && str_contains($preflight, 'Upload allowed as a repair candidate: catalog file #'),
    'Stale/unresolvable database identities must not suppress a useful contribution; preflight must explain when an exact DB identity cannot be physically confirmed.'
);

$check(
    'guid_is_advisory_not_rejection',
    str_contains($preflight, '\'guid_match\' => $guidInfo')
        && str_contains($preflight, 'This package GUID already appears in the catalog')
        && str_contains($preflight, 'no physically confirmed exact byte match was found')
        && !str_contains($preflight, "reason' => 'guid_duplicate'"),
    'Matching GUID without a physically confirmed exact byte match must be flagged but still uploadable.'
);

$check(
    'public_upload_csrf_session_is_persisted',
    str_contains($page, 'catalog_start_session(true);')
        && str_contains($page, "header('Cache-Control: no-store, private')")
        && str_contains($page, "data-csrf=\"' . catalog_h(catalog_csrf('public_upload'))")
        && str_contains($client, "'X-CSRF-Token': csrf")
        && str_contains($preflightApi, "catalog_api_require_csrf('public_upload')")
        && str_contains($uploadApi, "catalog_api_require_csrf('public_upload')"),
    'Anonymous contribution GET must persist its PHP session before generating the CSRF token used by later POST requests.'
);

$check(
    'public_surface_is_restricted_and_anonymous',
    !str_contains($page, 'catalog_require_admin')
        && !str_contains($preflightApi, 'catalog_api_require_admin')
        && !str_contains($uploadApi, 'catalog_api_require_admin')
        && str_contains($preflightApi, "catalog_api_require_csrf('public_upload')")
        && str_contains($uploadApi, "catalog_api_require_csrf('public_upload')")
        && str_contains($preflight, '$policy->isArchive($name) || $policy->isPakContainer($name)')
        && str_contains($page, 'Selected ZIP, RAR and 7z archives are processed only in the browser')
        && str_contains($page, 'the original archive is never uploaded')
        && str_contains($page, 'Public upload accepts normal Unreal package files plus .uz, .uz2 and .uz3 redirects')
        && str_contains($page, 'UMOD-family archives and PAK containers remain excluded'),
    'The public route must keep archive containers off the server while allowing browser-only ZIP/RAR/7z source inspection.'
);

$check(
    'redirect_identity_is_decoded_before_preflight',
    str_contains($inspector, 'async function inspectUz(id, file, maxFileBytes)')
        && str_contains($inspector, 'async function inspectUz2(id, file)')
        && str_contains($inspector, 'async function inspectUz3(id, file)')
        && str_contains($inspector, 'UnrealDbLegacyUzDecoder.decode(encoded,limit)')
        && str_contains($inspector, "new DecompressionStream('deflate')")
        && str_contains($legacyUzDecoder, 'function decodeHuffman(data,limit)')
        && str_contains($legacyUzDecoder, 'function decodeMtf(data)')
        && str_contains($legacyUzDecoder, 'function decodeBwt(data,limit)')
        && str_contains($legacyUzDecoder, 'function decodeRle(data,limit)')
        && str_contains($legacyUzDecoder, 'signature!==1234 && signature!==5678')
        && str_contains($legacyUzDecoder, "'epic-uz-5678-huffman+rle+mtf+bwt+rle'")
        && str_contains($legacyUzDecoder, "'epic-uz-1234-huffman+mtf+bwt+rle'")
        && str_contains($inspector, 'identity_size:output.length')
        && str_contains($inspector, 'md5:md5.digestHex()')
        && str_contains($inspector, 'sha1:sha1.digestHex()')
        && str_contains($client, 'identity_size: identitySize')
        && str_contains($preflight, '$identitySize = (int)($item[\'identity_size\']')
        && str_contains($preflight, '$md5 . "\0" . $sha1 . "\0" . $identitySize')
        && str_contains($preflight, "if (\$md5 === '' || \$sha1 === '' || \$identitySize < 1)")
        && !str_contains($compatibleInspector, 'Legacy .uz FCodec redirects are not accepted by the public uploader yet')
        && str_contains($compatibleInspector, 'dispatchToInspector(data);')
        && str_contains($client, "['uz', 'uz2', 'uz3'].forEach"),
    'UZ/UZ2/UZ3 must be decoded and hashed in the browser; the 100-file manifest must compare decompressed package identity, not compressed wrapper size.'
);

$check(
    'per_file_completion_does_not_reconcile_workers',
    str_contains($uploadApi, "if (\$action === 'wake')")
        && str_contains($client, 'async function wakePublicQueue(batchNumber)')
        && str_contains($client, 'await wakePublicQueue(batchNumber)')
        && str_contains($uploadApi, "'Upload complete. Validation is queued in the background.'")
        && str_contains($uploadApi, 'CatalogQueueWorkerStarter'),
    'Each file completion must return after durable job enqueue; worker-pool reconciliation is performed once at the batch wake boundary.'
);

$check(
    'worker_pool_status_is_informational',
    str_contains($client, "'info'")
        && str_contains($client, "'Background validation'")
        && str_contains($client, 'Worker pool status: ')
        && str_contains($client, 'Worker wake status: ')
        && str_contains($page, '.public-upload-log-info{color:#cbd5e1}')
        && !str_contains($client, 'Worker pool warning:')
        && !str_contains($client, "'warning',\n                            'Background validation'")
        && !str_contains($client, "'failed',\n                            'Background validation'"),
    'A detached-worker shortfall after durable enqueue is informational and must not be presented as an upload failure or warning.'
);

$check(
    'public_upload_reports_terminal_processing_result',
    str_contains($transfer, 'public function statusesForContributor(array $uploadTokens, string $ipAddress): array')
        && str_contains($uploadApi, "if (\$action === 'status_batch')")
        && str_contains($client, 'async function waitForValidationResults(entries)')
        && str_contains($client, "status === 'unverified'")
        && str_contains($client, "status === 'duplicate'")
        && str_contains($client, "status === 'failed'")
        && str_contains($client, "'Ready for administrator review as unverified file #'")
        && str_contains($client, "counters.duplicates + ' post-upload duplicates'")
        && str_contains($client, "counters.uploaded + ' transferred'"),
    'The public page must distinguish transfer completion from terminal background validation and expose the resulting unverified/duplicate/failed state.'
);

$check(
    'failed_public_upload_source_is_retained',
    str_contains($handler, 'Failed extraction/validation is the one case where the original')
        && !str_contains(
            substr(
                $handler,
                (int)strrpos($handler, '} catch (\\Throwable $error)'),
                900
            ),
            '$store->removeQuarantine($token);'
        )
        && str_contains($handler, "'status' => 'failed'")
        && str_contains($handler, "'active_identity_key' => null"),
    'A failed public extraction/validation must retain its original quarantine source for diagnosis rather than deleting it.'
);

$check(
    'unverified_admin_exposes_pending_public_contributions',
    str_contains($unverifiedQuery, 'public_uploads')
        && str_contains($unverifiedQuery, 'WHERE status IN ("uploaded","processing","failed","duplicate")')
        && str_contains($unverifiedPage, 'Public contribution status')
        && str_contains($unverifiedPage, 'Recent public upload processing outcomes and items still waiting for background validation.')
        && str_contains($unverifiedPage, 'Source contribution:')
        && str_contains($unverifiedPage, 'file-info.php?id=')
        && str_contains($unverifiedPage, 'Delete selected entries')
        && str_contains($unverifiedPage, "name=\"public_upload_ids[]\"")
        && str_contains($unverifiedPage, 'uv-public-contribution')
        && str_contains($unverifiedPage, "catalog_check_csrf('unverified-files')")
        && str_contains($transfer, 'public function deleteTerminalForAdmin(array $ids): array')
        && str_contains($transfer, 'AND status IN ("duplicate","failed","cancelled","expired","rejected")'),
    'Unverified Files must make pending/failed public contributions visible and show the original contribution path once staged.'
);

$check(
    'browser_archive_sources_are_member_only_and_memory_bounded',
    str_contains($client, "const ARCHIVE_EXTENSIONS = new Set(['zip', 'rar', '7z'])")
        && str_contains($client, 'await oneShotArchiveList(file, archiveLabel)')
        && str_contains($client, 'await openArchiveMember(')
        && str_contains($client, 'Original archive will not be uploaded.')
        && str_contains($client, 'activeArchiveStops')
        && str_contains($archiveWorker, 'module.FS.mount(module.WORKERFS, {files:[file]},')
        && !str_contains($archiveWorker, 'file.arrayBuffer()')
        && str_contains($archiveWorker, "['x', '-y', '-bd', '-bb0', '-spd', '-o/out'")
        && str_contains($archiveWorker, 'offset !== nextReadOffset')
        && str_contains($archiveWorker, "if (extension === 'uz') return inspectUz(id, name, maxFileBytes)")
        && str_contains($archiveWorker, 'UnrealDbLegacyUzDecoder.decode(encoded,limit)')
        && str_contains($archiveWorker, 'Archive member path contains an unsafe path segment.')
        && str_contains($archiveInstaller, 'EXPECTED_GIT_BLOB_SHA1')
        && str_contains($archiveInstaller, '337cfa5ac2e9ed01d9dfc5b9aeb8f2742e025502')
        && str_contains($archiveInstaller, "'verify_peer' => true")
        && str_contains($archiveInstaller, "'verify_peer_name' => true")
        && str_contains($archiveInstaller, 'unset($curl);')
        && !str_contains($archiveInstaller, 'curl_close(')
        && str_contains($archiveVendorReadme, 'WORKERFS'),
    'ZIP/RAR/7z must remain browser-only sources, mount without whole-archive copies, expose one member sequentially, and free each member worker after use.'
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
        && str_contains($transfer, '$free !== false && (int)$free - $maximumDecoded < (int)$settings[\'min_free_bytes\']')
        && str_contains($transfer, 'received_bytes=?')
        && str_contains($transfer, 'next_chunk_index=?')
        && str_contains($transfer, 'public-uploads')
        && str_contains($transfer, 'submitter_ip=?'),
    'Public bytes must use a token/IP-bound secondary quarantine with ordered logical chunks and a hard free-space reserve.'
);

$check(
    'gzip_transport_preserves_original_package_identity',
    str_contains($client, "new CompressionStream('gzip')")
        && str_contains($client, 'TRANSPORT_COMPRESSION_RATIO = 0.90')
        && str_contains($client, "data.append('content_encoding', contentEncoding || 'identity')")
        && str_contains($uploadApi, "(string)(\$_POST['content_encoding'] ?? 'identity')")
        && str_contains($transfer, "in_array(\$encoding, ['identity', 'gzip'], true)")
        && str_contains($transfer, 'private function appendTransportChunk(')
        && str_contains($transfer, '$decodedBytes = $this->appendTransportChunk(')
        && str_contains($transfer, '@ftruncate($output, $expectedOffset)')
        && str_contains($transfer, '$received + $decodedBytes')
        && str_contains($transfer, 'Decoded public upload chunk exceeds the allowed logical chunk size'),
    'Optional gzip must be a per-chunk transport envelope only: staging and reservation byte counts remain original package bytes.'
);

$check(
    'completion_and_worker_handoff_are_idempotent',
    str_contains($transfer, 'in_array($status, [\'uploaded\', \'processing\', \'unverified\', \'duplicate\'], true)')
        && str_contains($uploadApi, '$existingJobId = max(0, (int)($row[\'background_job_id\'] ?? 0));')
        && str_contains($uploadApi, 'if ($existingJobId > 0)')
        && str_contains($uploadApi, 'JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($uploadApi, '\'public-upload:\' . $publicUploadId')
        && str_contains($uploadApi, "if (\$action === 'wake')")
        && str_contains($uploadApi, 'CatalogQueueWorkerStarter'),
    'Lost complete responses must reuse the same durable upload/job, while worker startup stays at the explicit batch wake boundary.'
);

$check(
    'background_processing_is_authoritative',
    str_contains($handler, 'JobType::PROCESS_PUBLIC_UPLOAD')
        && str_contains($handler, 'scanner_file_has_unreal_package_magic')
        && str_contains($handler, "hash_init('md5')")
        && str_contains($handler, "hash_init('sha1')")
        && str_contains($handler, "Public upload MD5 mismatch: client=")
        && str_contains($handler, "Public upload SHA-1 mismatch: client=")
        && str_contains($handler, 'CatalogRedirectArchiveProcessor')
        && str_contains($handler, 'new CatalogUploadDuplicateDetector($this->db, $this->config)')
        && str_contains($handler, "is_array(\$duplicateCheck['duplicate'] ?? null)")
        && !str_contains($handler, 'private function exactExisting(')
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
        && str_contains($workerVersion, 'CatalogUploadDuplicateDetector.php')
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
        && str_contains($landing, 'The project is not looking for money at this time.')
        && str_contains($landing, 'including a used drive')
        && str_contains($landing, 'no intention to burden anyone')
        && str_contains($landing, 'catalog/feedback.php')
        && str_contains($landing, 'adding redundancy and capacity')
        && str_contains($landing, 'ue_game_catalog_stats')
        && str_contains($landing, 'SELECT scan_status,COUNT(*) record_count FROM ue_files GROUP BY scan_status')
        && str_contains($landing, 'verified_count')
        && str_contains($landing, 'unverified_count')
        && str_contains($landing, 'failed_count')
        && str_contains($landing, 'duplicate_count')
        && str_contains($landing, 'information_schema.tables')
        && str_contains($landing, 'Database size')
        && str_contains($landing, 'Data + indexes currently allocated by MySQL')
        && str_contains($landing, 'File-storage figures are the summed sizes of verified catalog files. Database size is MySQL\'s currently allocated data + index size.'),
    'Public landing/menu must advertise contributions, low-pressure drive support/contact, redundancy/capacity needs, database size, cached per-game storage and indexed file-state metrics without the DNS migration/main-path text.'
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
    'unverified-files.php',
    'src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
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
