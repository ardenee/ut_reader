<?php
declare(strict_types=1);

function specialist_diagnostics_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conflicts = file_get_contents(__DIR__ . '/../src/Application/Federation/CatalogFederationConflictListService.php');
$diagnostics = file_get_contents(__DIR__ . '/../federation/diagnostics.php');
$conflictView = file_get_contents(__DIR__ . '/../federation/_diagnostics-conflicts.php');
$jobs = file_get_contents(__DIR__ . '/../src/Application/Jobs/CatalogBackgroundJobPageService.php');
$endpoint = file_get_contents(__DIR__ . '/../api/v1/job-status-cursor.php');
$bridge = file_get_contents(__DIR__ . '/../assets/background-jobs-cursor-bridge.js');
$page = file_get_contents(__DIR__ . '/../background-jobs.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202607270011_specialist_diagnostics_pagination.php');
foreach ([$conflicts, $diagnostics, $conflictView, $jobs, $endpoint, $bridge, $page, $migration] as $source) {
    specialist_diagnostics_expect(is_string($source), 'Specialist diagnostics pagination source could not be read.');
}

specialist_diagnostics_expect(
    str_contains($conflicts, 'CatalogKeysetPaginator::comparison(')
        && str_contains($conflicts, 'CatalogKeysetPaginator::order(')
        && str_contains($conflicts, "['p.id', 'pf.package_name', 'pf.original_name', 'pf.id', 'f.id']"),
    'Federation conflict rows do not use a stable peer/file cursor tuple.'
);
specialist_diagnostics_expect(!str_contains(strtoupper($conflicts), ' OFFSET '), 'Federation conflicts returned to OFFSET pagination.');
specialist_diagnostics_expect(str_contains($conflicts, "LIMIT ' . (\$limit + 1)"), 'Federation conflicts do not read one bounded look-ahead row.');
specialist_diagnostics_expect(
    str_contains($conflicts, '$remainder = $total % $limit;'),
    'Federation conflict Last navigation does not return the exact partial final page.'
);
specialist_diagnostics_expect(
    str_contains($diagnostics, "require __DIR__ . '/_diagnostics-conflicts.php';"),
    'Federation Diagnostics does not load the isolated conflict view.'
);
specialist_diagnostics_expect(!str_contains($diagnostics . $conflictView, 'LIMIT 1000'), 'Federation Diagnostics still caps conflicts at 1,000 rows.');
foreach ([
    'CatalogFederationConflictListService::count(',
    'CatalogFederationConflictListService::fetch(',
    "'page' => 'federation-conflicts'",
    "'peer_id' => \$peerId",
    "'ignore_base_game' => \$ignore",
    "'conflict_cursor'",
    'federation_diagnostics_conflict_links(',
] as $fragment) {
    specialist_diagnostics_expect(str_contains($conflictView, $fragment), 'Federation conflict cursor is missing: ' . $fragment);
}

specialist_diagnostics_expect(
    str_contains($jobs, "CatalogKeysetPaginator::comparison(['j.id'], ['DESC']")
        && str_contains($jobs, "CatalogKeysetPaginator::order(['j.id'], ['DESC']")
        && str_contains($jobs, "LIMIT ' . (\$limit + 1)"),
    'Background-job history does not use bounded ID keyset pagination.'
);
specialist_diagnostics_expect(!str_contains(strtoupper($jobs), ' OFFSET '), 'Background-job page service contains OFFSET.');
specialist_diagnostics_expect(!str_contains(strtoupper($endpoint), ' OFFSET '), 'Cursor job API contains OFFSET.');
specialist_diagnostics_expect(
    str_contains($jobs, '$remainder = $total % $limit;'),
    'Background-job Last navigation does not return the exact partial final page.'
);
foreach ([
    'CatalogBackgroundJobPageService::fetch(',
    'CatalogKeysetPaginator::decode(',
    'CatalogKeysetPaginator::encode(',
    "'page' => 'background-jobs'",
    "'queue' => \$queue",
    "'status' => \$status",
    "'search' => \$search",
    "'previous_cursor'",
    "'next_cursor'",
] as $fragment) {
    specialist_diagnostics_expect(str_contains($endpoint, $fragment), 'Cursor job API is missing: ' . $fragment);
}

specialist_diagnostics_expect(
    str_contains($bridge, 'job-status-cursor.php')
        && str_contains($bridge, 'state.descriptors.set(page - 1')
        && str_contains($bridge, 'state.descriptors.set(page + 1')
        && str_contains($bridge, 'job_cursor')
        && str_contains($bridge, 'job_move'),
    'Background Jobs cursor bridge does not preserve sequential and reload navigation.'
);
$bridgePosition = strpos($page, 'background-jobs-cursor-bridge.js');
$rendererPosition = strpos($page, 'background-jobs-stable.js');
specialist_diagnostics_expect(
    $bridgePosition !== false && $rendererPosition !== false && $bridgePosition < $rendererPosition,
    'Background Jobs cursor bridge must load before the stable renderer.'
);

foreach ([
    'idx_ue_background_jobs_queue_id',
    'idx_ue_background_jobs_queue_status_id',
    'idx_ue_peer_files_conflict_cursor',
] as $index) {
    specialist_diagnostics_expect(str_contains($migration, $index), 'Specialist diagnostics migration lacks ' . $index . '.');
}

fwrite(STDOUT, "Specialist diagnostics pagination contract tests passed.\n");
