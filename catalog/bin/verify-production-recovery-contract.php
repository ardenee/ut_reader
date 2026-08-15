#!/usr/bin/env php
<?php
/**
 * Read-only production fault-recovery contract verification.
 *
 * Guards the exact invariants hardened after tracing queue starvation and
 * interrupted verified-import publication. Runtime concurrency/starvation is
 * exercised separately by verify-job-claim-concurrency.php and
 * verify-job-claim-starvation.php against MySQL.
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
    $source = @file_get_contents($path);
    return is_string($source) ? $source : '';
};

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$claimer = $read('src/Infrastructure/Persistence/PdoJobClaimer.php');
$record(
    'queue_skips_semantic_blockers',
    $claimer !== ''
        && str_contains($claimer, '$blockedResourceClasses')
        && str_contains($claimer, '$blockedConcurrencyKeys')
        && str_contains($claimer, 'rememberBlockedDimension(')
        && str_contains($claimer, 'NOT IN (')
        && !str_contains($claimer, 'MAX_BLOCKED_CANDIDATES_PER_SCOPE'),
    'claimer must skip saturated resource classes/occupied keys rather than stop after a row-count window'
);

$guard = $read('src/Infrastructure/Persistence/PdoJobAdmissionGuard.php');
$record(
    'admission_exposes_block_reason',
    $guard !== ''
        && str_contains($guard, 'acquireWithBlocker(')
        && str_contains($guard, 'blocked_dimension')
        && str_contains($guard, 'public function decision('),
    'resource/key blockers must be distinguishable so unrelated runnable jobs remain claimable'
);
$record(
    'admission_db_failure_not_hidden',
    str_contains($guard, 'MySQL did not return a valid admission-lock result.')
        && !str_contains($guard, "catch (Throwable) {\n            return false;"),
    'GET_LOCK SQL/driver failures must not masquerade as ordinary contention'
);

$health = $read('src/Infrastructure/Metadata/VerifiedCompactMetadataHealth.php');
$record(
    'metadata_health_is_physical',
    $health !== ''
        && str_contains($health, 'BlockedCompressedMetadataReader')
        && str_contains($health, '->verify($fileId)')
        && str_contains($health, 'VerifiedMetadataPublicationState::ready')
        && str_contains($health, 'VerifiedMetadataPublicationState::failed'),
    'format-2 DB registration alone must never be treated as proof of a healthy .uedb2 container'
);

$unverifiedFinalizer = $read('src/Infrastructure/Unverified/CatalogUnverifiedCompactMetadataFinalizer.php');
$record(
    'unverified_metadata_self_heals',
    $unverifiedFinalizer !== ''
        && str_contains($unverifiedFinalizer, 'VerifiedCompactMetadataHealth::verify')
        && str_contains($unverifiedFinalizer, '$this->store->has($fileId)')
        && str_contains($unverifiedFinalizer, 'VerifiedMetadataPublicationState::pending')
        && str_contains($unverifiedFinalizer, 'VerifiedMetadataPublicationState::ready')
        && str_contains($unverifiedFinalizer, 'VerifiedMetadataPublicationState::failed')
        && str_contains($unverifiedFinalizer, 'cleanupStaging('),
    'promotion recovery must verify physical metadata and republish from retained staging when possible'
);

$dependencyRecovery = $read('src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php');
$finalizePos = strpos($dependencyRecovery, '$this->compactFinalizer->finalize($fileId)');
$queuePos = strpos($dependencyRecovery, '$jobs = $this->queueRefresh(');
$record(
    'dependency_queue_requires_verified_metadata',
    $dependencyRecovery !== ''
        && $finalizePos !== false
        && $queuePos !== false
        && $finalizePos < $queuePos
        && !str_contains($dependencyRecovery, 'SELECT format_version FROM ue_file_metadata'),
    'dependency work must not be queued from a stale format_version registration'
);
$record(
    'unverified_recovery_has_durable_compact_fallback',
    str_contains($dependencyRecovery, 'JobType::REPAIR_COMPACT_METADATA_FILE')
        && str_contains($dependencyRecovery, "'compact-metadata-repair:' . \$fileId")
        && str_contains($dependencyRecovery, "'compact_repair_job_id'")
        && str_contains($dependencyRecovery, "'recovery_queued' => true"),
    'when retained staging cannot repair metadata, the verified row must receive a durable bounded repair job'
);

$nonBlocking = $read('src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php');
$record(
    'duplicate_retry_checks_metadata_physically',
    $nonBlocking !== ''
        && str_contains($nonBlocking, 'VerifiedCompactMetadataHealth::healthy')
        && str_contains($nonBlocking, 'VerifiedCompactMetadataHealth::verify')
        && !str_contains($nonBlocking, 'SELECT m.format_version'),
    'duplicate retry must repair missing/corrupt metadata before reporting success'
);
$record(
    'publication_failures_are_retryable',
    str_contains($nonBlocking, "'package alias dependency refresh failed'")
        && str_contains($nonBlocking, "'post-import dependency recovery queue failed'")
        && str_contains($nonBlocking, "'compact metadata'")
        && str_contains($nonBlocking, "'blocked metadata'"),
    'post-persistence publication failures are infrastructure failures, not bad-package terminal results'
);

$repair = $read('src/Infrastructure/Jobs/CatalogCompactMetadataRepairJobHandler.php');
$record(
    'repair_job_synchronizes_publication_state',
    $repair !== ''
        && str_contains($repair, 'VerifiedCompactMetadataHealth::healthy')
        && !str_contains($repair, 'new BlockedCompressedMetadataReader'),
    'repair verification must keep metadata_status aligned with physical health'
);

$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$identity = $read('src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php');
$publisher = $read('src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php');
$dependencyCoordinator = $read('src/Infrastructure/Import/CatalogVerifiedPackageDependencyCoordinator.php');
$record(
    'duplicate_targets_only_verified_rows',
    $identity !== ''
        && str_contains($identity, 'WHERE game_id=? AND scan_status="verified"')
        && str_contains($importer, 'findVerifiedDuplicate('),
    'failed/duplicate/unverified history rows must not absorb a physical verified import'
);
$record(
    'verified_import_has_one_compact_publication_pass',
    str_contains($publisher, 'VerifiedFileCompactMetadataFinalizer::finalizeParsed(')
        && !str_contains($publisher, 'VerifiedFileCompactMetadataFinalizer::finalize(')
        && str_contains($importer, '$this->publisher->publishMetadata('),
    'the package-import port must not immediately re-read the container it just published'
);
$record(
    'canonical_dependency_failure_has_durable_fallback',
    str_contains($dependencyCoordinator, 'CatalogPostImportDependencyQueue::enqueue(')
        && str_contains($dependencyCoordinator, 'post-import dependency recovery queue failed')
        && str_contains($importer, '$this->dependencies->refreshCanonical('),
    'a synchronous dependency-refresh failure must either record durable repair work or fail the import attempt'
);
$record(
    'alias_dependency_failure_retries',
    str_contains($identity, '\\catalog_package_alias_add(')
        && str_contains($importer, '$aliasAlreadyExisted = !$aliasAdded;')
        && str_contains($dependencyCoordinator, 'Package alias dependency refresh failed')
        && str_contains($importer, '$this->dependencies->refreshAlias('),
    'idempotent alias insertion must not convert a failed dependency publication into success'
);

$starvationVerifier = $read('bin/verify-job-claim-starvation.php');
$record(
    'blocked_prefix_regression_exists',
    $starvationVerifier !== ''
        && str_contains($starvationVerifier, 'for ($index = 0; $index < 64; $index++)')
        && str_contains($starvationVerifier, "'resource_blocked_prefix_64'")
        && str_contains($starvationVerifier, "'concurrency_key_blocked_prefix_64'"),
    'manual MySQL verification must reproduce a blocked prefix larger than the retired 32-row window'
);

$criticalPhp = [
    'bin/verify-production-recovery-contract.php',
    'bin/verify-job-claim-starvation.php',
    'src/Infrastructure/Persistence/PdoJobAdmissionGuard.php',
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
    'src/Infrastructure/Metadata/VerifiedCompactMetadataHealth.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedCompactMetadataFinalizer.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php',
    'src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php',
    'src/Infrastructure/Jobs/CatalogCompactMetadataRepairJobHandler.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
    'src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageDependencyCoordinator.php',
    'src/Application/Import/CatalogVerifiedPackageInspection.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l manually on guarded files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
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
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
