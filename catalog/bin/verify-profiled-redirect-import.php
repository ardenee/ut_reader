#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies profiled-upload redirect jobs preserve wrapper identity while surfacing the real package rejection.
 * Role: Read-only/no-database regression gate for staged .uz/.uz2/.uz3 import handling.
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
    'lib/CatalogRedirectArchivePayload.php',
    'lib/CatalogRedirectCodec.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
    'src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php',
    'src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    'src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php',
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

require_once $root . '/bootstrap/autoload.php';

try {
    $hydrator = new UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator([]);
    $rows = [[
        'id' => 123,
        'job_type' => UnrealDb\Catalog\Domain\Jobs\JobType::IMPORT_STAGED_PACKAGE,
        'status' => 'completed',
        'payload_json' => json_encode([
            'original_name' => 'ChameleonSkins.utx.uz2',
            'source_relative_path' => 'ChameleonSkins.utx.uz2',
        ], JSON_THROW_ON_ERROR),
        'progress_json' => json_encode([
            'stage' => 'rejected',
            'percent' => 100,
            'message' => 'Unsupported or invalid input was discarded: decoded output is not an Unreal package.',
        ], JSON_THROW_ON_ERROR),
        'result_json' => json_encode([
            'operation' => 'import_staged_package',
            'status' => 'rejected',
            'file_id' => 0,
            'message' => 'decoded output is not an Unreal package.',
            'original_name' => 'ChameleonSkins.utx',
            'source_relative_path' => 'ChameleonSkins.utx',
            'decompressed' => true,
            'redirect_decoder' => 'epic-uz2-zlib-uncompress-stream',
            'redirect_source_name' => 'ChameleonSkins.utx.uz2',
        ], JSON_THROW_ON_ERROR),
    ]];
    $hydrated = $hydrator->hydrate($rows)[0] ?? [];
    $result = is_array($hydrated['result'] ?? null) ? $hydrated['result'] : [];
    $record(
        'staged_redirect_wrapper_to_package_identity_is_valid',
        empty($result['integrity_mismatch'])
            && (string)($result['status'] ?? '') === 'rejected'
            && (string)($result['original_name'] ?? '') === 'ChameleonSkins.utx',
        'A staged redirect job may retain the uploaded wrapper in its payload while returning the decompressed package name.'
    );

    $rows[0]['result_json'] = json_encode([
        'operation' => 'import_staged_package',
        'status' => 'rejected',
        'file_id' => 0,
        'message' => 'test',
        'original_name' => 'ChameleonSkins.utx',
        'decompressed' => true,
        'redirect_source_name' => 'Different.utx.uz2',
    ], JSON_THROW_ON_ERROR);
    $bad = $hydrator->hydrate($rows)[0] ?? [];
    $badResult = is_array($bad['result'] ?? null) ? $bad['result'] : [];
    $record(
        'staged_redirect_identity_still_rejects_wrong_wrapper',
        !empty($badResult['integrity_mismatch']) && (string)($badResult['status'] ?? '') === 'failed',
        'Redirect-aware identity matching must not weaken the job/result integrity guard for a genuinely different wrapper.'
    );
} catch (Throwable $error) {
    $record('staged_redirect_wrapper_to_package_identity_is_valid', false, get_class($error) . ': ' . $error->getMessage());
}

$nonBlocking = $read('src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php');
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$uploadInspector = $read('assets/upload-file-inspector-worker.js');
$archiveWorker = $read('assets/public-upload-archive-worker.js');
$record(
    'profiled_redirect_requires_unreal_package_output',
    preg_match('/decompressToTemp\([\s\S]*?\n\s*true\s*\n\s*\);/m', $nonBlocking) === 1,
    'Profiled staged redirects must reject a successfully decoded payload that does not begin with an Unreal package tag.'
);
$record(
    'profiled_upload_surfaces_rejection_reason',
    str_contains($staged, "\$retentionMessage . ': ' . \$shortError")
        && str_contains($staged, "'status' => \$staged !== null ? 'unverified' : 'rejected'")
        && str_contains($staged, "'error' => \$shortError"),
    'The final 100% progress row must include the actual verification/decompression reason instead of only a generic discarded/unverified label.'
);

$record(
    'browser_uz3_content_fallback_matches_server',
    str_contains($uploadInspector, 'UnrealDbLegacyUzDecoder.header(probe, 5678)')
        && str_contains($uploadInspector, "kind: 'redirect-uz3-fcodec-compat'")
        && str_contains($uploadInspector, 'catch (error)')
        && str_contains($archiveWorker, 'UnrealDbLegacyUzDecoder.header(probe, 5678)')
        && str_contains($archiveWorker, "kind: 'redirect-uz3-fcodec-compat'")
        && str_contains($archiveWorker, 'catch (error)'),
    'Direct uploads and archive members must try canonical UT3 zlib first, then accept a structurally valid signature-5678 FCodec compatibility wrapper only after canonical decoding fails.'
);

