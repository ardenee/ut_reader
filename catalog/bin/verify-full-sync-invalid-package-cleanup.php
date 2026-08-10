#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that Full Sync retires authoritative invalid packages cleanly instead of keeping broken verified rows.
 * Role: Read-only regression gate for invalid-package detection, typed validation and cleanup routing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'lib/GameProfiles.php',
    'src/Infrastructure/Import/CatalogInvalidPackageException.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
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
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

try {
    require_once $root . '/lib/GameProfiles.php';
    $record(
        'epic_ue3_detection_is_bounded',
        gp_engine_from_version(334) === 'UE3'
            && gp_engine_from_version(512) === 'UE3'
            && gp_engine_from_version(867) === 'UE3'
            && gp_engine_from_version(868) === null
            && gp_engine_from_version(8261) === null
            && gp_engine_from_version(8320) === null,
        'Only Epic UE3 package versions 334..867 may provide a UE3 engine hint; 8261/8320 must not self-classify as UE3.'
    );
} catch (Throwable $error) {
    $record('epic_ue3_detection_is_bounded', false, get_class($error) . ': ' . $error->getMessage());
}

$profiles = $read('lib/GameProfiles.php');
$exception = $read('src/Infrastructure/Import/CatalogInvalidPackageException.php');
$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$handler = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');
$removal = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php');

$record(
    'extension_engine_fallback_is_removed',
    !str_contains($profiles, 'function gp_detect_from_extension')
        && !str_contains($importer, 'gp_detect_from_extension'),
    'There must be no filename/extension engine detector available to Full Sync or package import.'
);

$record(
    'primary_importer_has_no_extension_profile_gate',
    !str_contains($importer, 'Extension not allowed by assigned profile')
        && !str_contains($importer, '$profileExtensions')
        && str_contains($importer, 'No supported package reader can be selected from serialized header data.'),
    'Primary package import must never accept/reject or choose a reader from the filename extension.'
);

$record(
    'invalid_package_has_explicit_exception_type',
    str_contains($exception, 'final class CatalogInvalidPackageException extends RuntimeException')
        && substr_count($importer, 'throw new CatalogInvalidPackageException(') >= 2,
    'Profile mismatch and authoritative reader validation failures must be distinguishable from infrastructure failures.'
);

$typedCatch = strpos($handler, 'catch (CatalogInvalidPackageException $error)');
$genericCatch = strpos($handler, 'catch (Throwable $error)', $typedCatch === false ? 0 : $typedCatch);
$record(
    'full_sync_catches_invalid_before_generic_failure',
    $typedCatch !== false && $genericCatch !== false && $typedCatch < $genericCatch,
    'Invalid package retirement must run before the generic reimport-error path.'
);

$record(
    'full_sync_removes_invalid_verified_package',
    str_contains($handler, 'new CatalogFileMaintenanceRemovalService(')
        && str_contains($handler, "->remove((int)\$file['id'], null, true)")
        && str_contains($handler, '$removedInvalid++')
        && str_contains($handler, "'removed_invalid' => \$removedInvalid"),
    'A validated-invalid verified row must use the normal maintenance removal service and be reported as removed_invalid.'
);

$logMethodStart = strpos($handler, 'private function recordInvalidRemoval(');
$logMethodEnd = $logMethodStart === false
    ? false
    : strpos($handler, 'private function recordFailure(', $logMethodStart);
$logMethod = $logMethodStart !== false && $logMethodEnd !== false
    ? substr($handler, $logMethodStart, $logMethodEnd - $logMethodStart)
    : '';
$record(
    'invalid_removal_is_warning_log_not_system_error',
    $logMethod !== ''
        && str_contains($logMethod, "\\app_log(\$this->db, 'WARN', 'FULL_SYNC_INVALID_PACKAGE_REMOVED'")
        && !str_contains($logMethod, 'CatalogSystemErrorRecorder::record'),
    'Successful invalid-package retirement must log the reason as a warning and must not create a Full Sync System Error.'
);

$record(
    'cleanup_failure_remains_real_error',
    $typedCatch !== false
        && str_contains($handler, "'sync_remove_invalid'")
        && str_contains($handler, 'catch (Throwable $removeError)')
        && str_contains($handler, '$reimportFailures++'),
    'Failure to remove an invalid row/storage package is an infrastructure failure and must remain visible as an error.'
);

$record(
    'invalid_removal_uses_complete_cleanup_contract',
    str_contains($removal, '$support->deleteFileProjections($fileId)')
        && str_contains($removal, "DELETE FROM ue_files WHERE id=?")
        && str_contains($removal, "@unlink(\$stagedPath)")
        && str_contains($removal, "@unlink(\$metadataPath)"),
    'Invalid retirement must remove compact projections, the verified row, staged storage and compact metadata without leaving catalog debris.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
