#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for the possible-misnamed-file diagnostic. */
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
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$phpFiles = [
    'possible-misnamed-files.php',
    'src/Application/Jobs/JobExecutionContext.php',
    'src/Domain/Jobs/JobType.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogMisnamedFileScanJobHandler.php',
    'src/Infrastructure/Maintenance/CatalogMisnamedFileDetector.php',
    'lib/CatalogNavigation.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' missing';
            continue;
        }
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

$jobType = $read('src/Domain/Jobs/JobType.php');
$resourcePolicy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$executionContext = $read('src/Application/Jobs/JobExecutionContext.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$handler = $read('src/Infrastructure/Jobs/CatalogMisnamedFileScanJobHandler.php');
$detector = $read('src/Infrastructure/Maintenance/CatalogMisnamedFileDetector.php');
$page = $read('possible-misnamed-files.php');
$navigation = $read('lib/CatalogNavigation.php');

$record(
    'job_type_and_worker_route_registered',
    str_contains($jobType, "SCAN_POSSIBLE_MISNAMED_FILES = 'catalog.scan_possible_misnamed_files'")
        && str_contains($jobType, 'self::SCAN_POSSIBLE_MISNAMED_FILES')
        && str_contains($factory, 'JobType::SCAN_POSSIBLE_MISNAMED_FILES')
        && str_contains($factory, 'new CatalogMisnamedFileScanJobHandler($db)'),
    'The durable diagnostic must be a registered job type with a worker route.'
);

$record(
    'scan_resource_is_serial_and_long_running_safe',
    str_contains($resourcePolicy, 'JobType::SCAN_POSSIBLE_MISNAMED_FILES')
        && str_contains($resourcePolicy, "'diagnostic:possible-misnamed-files'")
        && str_contains($resourcePolicy, 'self::SEARCH_HEAVY')
        && str_contains($executionContext, 'JobType::SCAN_POSSIBLE_MISNAMED_FILES'),
    'The diagnostic must use the single-slot search-heavy resource class and a long-running lease profile.'
);

$record(
    'scan_is_batched_and_yields_worker',
    str_contains($handler, 'OWNER_BATCH_SIZE = 8')
        && str_contains($handler, 'nextOwnerIds(')
        && str_contains($handler, '$context->defer(')
        && str_contains($handler, "false\n        );"),
    'The catalogue scan must advance in small owner-file batches and release worker affinity between turns.'
);

$record(
    'detector_uses_indexed_term_identity_without_text_scan',
    str_contains($detector, 'MAX_OBJECT_PROVIDER_FANOUT = 40')
        && str_contains($detector, 'e.object_term_id IN (')
        && str_contains($detector, 'resolved_file_id IN (')
        && str_contains($detector, 'd.import_object_term_id IS NOT NULL')
        && !str_contains($detector, ' LIKE '),
    'Candidate discovery must use compact term IDs and bounded provider fan-out, never wildcard metadata scans.'
);

$record(
    'same_file_multiple_matches_are_required',
    str_contains($detector, 'if ($matched < 2)')
        && str_contains($detector, "'best_same_file_matches' => \$matched")
        && str_contains($detector, "'matching_files' => 1"),
    'A candidate must have at least two distinct object-term matches from the same importing file.'
);

$record(
    'zero_dependants_strengthen_confidence',
    str_contains($detector, '$dependants === 0 ? 35')
        && str_contains($detector, "\$confidence = 'very_high'")
        && str_contains($detector, 'COUNT(DISTINCT file_id) dependant_count'),
    'Files with no current resolved dependants must receive the strongest confidence boost.'
);

$record(
    'admin_page_is_non_destructive_and_serial',
    str_contains($page, 'catalog_support_is_admin()')
        && str_contains($page, "catalog_check_csrf('possible_misnamed_files_scan')")
        && str_contains($page, "'possible-misnamed-files',")
        && str_contains($page, 'Nothing is renamed automatically')
        && str_contains($page, 'Review / rename')
        && str_contains($navigation, "'Possible Misnamed Files' => \$root . 'possible-misnamed-files.php'"),
    'The diagnostic must remain admin-only, allow one active scan, and require manual review before the existing rename action.'
);

require_once $root . '/bootstrap/autoload.php';
$fixture = \UnrealDb\Catalog\Infrastructure\Maintenance\CatalogMisnamedFileDetector::rankCandidate([
    'candidate_package_name' => '_GO_tex_1',
    'suggested_package_name' => '[GO]tex_1',
    'best_same_file_matches' => 4,
    'matching_objects' => 4,
    'matching_files' => 1,
    'current_dependants' => 0,
]);
$record(
    'historical_cleanup_fixture_ranks_very_high',
    (string)($fixture['confidence'] ?? '') === 'very_high'
        && (string)($fixture['name_similarity'] ?? '') === 'same letters/numbers after punctuation cleanup'
        && (int)($fixture['score'] ?? 0) >= 100,
    '_GO_tex_1 versus [GO]tex_1 with four same-file object matches and zero dependants must rank Very high.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
