#!/usr/bin/env php
<?php
/** Read-only contract for public download and generated-package workflow. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$files = [
    'game_files' => 'game-files.php',
    'download' => 'download.php',
    'external' => 'lib/ExternalMirrors.php',
    'download_info' => 'download-info.php',
    'download_package' => 'download-package.php',
    'package_job' => 'generated-package-job.php',
    'package_download' => 'generated-package-download.php',
    'package_options_js' => 'assets/generated-package-options.js',
    'package_jobs_js' => 'assets/generated-package-jobs.js',
    'package_access' => 'src/Infrastructure/Jobs/CatalogGeneratedPackageJobAccess.php',
    'package_queue' => 'src/Infrastructure/Jobs/CatalogGeneratedPackageQueue.php',
    'worker_factory' => 'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'package_handler' => 'src/Infrastructure/Jobs/GeneratedPackageJobHandler.php',
    'retry_policy' => 'src/Application/Jobs/JobFailureRetryPolicy.php',
    'fingerprint' => 'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'settings' => 'src/Infrastructure/Downloads/CatalogDownloadSettingsService.php',
    'pak_download' => 'pak-download.php',
    'config' => 'config.example.php',
    'migration' => 'migrations/202609080001_public_downloads_external_only.php',
];
$source = [];
foreach ($files as $key => $relative) {
    $source[$key] = $read($relative);
}

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'public_individual_files_never_stream_local_storage',
    str_contains($source['download'], '$isAdmin = catalog_support_is_admin();')
        && str_contains($source['download'], 'if ($isAdmin) {')
        && str_contains($source['download'], 'public_download_send_local($config, $db, $file);')
        && str_contains($source['download'], 'external_public_download_decision(')
        && str_contains($source['download'], 'header(\'Location: \' . $externalUrl, true, 302);')
        && str_contains($source['external'], "fed_setting(\$db, 'public_download_mode', 'external_mirror')")
        && str_contains($source['external'], "in_array(\$mode, ['external_mirror','disabled'], true)")
        && str_contains($source['settings'], "['external_mirror', 'disabled']")
        && str_contains($source['migration'], 'setting_value IN ("local_direct","external_mirror_preferred")'),
    'Public users must use an external provider link; only authenticated administrators may stream the verified local file.'
);

$record(
    'game_file_download_action_uses_download_controller',
    str_contains($source['game_files'], 'href="download.php?id=')
        && !str_contains($source['game_files'], 'class="game-files-download-link" href="download-info.php?id='),
    'The game-file down-arrow must perform an individual-file download decision instead of opening package options.'
);

$record(
    'original_pak_direct_download_is_admin_only',
    str_contains($source['pak_download'], 'if (!catalog_support_is_admin())')
        && str_contains($source['pak_download'], 'Administrator access is required for direct PAK downloads.')
        && !str_contains($source['pak_download'], 'catalog_public_download_limit($db);'),
    'Original locally stored PAK containers must not be publicly streamed.'
);

$record(
    'generated_package_uses_dedicated_priority_queue',
    str_contains($source['package_job'], 'CatalogGeneratedPackageQueue::name($config)')
        && str_contains($source['package_job'], 'CatalogGeneratedPackageQueue::workerCount($config)')
        && str_contains($source['package_job'], '$dedupeKey = \'generated-package:\' . $buildKey;')
        && preg_match('/JobType::GENERATE_MOD_PACKAGE,\s*\$payload,\s*0,/s', $source['package_job']) === 1
        && str_contains($source['package_queue'], "'package_name'")
        && str_contains($source['worker_factory'], 'JobType::GENERATE_MOD_PACKAGE => static fn() => new GeneratedPackageJobHandler($db, $config)')
        && str_contains($source['config'], "'package_name' => 'catalog-packages'")
        && str_contains($source['config'], "'package_worker_processes' => 1"),
    'Interactive package generation must run at priority 0 on a separate package queue with its own detached worker.'
);

$record(
    'equivalent_package_builds_are_reused',
    str_contains($source['package_job'], "'build_key' => \$buildKey")
        && str_contains($source['package_job'], 'generated_package_reusable(')
        && str_contains($source['package_job'], "in_array(\$action, ['enqueue', 'lookup'], true)")
        && str_contains($source['package_access'], 'public function reusableCandidates(')
        && str_contains($source['package_access'], "status IN (\"queued\",\"running\",\"completed\")")
        && str_contains($source['package_access'], "str_starts_with(\$grant, 'build:')")
        && str_contains($source['package_download'], 'isAuthorizedGrant($job, $grant)'),
    'Lookup/enqueue must attach the browser to an equivalent queued/running build or still-valid completed artifact instead of generating it again.'
);

$record(
    'download_options_reflect_existing_build_state',
    str_contains($source['download_info'], 'id="generated-package-options-form"')
        && str_contains($source['download_info'], 'id="package-generate-button"')
        && str_contains($source['download_info'], 'assets/generated-package-options.js')
        && str_contains($source['package_options_js'], "data.set('action', 'lookup')")
        && str_contains($source['package_options_js'], 'Package build already queued')
        && str_contains($source['package_options_js'], 'Package is being generated')
        && str_contains($source['package_options_js'], 'Download generated package')
        && str_contains($source['package_options_js'], 'window.location.href = readyDownloadUrl;'),
    'Download options must disable generation for the same active build and turn the button into an immediate download when the artifact is already ready.'
);

$record(
    'generation_page_download_stays_disabled_until_ready_without_delay',
    str_contains($source['download_package'], 'id="package-job-download"')
        && str_contains($source['download_package'], 'aria-disabled="true"')
        && str_contains($source['package_jobs_js'], 'disableDownload();')
        && str_contains($source['package_jobs_js'], 'enableDownload(job, result);')
        && !str_contains($source['package_jobs_js'], 'downloadReadyDelayMs')
        && !str_contains($source['package_jobs_js'], 'startDownloadReadyDelay')
        && !str_contains($source['package_jobs_js'], '5000'),
    'The generated-package download control must be disabled until completion and must enable immediately without the old five-second delay.'
);

$record(
    'back_navigation_warns_but_keeps_build_running',
    str_contains($source['download_package'], 'id="package-job-back"')
        && str_contains($source['package_jobs_js'], 'This package is still being generated and should be ready soon.')
        && str_contains($source['package_jobs_js'], 'window.confirm(')
        && str_contains($source['download_package'], '$backUrl = \'download-info.php?\' . http_build_query(['),
    'Leaving the progress page while active must explain that the durable build continues and return to the exact same options.'
);

$record(
    'package_progress_lists_planned_files',
    str_contains($source['package_handler'], 'private function progressFiles(')
        && str_contains($source['package_handler'], "'files' => \$progressFiles")
        && str_contains($source['package_handler'], "'files_omitted' => \$filesOmitted")
        && str_contains($source['package_jobs_js'], 'Files in generated package')
        && str_contains($source['package_jobs_js'], 'file.install_path'),
    'Once dependency planning completes, progress must expose and render the files being added to the generated artifact.'
);

$record(
    'package_only_matches_do_not_block_generation',
    str_contains($source['package_handler'], 'if ($plan[\'missing\'] && !$allowIncomplete)')
        && str_contains($source['package_handler'], 'Package-only matches are included and do not block generation.')
        && str_contains($source['package_job'], 'genuinely missing dependency object')
        && str_contains($source['download_info'], 'Package-level matches')
        && str_contains($source['download_info'], 'these no longer block generation')
        && str_contains($source['retry_policy'], 'isDeterministicGeneratedPackageMessage'),
    'Package-level provider matches are already part of the closure and must not fail the build; genuinely missing objects are preflighted before a job is queued.'
);

$record(
    'worker_fingerprint_tracks_package_builder',
    str_contains($source['fingerprint'], '/src/Infrastructure/Jobs/GeneratedPackageJobHandler.php')
        && str_contains($source['fingerprint'], '/src/Infrastructure/Downloads/PdoCatalogPackageExportPlanner.php')
        && str_contains($source['fingerprint'], '/lib/GeneratedPackageBuilder.php'),
    'Detached package workers must restart when package planning/build execution code changes.'
);

$syntaxFailures = [];
$syntaxFiles = [
    'game-files.php',
    'download.php',
    'lib/ExternalMirrors.php',
    'download-info.php',
    'download-package.php',
    'generated-package-job.php',
    'generated-package-download.php',
    'pak-download.php',
    'src/Infrastructure/Jobs/CatalogGeneratedPackageJobAccess.php',
    'src/Infrastructure/Jobs/CatalogGeneratedPackageQueue.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/GeneratedPackageJobHandler.php',
    'src/Application/Jobs/JobFailureRetryPolicy.php',
    'src/Infrastructure/Downloads/CatalogDownloadSettingsService.php',
    'migrations/202609080001_public_downloads_external_only.php',
];
foreach ($syntaxFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ': could not lint';
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

$pipes = [];
$process = @proc_open([PHP_BINARY, '-l', __FILE__], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    $syntaxFailures[] = basename(__FILE__) . ': could not lint';
} else {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename(__FILE__) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
