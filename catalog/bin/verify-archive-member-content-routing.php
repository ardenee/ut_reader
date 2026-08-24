#!/usr/bin/env php
<?php
/** Regression verifier for archive-member content classification/routing. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$classifierPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentClassifier.php';
$routerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentRoutingJobHandler.php';
$factoryPath = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
$extractorPath = $root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php';
$sequentialPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$externalRarPath = $root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php';
$incomingPath = $root . '/src/Infrastructure/Import/CatalogIncomingFileStore.php';
$retryPath = $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';
$outcomeQueryPath = $root . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php';
$projectorPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php';
$workflowPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php';
$workerVersionPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';

$files = [
    $classifierPath,
    $routerPath,
    $factoryPath,
    $extractorPath,
    $sequentialPath,
    $externalRarPath,
    $incomingPath,
    $retryPath,
    $outcomeQueryPath,
    $projectorPath,
    $workflowPath,
    $workerVersionPath,
    __FILE__,
];

$source = [];
foreach ($files as $path) {
    $source[$path] = (string)@file_get_contents($path);
}

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

require_once $classifierPath;
$classifierClass = 'UnrealDb\\Catalog\\Infrastructure\\Jobs\\CatalogArchiveMemberContentClassifier';
$classifier = new $classifierClass();
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-member-routing-' . bin2hex(random_bytes(6));
@mkdir($tempDir, 0700, true);
$tempFiles = [];
$make = static function (string $name, string $bytes) use ($tempDir, &$tempFiles): string {
    $path = $tempDir . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not create verifier fixture: ' . $name);
    }
    $tempFiles[] = $path;
    return $path;
};

try {
    $packageMagic = "\xC1\x83\x2A\x9E" . str_repeat("\0", 64);
    $realMd5 = $classifier->classify($make('RealPackage.md5', $packageMagic), 'RealPackage.md5', 'System/RealPackage.md5');
    $record(
        'md5_with_unreal_magic_is_never_ignored',
        ($realMd5['kind'] ?? '') === 'package',
        'A .md5 filename is not a reason to skip a real Unreal package; package magic must win.'
    );

    $masterMd5 = $classifier->classify(
        $make('SA110.md5', "Executing Class Engine.MasterMD5\r\nSome package hashes follow\r\n"),
        'SA110.md5',
        'System/SA110.md5'
    );
    $record(
        'mastermd5_text_sidecar_is_skipped_by_content',
        ($masterMd5['kind'] ?? '') === 'skip'
            && str_contains(strtolower((string)($masterMd5['reason'] ?? '')), 'mastermd5'),
        'Only recognized non-package MasterMD5 text is skipped; the rule is content-driven.'
    );

    $appleDouble = $classifier->classify(
        $make('._Map.ut2', "\x00\x05\x16\x07\x00\x02\x00\x00Mac OS X        \x00\x02"),
        '._Map.ut2',
        '__MACOSX/._Map.ut2'
    );
    $record(
        'appledouble_archive_metadata_is_skipped',
        ($appleDouble['kind'] ?? '') === 'skip',
        '__MACOSX/._* AppleDouble metadata must not make an otherwise-valid archive partial.'
    );

    $rar = $classifier->classify(
        $make('nested.ut2', "Rar!\x1A\x07\x00" . str_repeat("\0", 32)),
        'nested.ut2',
        'nested.ut2'
    );
    $record(
        'rar_disguised_as_package_is_detected_by_magic',
        ($rar['kind'] ?? '') === 'archive' && ($rar['format'] ?? '') === 'rar',
        'RAR content named .ut2 must enter the nested archive workflow instead of the package parser.'
    );

    if (function_exists('gzcompress')) {
        $decoded = "\xC1\x83\x2A\x9E" . str_repeat("\0", 252);
        $compressed = gzcompress($decoded, 6);
        if (!is_string($compressed)) {
            throw new RuntimeException('gzcompress failed while creating the UZ2 verifier fixture.');
        }
        $uz2Bytes = pack('V2', strlen($compressed), strlen($decoded)) . $compressed;
        $uz2 = $classifier->classify($make('redirect.ut2', $uz2Bytes), 'redirect.ut2', 'redirect.ut2');
        $record(
            'uz2_disguised_as_package_is_content_detected',
            ($uz2['kind'] ?? '') === 'redirect' && ($uz2['format'] ?? '') === 'uz2',
            'A valid UE2 [compressed,uncompressed,zlib] record whose output starts with Unreal magic must route through UZ2 decompression.'
        );
    } else {
        $record('uz2_disguised_as_package_is_content_detected', false, 'zlib/gzcompress is unavailable in this PHP runtime.');
    }

    $placeholder = $classifier->classify(
        $make('Placeholder.ut2', "This file is a place holder...\r\n"),
        'Placeholder.ut2',
        'Placeholder.ut2'
    );
    $record(
        'known_placeholder_text_is_skipped',
        ($placeholder['kind'] ?? '') === 'skip',
        'Intentional map-pack placeholder files should not be presented as recoverable package failures.'
    );

    $filenameOnly = $classifier->classify(
        $make('ONS-Dinora-32p.unr', 'ONS-Dinora-32p'),
        'ONS-Dinora-32p.unr',
        'ONS-Dinora-32p.unr'
    );
    $record(
        'filename_only_placeholder_is_skipped',
        ($filenameOnly['kind'] ?? '') === 'skip',
        'Tiny printable members containing only their own package stem are intentional placeholders.'
    );

    $unknown = $classifier->classify(
        $make('unknown.ut2', "\x01\x02\x03\x04unrecognized"),
        'unknown.ut2',
        'unknown.ut2'
    );
    $record(
        'unknown_content_still_reaches_real_parser',
        ($unknown['kind'] ?? '') === 'unknown',
        'The classifier must not silently hide unknown/corrupt/uncommon package bytes.'
    );
} catch (Throwable $error) {
    $record('runtime_classifier_fixtures', false, $error->getMessage());
} finally {
    foreach ($tempFiles as $path) {
        @unlink($path);
    }
    @rmdir($tempDir);
}

$classifierSource = $source[$classifierPath];
$routerSource = $source[$routerPath];
$factorySource = $source[$factoryPath];
$extractorSource = $source[$extractorPath];
$sequentialSource = $source[$sequentialPath];
$externalRarSource = $source[$externalRarPath];
$incomingSource = $source[$incomingPath];
$retrySource = $source[$retryPath];
$querySource = $source[$outcomeQueryPath];
$projectorSource = $source[$projectorPath];
$workflowSource = $source[$workflowPath];
$versionSource = $source[$workerVersionPath];

$record(
    'archive_content_router_wraps_both_staged_package_routes',
    substr_count($factorySource, 'new CatalogArchiveMemberContentRoutingJobHandler(') >= 2
        && str_contains($factorySource, 'JobType::IMPORT_STAGED_PACKAGE')
        && str_contains($factorySource, 'JobType::PROCESS_BUCKET_STAGED_PACKAGE'),
    'Both profiled and Upload Bucket archive-extracted package jobs must use identical content routing.'
);

$record(
    'disguised_containers_use_durable_child_archive_jobs',
    str_contains($routerSource, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($routerSource, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($routerSource, '$context->defer(')
        && str_contains($routerSource, "'archive_depth'"),
    'Content-detected ZIP/RAR/7z must recurse through the durable queue rather than the PHP call stack.'
);

$record(
    'content_detected_nesting_honors_depth_limit',
    str_contains($routerSource, 'DEFAULT_MAX_NESTING_DEPTH = 4')
        && str_contains($routerSource, 'MAX_CONFIGURED_NESTING_DEPTH = 16')
        && str_contains($routerSource, 'UNREALDB_ARCHIVE_MAX_NESTING_DEPTH')
        && str_contains($routerSource, 'Nested archive depth limit of ')
        && str_contains($retrySource, "'nested archive depth limit of '"),
    'A disguised nested container must obey the same bounded nesting policy and must not auto-retry until configuration changes.'
);

$record(
    'nested_child_detail_uses_background_job_schema',
    str_contains($routerSource, 'result_json,last_error FROM ue_background_jobs')
        && str_contains($routerSource, "\$row['last_error']")
        && !str_contains($routerSource, 'error_message FROM ue_background_jobs'),
    'Nested archive detail reporting must read ue_background_jobs.last_error rather than a nonexistent error_message column.'
);

$rootedPathPolicy = static fn(string $text): bool =>
    str_contains($text, 'absolute drive path')
    && str_contains($text, "\$path = ltrim(\$path, '/');")
    && str_contains($text, 'parent-directory traversal');
$record(
    'rooted_archive_paths_are_normalized_not_trusted',
    $rootedPathPolicy($extractorSource)
        && $rootedPathPolicy($sequentialSource)
        && $rootedPathPolicy($externalRarSource),
    'ZIP, libarchive/7z and PHP-RAR paths may drop a leading archive-root slash only while drive paths and parent traversal remain rejected.'
);

$record(
    'local_staging_copy_is_verified_and_actionable',
    str_contains($incomingSource, 'copyLocalFileVerified(')
        && str_contains($incomingSource, 'expected_bytes=')
        && str_contains($incomingSource, 'written_bytes=')
        && str_contains($incomingSource, 'free_bytes=')
        && str_contains($incomingSource, 'filesystem_error='),
    'Archive staging failures must either copy the exact byte count or report actionable filesystem diagnostics.'
);

$record(
    'legacy_table_boundary_corruption_does_not_retry',
    str_contains($retrySource, 'JobType::PROCESS_BUCKET_UPLOAD')
        && str_contains($retrySource, "'invalid exports table offset:'")
        && str_contains($retrySource, "'invalid imports table offset:'")
        && str_contains($retrySource, "'invalid names table offset:'"),
    'Immutable legacy package table-boundary failures must terminate on the first attempt instead of repeating three identical parses.'
);

$record(
    'skipped_and_nested_children_are_not_counted_as_added',
    str_contains($querySource, "'skipped' => 0")
        && str_contains($querySource, "'nested_archive' => 0")
        && str_contains($projectorSource, "\$resultStatus === 'skipped'")
        && str_contains($projectorSource, "\$resultStatus === 'nested_archive'")
        && str_contains($workflowSource, "\$children['skipped']")
        && str_contains($workflowSource, "\$children['nested_archive']"),
    'Operator summaries must separate intentional skips/nested containers from genuinely added/imported Unreal files.'
);

$record(
    'worker_fingerprint_tracks_content_and_staging_code',
    str_contains($versionSource, '/CatalogArchiveMemberContentClassifier.php')
        && str_contains($versionSource, '/CatalogArchiveMemberContentRoutingJobHandler.php')
        && str_contains($versionSource, '/CatalogIncomingFileStore.php'),
    'Detached workers must reconcile when archive content routing or staging behavior changes.'
);

$record(
    'archive_content_routing_uses_no_external_tools',
    preg_match('/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', $classifierSource . "\n" . $routerSource) !== 1,
    'Archive content classification/routing must remain entirely in-process PHP.'
);

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($files as $path) {
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = basename($path) . ': could not run php -l';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
} else {
    $record('php_syntax', true, 'proc_open unavailable; runtime fixture execution already parsed the classifier and this verifier.');
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
