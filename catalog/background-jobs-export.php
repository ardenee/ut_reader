<?php
/**
 * Downloads the current file-centric Background Jobs filter as a Markdown report.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogArchiveJobOutcomeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobFileTreeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobFileTreeQuery;

function background_jobs_export_text(mixed $value): string
{
    $text = trim((string)$value);
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array<int,bool> $seen
 * @return list<array{row:array<string,mixed>,depth:int,parent_job_id:int}>
 */
function background_jobs_export_tree(
    PdoBackgroundJobFileTreeQuery $query,
    CatalogBackgroundJobResultHydrator $hydrator,
    CatalogArchiveJobOutcomeProjector $archiveProjector,
    CatalogBackgroundJobFileTreeProjector $fileProjector,
    string $queue,
    array $rows,
    int $depth,
    int $parentJobId,
    int &$remaining,
    array &$seen
): array {
    if ($remaining < 1 || $depth > 24 || $rows === []) {
        return [];
    }

    $projected = $hydrator->hydrate($rows);
    $projected = $archiveProjector->project($projected);
    $projected = $fileProjector->project($projected);
    $tree = [];

    foreach ($projected as $row) {
        if ($remaining < 1) {
            break;
        }
        $id = max(0, (int)($row['id'] ?? 0));
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $tree[] = ['row' => $row, 'depth' => $depth, 'parent_job_id' => $parentJobId];
        $remaining--;

        if ($remaining < 1 || max(0, (int)($row['child_count'] ?? 0)) < 1) {
            continue;
        }

        $page = 1;
        do {
            $children = $query->children($queue, $id, $page, 500);
            $tree = array_merge(
                $tree,
                background_jobs_export_tree(
                    $query,
                    $hydrator,
                    $archiveProjector,
                    $fileProjector,
                    $queue,
                    $children['rows'],
                    $depth + 1,
                    $id,
                    $remaining,
                    $seen
                )
            );
            $page++;
        } while ($remaining > 0 && $page <= (int)$children['pages']);
    }

    return $tree;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Background Jobs Export')) {
        exit;
    }

    $queue = trim((string)($_GET['queue'] ?? ($config['queue']['name'] ?? 'catalog')));
    if ($queue === '' || strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1) {
        throw new InvalidArgumentException('A valid queue name is required.');
    }

    $state = strtolower(trim((string)($_GET['state'] ?? 'all')));
    if (!in_array($state, ['all', 'working', 'issue', 'completed', 'stopped'], true)) {
        $state = 'all';
    }

    $search = background_jobs_export_text($_GET['search'] ?? '');
    if (mb_strlen($search, 'UTF-8') > 200) {
        $search = mb_substr($search, 0, 200, 'UTF-8');
    }

    $jobType = trim((string)($_GET['job_type'] ?? ''));
    if ($jobType !== '' && !in_array($jobType, JobType::all(), true)) {
        $jobType = '';
    }

    $query = new PdoBackgroundJobFileTreeQuery($db);
    $hydrator = new CatalogBackgroundJobResultHydrator($config);
    $archiveProjector = new CatalogArchiveJobOutcomeProjector($db);
    $fileProjector = new CatalogBackgroundJobFileTreeProjector();

    $limit = 20000;
    $perPage = 200;
    $page = 1;
    $total = 0;
    $rootRows = [];

    do {
        $result = $query->roots($queue, $state, $search, $jobType, $page, $perPage);
        if ($page === 1) {
            $total = max(0, (int)$result['total']);
        }
        foreach ($result['rows'] as $row) {
            $rootRows[] = $row;
            if (count($rootRows) >= $limit) {
                break 2;
            }
        }
        $page++;
    } while ($page <= (int)$result['pages']);

    $remaining = $limit;
    $seen = [];
    $treeRows = background_jobs_export_tree(
        $query,
        $hydrator,
        $archiveProjector,
        $fileProjector,
        $queue,
        $rootRows,
        0,
        0,
        $remaining,
        $seen
    );

    $generated = gmdate('Y-m-d H:i:s') . ' UTC';
    $filename = 'unrealdb-background-jobs-' . gmdate('Ymd-His') . '.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    echo "# UnrealDB Background Jobs Export\n\n";
    echo '- Generated: ' . $generated . "\n";
    echo '- Queue: ' . $queue . "\n";
    echo '- State: ' . $state . "\n";
    echo '- Job type: ' . ($jobType !== '' ? $jobType : 'all') . "\n";
    echo '- Search: ' . ($search !== '' ? $search : 'none') . "\n";
    echo '- Matching rows: ' . number_format($total) . "\n";
    echo '- Exported source rows: ' . number_format(count($rootRows)) . "\n";
    echo '- Exported rows including descendants: ' . number_format(count($treeRows)) . "\n";
    if ($total > $limit || $remaining < 1) {
        echo '- Warning: export limited to ' . number_format($limit) . " total job rows.\n";
    }
    echo "\n";

    foreach ($treeRows as $entry) {
        $row = $entry['row'];
        $depth = max(0, (int)$entry['depth']);
        $parentJobId = max(0, (int)$entry['parent_job_id']);
        $id = max(0, (int)($row['id'] ?? 0));
        $fileName = background_jobs_export_text($row['file_name'] ?? '');
        $filePath = background_jobs_export_text($row['file_path'] ?? '');
        $jobTypeValue = background_jobs_export_text($row['job_type'] ?? '');
        $queueStatus = background_jobs_export_text($row['status'] ?? '');
        $displayStatus = background_jobs_export_text($row['display_status'] ?? '');
        $operatorState = background_jobs_export_text($row['operator_state'] ?? '');
        $operatorLabel = background_jobs_export_text($row['operator_status_label'] ?? '');
        $action = background_jobs_export_text($row['action_label'] ?? '');
        $resultLabel = background_jobs_export_text($row['result_label'] ?? '');
        $issue = background_jobs_export_text($row['issue_reason'] ?? '');
        $activity = background_jobs_export_text($row['activity_detail'] ?? '');
        $progress = background_jobs_export_text($row['progress_text'] ?? '');
        $size = max(0, (int)($row['size_bytes'] ?? 0));
        $childCount = max(0, (int)($row['child_count'] ?? 0));
        $childIssues = max(0, (int)($row['child_issue_count'] ?? 0));
        $lastError = background_jobs_export_text($row['last_error'] ?? '');
        $resultData = is_array($row['result'] ?? null) ? $row['result'] : [];
        $progressData = is_array($row['progress'] ?? null) ? $row['progress'] : [];
        $resultMessage = background_jobs_export_text($resultData['message'] ?? '');
        $progressMessage = background_jobs_export_text($progressData['message'] ?? '');

        $headingLevel = min(6, 2 + $depth);
        $heading = str_repeat('#', $headingLevel);
        echo $heading . ' ' . ($depth > 0 ? 'Child ' : '') . 'Job #' . $id . ' — '
            . ($fileName !== '' ? $fileName : 'Unnamed source') . "\n\n";
        if ($parentJobId > 0) {
            echo '- Parent job: #' . $parentJobId . "\n";
        }
        echo '- Tree depth: ' . $depth . "\n";
        echo '- File: ' . $fileName . "\n";
        echo '- Path: ' . $filePath . "\n";
        echo '- Job type: ' . $jobTypeValue . "\n";
        echo '- Queue status: ' . $queueStatus . "\n";
        echo '- Display status: ' . $displayStatus . "\n";
        echo '- Operator state: ' . $operatorState . ($operatorLabel !== '' ? ' (' . $operatorLabel . ')' : '') . "\n";
        echo '- Action: ' . ($action !== '' ? $action : 'n/a') . "\n";
        if ($resultLabel !== '') {
            echo '- Result: ' . $resultLabel . "\n";
        }
        echo '- Progress: ' . ($progress !== '' ? $progress : 'n/a') . "\n";
        echo '- Size: ' . number_format($size) . " bytes\n";
        echo '- Children: ' . number_format($childCount) . ' total, ' . number_format($childIssues) . " issue(s)\n";

        if ($issue !== '') {
            echo "\n**Issue**\n\n" . $issue . "\n";
        }
        if ($activity !== '' && $activity !== $issue) {
            echo "\n**Activity**\n\n" . $activity . "\n";
        }
        if ($resultMessage !== '' && $resultMessage !== $issue && $resultMessage !== $activity) {
            echo "\n**Result message**\n\n" . $resultMessage . "\n";
        }
        if ($progressMessage !== '' && $progressMessage !== $issue
            && $progressMessage !== $activity && $progressMessage !== $resultMessage) {
            echo "\n**Progress message**\n\n" . $progressMessage . "\n";
        }
        if ($lastError !== '' && $lastError !== $issue) {
            echo "\n**Last error**\n\n" . $lastError . "\n";
        }
        echo "\n---\n\n";
    }
    exit;
} catch (Throwable $error) {
    error_log('[UnrealDB background jobs export][' . catalog_request_id() . '] ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Background Jobs export failed.\n";
    exit;
}
