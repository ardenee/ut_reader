<?php
/**
 * Administrator diagnostic for likely historical filename/package-name corruption.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

function possible_misnamed_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function possible_misnamed_confidence_label(string $confidence): string
{
    return match ($confidence) {
        'very_high' => 'Very high',
        'high' => 'High',
        default => 'Possible',
    };
}

$config = catalog_config();
$db = catalog_db($config);
catalog_start_session();
if (!catalog_support_is_admin()) {
    http_response_code(403);
    catalog_head('Possible Misnamed Files');
    echo '<div class="card"><h1>Administrator access required</h1></div>';
    catalog_foot();
    exit;
}

$userId = isset($_SESSION['user']['id']) ? max(0, (int)$_SESSION['user']['id']) : 0;
$queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'start_scan') {
    catalog_check_csrf('possible_misnamed_files_scan');
    $gameId = max(0, (int)($_POST['game_id'] ?? 0));
    if ($gameId > 0) {
        $gameExists = catalog_one($db, 'SELECT id FROM ue_games WHERE id=?', [$gameId]);
        if (!$gameExists) {
            throw new RuntimeException('Selected game was not found.');
        }
    }

    // Only one catalogue-wide misname diagnostic may be active at once. A second
    // request returns the existing durable job rather than creating competing
    // scans over the same large dependency/export projections.
    $jobId = (new PdoJobQueue($db))->enqueue(
        $queueName,
        JobType::SCAN_POSSIBLE_MISNAMED_FILES,
        [
            'game_id' => $gameId,
            'requested_by' => $userId > 0 ? $userId : null,
        ],
        70,
        null,
        'possible-misnamed-files',
        $userId > 0 ? $userId : null,
        3
    );

    $worker = (new CatalogQueueWorkerStarter($db, $config))->start(
        $queueName,
        true,
        $userId > 0 ? $userId : null
    );
    $workerError = trim((string)($worker['worker_error'] ?? ''));
    $_SESSION['possible_misnamed_files_flash'] = 'Scan job #' . $jobId . ' queued or already active.'
        . ($workerError !== '' ? ' Worker start reported: ' . $workerError : '');
    header('Location: possible-misnamed-files.php?job_id=' . $jobId, true, 303);
    exit;
}

if (isset($_SESSION['possible_misnamed_files_flash'])) {
    $flash = (string)$_SESSION['possible_misnamed_files_flash'];
    unset($_SESSION['possible_misnamed_files_flash']);
}

$games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
$jobs = catalog_all(
    $db,
    'SELECT id,status,payload_json,result_json,progress_json,last_error,created_at,updated_at,completed_at '
    . 'FROM ue_background_jobs WHERE job_type=? ORDER BY id DESC LIMIT 10',
    [JobType::SCAN_POSSIBLE_MISNAMED_FILES]
);

$requestedJobId = filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);
$requestedJobId = $requestedJobId === false || $requestedJobId === null ? 0 : max(0, (int)$requestedJobId);
$selected = null;
foreach ($jobs as $job) {
    if ($requestedJobId > 0 && (int)$job['id'] === $requestedJobId) {
        $selected = $job;
        break;
    }
}
if ($selected === null && $jobs !== []) {
    $selected = $jobs[0];
}

$payload = $selected ? possible_misnamed_decode((string)($selected['payload_json'] ?? '')) : [];
$progress = $selected ? possible_misnamed_decode((string)($selected['progress_json'] ?? '')) : [];
$result = $selected ? possible_misnamed_decode((string)($selected['result_json'] ?? '')) : [];
$candidates = is_array($result['candidates'] ?? null) ? $result['candidates'] : [];

catalog_head('Possible Misnamed Files');

echo '<div class="card hero"><h1>Possible Misnamed Files</h1>'
    . '<p class="muted">Find verified files whose exported object names repeatedly match unresolved imports that expect a different package name. '
    . 'Candidates with several matches from the same importing file and zero current dependants are ranked highest. '
    . 'A dedicated copy-suffix check also tests names ending in (1) through (9), with or without a preceding space, against the unsuffixed package name. '
    . 'Nothing is renamed automatically.</p></div>';

if ($flash !== '') {
    echo '<div class="card"><p><strong>' . catalog_h($flash) . '</strong></p></div>';
}

echo '<div class="card"><h2>Run diagnostic</h2>'
    . '<p class="muted">The scan runs as a bounded background job. Common object names exported by more than 40 files are ignored so generic names do not create false matches or expensive fan-out. '
    . 'For copy-style names such as MyTex(2).utx or MyTex (2).utx, the detector tests MyTex as the expected package identity when unresolved dependency/object-path evidence supports it. '
    . 'Only one scan runs at a time.</p>'
    . '<form method="post">'
    . '<input type="hidden" name="action" value="start_scan">'
    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('possible_misnamed_files_scan')) . '">'
    . '<label>Game <select name="game_id"><option value="0">All games</option>';
foreach ($games as $game) {
    echo '<option value="' . (int)$game['id'] . '">' . catalog_h((string)$game['name']) . '</option>';
}
echo '</select></label> <button type="submit">Scan for possible misnamed files</button></form></div>';

if ($selected !== null) {
    $status = (string)$selected['status'];
    $selectedGameId = max(0, (int)($payload['game_id'] ?? 0));
    $selectedGameName = 'All games';
    foreach ($games as $game) {
        if ((int)$game['id'] === $selectedGameId) {
            $selectedGameName = (string)$game['name'];
            break;
        }
    }
    echo '<div class="card"><h2>Scan #' . (int)$selected['id'] . '</h2>'
        . '<p><strong>Status:</strong> ' . catalog_h($status)
        . ' &nbsp; <strong>Scope:</strong> ' . catalog_h($selectedGameName)
        . ' &nbsp; <strong>Started:</strong> ' . catalog_h((string)$selected['created_at']) . '</p>';

    if (in_array($status, ['queued', 'running'], true)) {
        $message = trim((string)($progress['message'] ?? 'Waiting for a worker.'));
        $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
        echo '<p>' . catalog_h($message) . '</p>'
            . '<p class="muted small">Progress ' . $percent . '% · '
            . (int)($progress['scanned_owner_files'] ?? 0) . ' files with unresolved imports checked · '
            . (int)($progress['imports_examined'] ?? 0) . ' unresolved imports examined · '
            . count((array)($progress['candidate_state'] ?? [])) . ' candidates currently retained.</p>'
            . '<p><a class="button secondary" href="possible-misnamed-files.php?job_id=' . (int)$selected['id'] . '">Refresh</a> '
            . '<a class="button secondary" href="background-jobs.php">Background Jobs</a></p>'
            . '<script>setTimeout(function(){location.reload();},5000);</script>';
    } elseif ($status === 'completed') {
        $counts = is_array($result['confidence_counts'] ?? null) ? $result['confidence_counts'] : [];
        echo '<p>' . catalog_h((string)($result['message'] ?? 'Scan complete.')) . '</p>'
            . '<p class="muted small">Very high: ' . (int)($counts['very_high'] ?? 0)
            . ' · High: ' . (int)($counts['high'] ?? 0)
            . ' · Possible: ' . (int)($counts['possible'] ?? 0)
            . ' · Ambiguous/common object terms skipped: ' . (int)($result['ambiguous_terms_skipped'] ?? 0)
            . ' · Oversized import lists truncated: ' . (int)($result['truncated_owner_files'] ?? 0) . '</p>';
    } else {
        echo '<p class="muted">' . catalog_h((string)($selected['last_error'] ?? 'Scan did not complete.')) . '</p>';
    }
    echo '</div>';
}

if ($selected !== null && (string)$selected['status'] === 'completed') {
    echo '<div class="card"><h2>Ranked candidates</h2>';
    if ($candidates === []) {
        echo '<p class="muted">No likely filename/package-name mismatches were found in this scan.</p></div>';
    } else {
        echo '<p class="muted small">Review the evidence before renaming. The candidate page will perform the actual admin-only rename and queue dependency reconciliation.</p>'
            . '<table><thead><tr><th>Confidence</th><th>Candidate file</th><th>Suggested package</th><th>Evidence</th><th>Current dependants</th><th>Name similarity</th><th>Score</th><th></th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $fileId = (int)($candidate['candidate_file_id'] ?? 0);
            $confidence = (string)($candidate['confidence'] ?? 'possible');
            $evidenceHtml = '';
            foreach (array_slice((array)($candidate['evidence'] ?? []), 0, 3) as $evidence) {
                if (!is_array($evidence)) {
                    continue;
                }
                $ownerId = (int)($evidence['file_id'] ?? 0);
                $ownerName = (string)($evidence['original_name'] ?? ('File #' . $ownerId));
                $evidenceHtml .= '<div><a href="file-examine.php?id=' . $ownerId . '">' . catalog_h($ownerName) . '</a>: '
                    . (int)($evidence['matched_objects'] ?? 0) . ' matching objects</div>';
            }
            echo '<tr>'
                . '<td><strong>' . catalog_h(possible_misnamed_confidence_label($confidence)) . '</strong></td>'
                . '<td><a href="file-examine.php?id=' . $fileId . '">' . catalog_h((string)($candidate['candidate_original_name'] ?? '')) . '</a>'
                . '<div class="mono small muted">' . catalog_h((string)($candidate['candidate_package_name'] ?? '')) . '</div>'
                . '<div class="small muted">' . catalog_h((string)($candidate['game_name'] ?? '')) . '</div></td>'
                . '<td><div class="mono">' . catalog_h((string)($candidate['suggested_package_name'] ?? '')) . '</div>'
                . '<div class="small muted">Suggested filename: ' . catalog_h((string)($candidate['suggested_filename'] ?? '')) . '</div></td>'
                . '<td>' . ($evidenceHtml !== '' ? $evidenceHtml : '<span class="muted">No retained evidence detail</span>')
                . '<div class="small muted">Best same-file match: ' . (int)($candidate['best_same_file_matches'] ?? 0)
                . ' · matching files: ' . (int)($candidate['matching_files'] ?? 0) . '</div></td>'
                . '<td>' . (int)($candidate['current_dependants'] ?? 0) . '</td>'
                . '<td>' . catalog_h((string)($candidate['name_similarity'] ?? '')) . '</td>'
                . '<td>' . (int)($candidate['score'] ?? 0) . '</td>'
                . '<td><a class="button primary" href="file-examine.php?id=' . $fileId . '&rename_suggestions=1">Review / rename</a></td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

if ($jobs !== []) {
    echo '<div class="card"><h2>Recent scans</h2><table><thead><tr><th>Job</th><th>Status</th><th>Scope</th><th>Created</th><th>Completed</th></tr></thead><tbody>';
    foreach ($jobs as $job) {
        $jobPayload = possible_misnamed_decode((string)($job['payload_json'] ?? ''));
        $jobGameId = max(0, (int)($jobPayload['game_id'] ?? 0));
        $scope = 'All games';
        foreach ($games as $game) {
            if ((int)$game['id'] === $jobGameId) {
                $scope = (string)$game['name'];
                break;
            }
        }
        echo '<tr><td><a href="possible-misnamed-files.php?job_id=' . (int)$job['id'] . '">#' . (int)$job['id'] . '</a></td>'
            . '<td>' . catalog_h((string)$job['status']) . '</td>'
            . '<td>' . catalog_h($scope) . '</td>'
            . '<td>' . catalog_h((string)$job['created_at']) . '</td>'
            . '<td>' . catalog_h((string)($job['completed_at'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

catalog_foot();