try {
    require_once $root . '/lib/CatalogRedirectCodec.php';
    require_once $root . '/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php';
    require_once $root . '/src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php';

    $uz3Package = "\xC1\x83\x2A\x9E" . "UnrealDB UZ3 regression fixture\0" . str_repeat("0123456789ABCDEF", 16);
    $uz3ExpectedPayload = gzcompress($uz3Package);
    if (!is_string($uz3ExpectedPayload)) {
        throw new RuntimeException('gzcompress unavailable for UZ3 fixture.');
    }
    $uz3Encoded = catalog_redirect_archive_compress_data($uz3Package, 'Fixture.upk', 'uz3');
    $uz3Archive = (string)($uz3Encoded['data'] ?? '');
    $uz3Decoded = catalog_redirect_archive_decompress_data($uz3Archive, 'uz3', 1024 * 1024);
    $record(
        'uz3_matches_ut3_tag_size_whole_file_zlib_layout',
        $uz3Archive === pack('V2', 5678, strlen($uz3Package)) . $uz3ExpectedPayload
            && catalog_redirect_archive_read_u32($uz3Archive, 0, 'le') === 5678
            && catalog_redirect_archive_read_u32($uz3Archive, 4, 'le') === strlen($uz3Package)
            && (string)($uz3Encoded['encoder'] ?? '') === 'epic-uz3-zlib'
            && (int)($uz3Encoded['chunks'] ?? 0) === 1
            && !isset($uz3Encoded['embedded_filename'])
            && is_array($uz3Decoded)
            && (string)($uz3Decoded['data'] ?? '') === $uz3Package
            && (int)($uz3Decoded['expected_bytes'] ?? 0) === strlen($uz3Package)
            && (int)($uz3Decoded['chunks'] ?? 0) === 1
            && !isset($uz3Decoded['embedded_filename']),
        'UT3 UZ3 must be tag 5678 + total uncompressed size + one zlib compress() stream, with no embedded filename or FCodec stages.'
    );


    // Historical mirrors contain files with a .uz3 suffix whose bytes are the
    // older Epic signature-5678 FCodec wrapper. The suffix must not make the
    // filename bytes get misread as a multi-gigabyte UT3 uncompressed size.
    $legacyUz3Encoded = catalog_redirect_archive_encode_native_codec(
        $uz3Package,
        'Zo_Town_Tex.utx',
        5678
    );
    $legacyUz3Archive = (string)($legacyUz3Encoded['data'] ?? '');
    $legacyUz3Decoded = catalog_redirect_archive_decompress_data(
        $legacyUz3Archive,
        'uz3',
        1024 * 1024
    );
    $record(
        'uz3_suffix_accepts_signature_5678_fcodec_content',
        is_array($legacyUz3Decoded)
            && (string)($legacyUz3Decoded['data'] ?? '') === $uz3Package
            && (string)($legacyUz3Decoded['embedded_filename'] ?? '') === 'Zo_Town_Tex.utx'
            && str_starts_with((string)($legacyUz3Decoded['decoder'] ?? ''), 'uz3-compat-epic-uz-5678-')
            && (int)($legacyUz3Decoded['wrapper_signature'] ?? 0) === 5678,
        'A .uz3 transport suffix may contain the engine FCodec 5678 wrapper seen in historic redirect mirrors; decode by content without changing canonical UT3 UZ3 encoding.'
    );

    $brokenLegacyUz3 = substr($legacyUz3Archive, 0, max(0, strlen($legacyUz3Archive) - 7));
    $brokenLegacyMessage = catalog_redirect_archive_decode_failure_message(
        $brokenLegacyUz3,
        'uz3',
        1024 * 1024,
        'Zo_Town_Tex.utx.uz3'
    );
    $record(
        'broken_legacy_fcodec_uz3_is_reported_as_fcodec_not_huge_zlib',
        str_contains($brokenLegacyMessage, 'UZ3 compatibility FCodec wrapper')
            && str_contains($brokenLegacyMessage, 'embedded_filename=Zo_Town_Tex.utx')
            && !str_contains($brokenLegacyMessage, 'uncompressed_size='),
        'A damaged compatibility wrapper must not be diagnosed by interpreting its serialized filename bytes as the UT3 uncompressed-size field.'
    );

    $payload = "NOT_AN_UNREAL_PACKAGE";
    $compressed = gzcompress($payload);
    if (!is_string($compressed)) {
        throw new RuntimeException('gzcompress unavailable for fixture.');
    }
    $fixture = tempnam(sys_get_temp_dir(), 'uz2-fixture-');
    if (!is_string($fixture)) {
        throw new RuntimeException('Could not allocate UZ2 fixture.');
    }
    file_put_contents($fixture, pack('V2', strlen($compressed), strlen($payload)) . $compressed);
    try {
        $processor = new UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor([
            'max_redirect_output_bytes' => 1024 * 1024,
        ]);
        $caught = '';
        try {
            $processor->decompressToTemp($fixture, 'Fake.utx.uz2', null, true);
        } catch (Throwable $error) {
            $caught = $error->getMessage();
        }
        $record(
            'decoded_non_package_uz2_is_rejected_before_profile_detection',
            str_contains($caught, 'Magic not found')
                && str_contains($caught, 'actual_magic_hex=')
                && str_contains($caught, 'expected_magic_hex='),
            'A valid UZ2 record stream containing non-package bytes must fail as non-package data, not fall through to .utx => UE1 classification.'
        );
    } finally {
        @unlink($fixture);
    }

    $badTag = catalog_redirect_archive_decode_failure_message(
        pack('V2', 1234, 4096) . "\x78\x9Cbad",
        'uz3',
        1024 * 1024,
        'Fake.utx.uz3'
    );
    $badStream = catalog_redirect_archive_decode_failure_message(
        pack('V2', 5678, 4096) . "not-zlib",
        'uz3',
        1024 * 1024,
        'Fake.utx.uz3'
    );
    $record(
        'uz3_failure_identifies_header_or_zlib_stage',
        str_contains($badTag, 'expected_tag=5678')
            && str_contains($badTag, 'actual_tag=1234')
            && str_contains($badStream, 'Cannot decompress UZ3')
            && str_contains($badStream, 'uncompressed_size=4096'),
        'Strict UZ3 rejection must identify whether the wrapper tag or tagged whole-file zlib stream failed without trying another format.'
    );
} catch (Throwable $error) {
    $record('redirect_format_regressions', false, get_class($error) . ': ' . $error->getMessage());
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
