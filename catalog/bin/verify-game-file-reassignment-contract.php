<?php
/**
 * Static regression contract for admin-only verified-file reassignment.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

/** @param list<string> $needles */
function require_text(string $path, array $needles, array &$failures): void
{
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        $failures[] = 'Could not read ' . $path;
        return;
    }
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            $failures[] = basename($path) . ' is missing contract marker: ' . $needle;
        }
    }
}

$api = $root . '/api/v1/game-files-reassign.php';
require_text($api, [
    'catalog_api_require_admin(false);',
    "catalog_api_require_csrf('catalog-maintenance');",
    'JobType::GAME_FILE_REASSIGN',
    "'scope' => \$scope",
    "'snapshot_total'",
    'CatalogQueueWorkerStarter',
], $failures);

$asset = $root . '/assets/game-file-reassignment.js';
require_text($asset, [
    'form[action="file-maintenance.php"] input[name="csrf"]',
    'Move selected',
    'Move all matching',
    'Unverified Files',
    'Each destination copy is verified first.',
    "scope === 'selected'",
], $failures);

$jobType = $root . '/src/Domain/Jobs/JobType.php';
require_text($jobType, [
    "public const GAME_FILE_REASSIGN = 'catalog.game_file_reassign';",
    "public const GAME_FILE_REASSIGN_BATCH = 'catalog.game_file_reassign_batch';",
    'self::GAME_FILE_REASSIGN,',
    'self::GAME_FILE_REASSIGN_BATCH,',
], $failures);

$worker = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
require_text($worker, [
    'JobType::GAME_FILE_REASSIGN =>',
    'JobType::GAME_FILE_REASSIGN_BATCH =>',
    'new CatalogGameFileReassignmentJobHandler',
], $failures);

$handler = $root . '/src/Infrastructure/Jobs/CatalogGameFileReassignmentJobHandler.php';
require_text($handler, [
    'private const PLAN_ID_WINDOW = 5000;',
    'private const CHILD_ID_SPAN = 100;',
    'enqueueWorkflowUnits(',
    '$context->defer(',
    'failure_samples',
], $failures);

$demotion = $root . '/src/Infrastructure/Games/CatalogVerifiedFileDemotionService.php';
require_text($demotion, [
    'CatalogUnverifiedQueueStorage::uploadBucketDirectory',
    'UPDATE ue_files SET unverified_queue_key=?',
    'CatalogUnverifiedStagingIndex',
    "'status' => 'unverified'",
    'restoreReimportFileRow',
], $failures);

$move = $root . '/src/Infrastructure/Games/CatalogVerifiedFileReassignmentService.php';
require_text($move, [
    'PdoCatalogPackageImporter',
    '->importUploadedFile(',
    'Destination import did not produce a verified package.',
    'retireSource(',
], $failures);
$moveContent = @file_get_contents($move);
if (is_string($moveContent)) {
    $targetImport = strpos($moveContent, '->importUploadedFile(');
    $retire = strpos($moveContent, '$retired = $this->retireSource');
    if ($targetImport === false || $retire === false || $targetImport >= $retire) {
        $failures[] = 'Canonical destination verification must occur before source retirement.';
    }
    if (str_contains($moveContent, 'private function targetVerifiedFile')) {
        $failures[] = 'Same-MD5 destination moves must not bypass the canonical importer.';
    }
}

$selector = $root . '/src/Infrastructure/Games/PdoGameFileReassignmentSelectionQuery.php';
require_text($selector, [
    'f.scan_status="verified"',
    'COUNT(*) c,COALESCE(MAX(f.id),0) max_id',
    "' ORDER BY f.id ASC LIMIT ' . \$limit",
    'Select no more than 1,000 visible files at once.',
], $failures);

$responseTransform = $root . '/src/Presentation/Http/CatalogPageResponseTransform.php';
require_text($responseTransform, [
    "if (\$script === 'game-files.php')",
    'assets/game-file-reassignment.js',
], $failures);

if ($failures !== []) {
    fwrite(STDERR, "Game file reassignment contract FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Game file reassignment contract OK: admin-only API/CSRF, selected/all-matching UI, durable batches, unverified fallback and destination-first move safety are present.\n";
