<?php
declare(strict_types=1);

function pak_rerun_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../background-jobs.php');
pak_rerun_expect(is_string($page), 'Could not read Background Jobs page.');
foreach ([
    'data-pak-rerun-url="api/v1/job-rerun-pak.php"',
    'background-jobs-pak-rerun.js',
] as $fragment) {
    pak_rerun_expect(str_contains($page, $fragment), 'Background Jobs does not load PAK re-run support: ' . $fragment);
}

$client = file_get_contents(__DIR__ . '/../assets/background-jobs-pak-rerun.js');
pak_rerun_expect(is_string($client), 'Could not read PAK re-run client.');
foreach ([
    "jobType !== 'catalog.import_staged_pak'",
    "button.textContent = 'Re-run'",
    'The original completed job will remain unchanged.',
    'job-rerun-pak.php',
] as $fragment) {
    pak_rerun_expect(str_contains($client, $fragment), 'PAK re-run client is missing: ' . $fragment);
}

$endpoint = file_get_contents(__DIR__ . '/../api/v1/job-rerun-pak.php');
pak_rerun_expect(is_string($endpoint), 'Could not read PAK re-run endpoint.');
foreach ([
    'CatalogIncomingFileStore',
    'CatalogPakArchiveStore',
    "['completed', 'failed', 'dead_letter', 'cancelled']",
    "'local-pak:'",
    "'rerun_of_job_id'",
    'JobType::IMPORT_STAGED_PAK',
    "'source' => \$sourceMode",
    "\$sourceMode = 'retained_pak'",
    "\$dedupeKey = 'rerun-pak:'",
] as $fragment) {
    pak_rerun_expect(str_contains($endpoint, $fragment), 'PAK re-run endpoint is missing: ' . $fragment);
}

pak_rerun_expect(
    !str_contains($endpoint, 'stageLocalFile(') && !str_contains($endpoint, 'copy('),
    'PAK re-run makes a second large physical copy instead of referencing retained storage.'
);

$retainedPosition = strpos($endpoint, 'CatalogPakArchiveStore::schemaInstalled');
$stagingPosition = strpos($endpoint, 'new CatalogIncomingFileStore');
pak_rerun_expect(
    $retainedPosition !== false && $stagingPosition !== false && $retainedPosition < $stagingPosition,
    'PAK re-run does not prefer the retained managed archive before durable staging fallback.'
);

pak_rerun_expect(
    str_contains($endpoint, "Neither the retained managed PAK nor the durable staging source is available"),
    'PAK re-run does not clearly report when both reusable sources are unavailable.'
);

echo "Background-job PAK re-run contract tests passed.\n";
