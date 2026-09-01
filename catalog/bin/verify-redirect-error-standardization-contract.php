#!/usr/bin/env php
<?php
/** Read-only runtime contract for concise redirect-archive validation errors. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogRedirectArchivePayload.php';

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogRedirectArchiveStream;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueueSupport;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveValidationException;

$checks = [];
$failures = [];
$temporary = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$fixture = static function (string $bytes) use (&$temporary): string {
    $path = tempnam(sys_get_temp_dir(), 'unrealdb-redirect-error-');
    if (!is_string($path) || file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not create redirect error verifier fixture.');
    }
    $temporary[] = $path;
    return $path;
};
$caught = static function (string $path, string $name): ?Throwable {
    try {
        CatalogRedirectArchiveStream::decompressUz2($path, $name, 1024 * 1024, null, true);
    } catch (Throwable $error) {
        return $error;
    }
    return null;
};

try {
    $truncated = $caught(
        $fixture(pack('V2', 12822, 32768) . str_repeat('x', 8954)),
        "AS-You_Can't_Die_(Trial-v2).ut2.uz2"
    );
    $truncatedMessage = $truncated?->getMessage() ?? '';
    $record(
        'truncated_payload_states_exact_missing_bytes',
        $truncated instanceof CatalogRedirectArchiveValidationException
            && $truncated->validationCode() === 'uz2.incomplete_record_payload'
            && str_contains($truncatedMessage, 'UZ2 file is incomplete/cut by 3868 bytes')
            && str_contains($truncatedMessage, 'compressed_size=12822')
            && str_contains($truncatedMessage, 'uncompressed_size=32768')
            && str_contains($truncatedMessage, 'available_bytes=8954')
            && str_contains($truncatedMessage, 'actual_file_size=8962')
            && str_contains($truncatedMessage, 'required_file_size=12830')
            && ($truncated->validationArguments()['missing_bytes'] ?? null) === 3868,
        $truncatedMessage
    );

    $badPayload = hex2bin('789ced5d31681bdb') . str_repeat("\0", 5304 - 8);
    if (!is_string($badPayload)) {
        throw new RuntimeException('Could not create bad-zlib verifier payload.');
    }
    $decompression = $caught(
        $fixture(pack('V2', strlen($badPayload), 32768) . $badPayload),
        'AS-UT2K4-JanisTrial.ut2.uz2'
    );
    $decompressionMessage = $decompression?->getMessage() ?? '';
    $record(
        'decompression_failure_is_plain_and_proven',
        $decompression instanceof CatalogRedirectArchiveValidationException
            && $decompression->validationCode() === 'uz2.decompression_failed'
            && str_contains($decompressionMessage, 'Cannot decompress UZ2 record 1')
            && str_contains($decompressionMessage, 'compressed_size=5304')
            && str_contains($decompressionMessage, 'uncompressed_size=32768')
            && str_contains($decompressionMessage, 'payload_head_hex=789ced5d31681bdb')
            && !str_contains($decompressionMessage, 'available decoders'),
        $decompressionMessage
    );

    $plain = 'TEXT-not-an-Unreal-package';
    $compressed = gzcompress($plain);
    if (!is_string($compressed)) {
        throw new RuntimeException('gzcompress is unavailable for redirect error verifier.');
    }
    $magic = $caught(
        $fixture(pack('V2', strlen($compressed), strlen($plain)) . $compressed),
        'AS-Demonvein-Trials.ut2.uz2'
    );
    $magicMessage = $magic?->getMessage() ?? '';
    $record(
        'magic_failure_shows_actual_and_expected_bytes',
        $magic instanceof CatalogRedirectArchiveValidationException
            && $magic->validationCode() === 'uz2.magic_not_found'
            && str_contains($magicMessage, 'Magic not found: AS-Demonvein-Trials.ut2.uz2')
            && str_contains($magicMessage, 'redirect_format=UZ2')
            && str_contains($magicMessage, 'actual_magic_hex=54455854')
            && str_contains($magicMessage, 'actual_magic_text=TEXT')
            && str_contains($magicMessage, 'expected_magic_hex=C1832A9E|9E2A83C1'),
        $magicMessage
    );

    $record(
        'queue_persists_plain_validation_reason',
        $truncated instanceof Throwable
            && PdoJobQueueSupport::trimError($truncated) === $truncatedMessage
            && !str_contains(PdoJobQueueSupport::trimError($truncated), 'RuntimeException:')
            && !str_contains(PdoJobQueueSupport::trimError($truncated), 'dead_letter'),
        $truncated instanceof Throwable ? PdoJobQueueSupport::trimError($truncated) : 'No exception captured.'
    );

    $record(
        'bad_redirect_bytes_are_not_retried_or_reported_as_worker_faults',
        JobFailureRetryPolicy::isDeterministicFailureText(JobType::PROCESS_BUCKET_UPLOAD, $truncatedMessage)
            && JobFailureRetryPolicy::isDeterministicFailureText(JobType::PROCESS_BUCKET_UPLOAD, $decompressionMessage)
            && JobFailureRetryPolicy::isDeterministicFailureText(JobType::PROCESS_BUCKET_UPLOAD, $magicMessage)
            && !JobFailureRetryPolicy::isInvalidPackageContentText(JobType::PROCESS_BUCKET_UPLOAD, $truncatedMessage)
            && !JobFailureRetryPolicy::isInvalidPackageContentText(JobType::PROCESS_BUCKET_UPLOAD, $decompressionMessage)
            && !JobFailureRetryPolicy::isInvalidPackageContentText(JobType::PROCESS_BUCKET_UPLOAD, $magicMessage),
        'Truncated, undecompressible and bad-magic UZ2 bytes must stop after the first attempt.'
    );

    $inspector = (string)file_get_contents($root . '/assets/upload-file-inspector-worker.js');
    $compatibleInspector = (string)file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
    $archiveWorker = (string)file_get_contents($root . '/assets/public-upload-archive-worker.js');
    $redirectReader = (string)file_get_contents($root . '/assets/unreal-redirect-reader.js');
    $record(
        'browser_paths_share_one_uz2_reader',
        str_contains($redirectReader, 'async function readUz2(options)')
            && str_contains($redirectReader, 'UZ2 file is incomplete/cut by ')
            && str_contains($redirectReader, 'Cannot decompress UZ2 record ')
            && str_contains($redirectReader, 'actual_magic_hex=')
            && str_contains($inspector, 'UnrealDbRedirectReader.readUz2(')
            && str_contains($archiveWorker, 'UnrealDbRedirectReader.readUz2(')
            && str_contains($compatibleInspector, 'UnrealDbRedirectReader.validateUz2Header(')
            && str_contains($inspector, "new URL('unreal-redirect-reader.js'")
            && str_contains($archiveWorker, "new URL('unreal-redirect-reader.js'")
            && str_contains($compatibleInspector, "new URL('unreal-redirect-reader.js'")
            && !str_contains($inspector, 'UZ2 file is incomplete/cut by ')
            && !str_contains($archiveWorker, 'UZ2 file is incomplete/cut by ')
            && !str_contains($compatibleInspector, 'UZ2 file is incomplete/cut by ')
            && str_contains($inspector, 'full record validation follows')
            && !str_contains($inspector, 'does not contain a valid first Epic redirect record')
            && !str_contains($inspector, 'Invalid Epic UZ2 record')
            && !str_contains($archiveWorker, 'Invalid Epic UZ2 record'),
        'Direct uploads, archive members and fast header checks must use the same UZ2 parser and diagnostics.'
    );

    $fingerprint = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $workerFactory = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
    $record(
        'system_error_sentence_omits_queue_and_temporary_path_noise',
        str_contains($workerFactory, '$message = trim($error->getMessage())')
            && !str_contains($workerFactory, "\$job->type . ' #'")
            && !str_contains($workerFactory, "\$message .= ' Source: '")
            && !str_contains($workerFactory, "\$message .= ' Archive: '"),
        'Job id, disposition and source provenance belong in structured context, not the error sentence.'
    );
    $record(
        'detached_workers_restart_for_redirect_decoder_changes',
        str_contains($fingerprint, '/Redirect/CatalogRedirectArchiveValidationException.php')
            && str_contains($fingerprint, '/Redirect/CatalogRedirectArchiveProcessor.php')
            && str_contains($fingerprint, '/Jobs/CatalogRedirectArchiveStream.php')
            && str_contains($fingerprint, '/lib/CatalogRedirectArchivePayload.php'),
        'The worker code fingerprint must include every server-side redirect diagnostic implementation.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, get_class($error) . ': ' . $error->getMessage());
} finally {
    foreach ($temporary as $path) {
        @unlink($path);
    }
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
