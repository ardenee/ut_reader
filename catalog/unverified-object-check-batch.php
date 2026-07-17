<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedObjectCheck.php';
require_once __DIR__ . '/lib/UploadProgress.php';

/** @return list<string> */
function uvoc_batch_tokens(): array
{
    $requested = $_POST['tokens'] ?? [];
    if (is_string($requested)) {
        $requested = [$requested];
    }

    $tokens = [];
    if (is_array($requested)) {
        foreach ($requested as $token) {
            if (!is_string($token)) {
                continue;
            }
            $token = trim($token);
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }
    return array_slice(array_keys($tokens), 0, 1000);
}

function uvoc_batch_progress(string $token, array $state): void
{
    if ($token !== '') {
        upload_progress_write($token, $state);
    }
}

function uvoc_batch_result_marker(string $status, string $message = ''): void
{
    echo '<div data-uvoc-result-status="' . catalog_h($status) . '" data-message="' . catalog_h($message) . '" hidden></div>';
}

function uvoc_batch_candidate_table(array $candidates): void
{
    if ($candidates === []) {
        echo '<p class="muted">No indexed catalog imports currently require this package name.</p>';
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr><th>Game</th><th>Imports</th><th>Files</th><th>Exact object matches</th><th>Not matched</th></tr></thead><tbody>';
    foreach ($candidates as $candidate) {
        echo '<tr>';
        echo '<td><a href="game-files.php?id=' . (int)$candidate['game_id'] . '" target="_blank" rel="noopener">' . catalog_h((string)$candidate['game_name']) . '</a></td>';
        echo '<td>' . (int)$candidate['import_count'] . '</td>';
        echo '<td>' . (int)$candidate['owner_count'] . '</td>';
        echo '<td>' . (int)$candidate['exact_object_matches'] . '</td>';
        echo '<td>' . (int)$candidate['unmatched_object_count'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$progressToken = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required for a batch Object Check.');
    }
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Administrator login is required.');
    }

    catalog_check_csrf('unverified-files');
    $progressToken = upload_progress_token(trim((string)($_POST['progress_token'] ?? '')));
    $tokens = uvoc_batch_tokens();
    if ($tokens === []) {
        throw new RuntimeException('Select at least one queued file before running Object Check.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $total = count($tokens);

    uvoc_batch_progress($progressToken, [
        'stage' => 'starting',
        'done' => 0,
        'total' => $total,
        'percent' => 0,
        'current_index' => 0,
        'file_percent' => 0,
        'message' => 'Starting queued package Object Check',
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $checks = [];
    foreach ($tokens as $index => $token) {
        $progress = $progressToken === '' ? null : static function (array $state) use ($progressToken, $index, $total): void {
            $filePercent = max(0, min(100, (int)($state['percent'] ?? 0)));
            $overall = min(98, (int)floor((($index + ($filePercent / 100)) / max(1, $total)) * 100));
            uvoc_batch_progress($progressToken, [
                'stage' => (string)($state['stage'] ?? 'checking'),
                'done' => $index,
                'total' => $total,
                'percent' => $overall,
                'current_index' => $index,
                'file_percent' => $filePercent,
                'message' => 'File ' . ($index + 1) . ' of ' . $total . ': ' . (string)($state['message'] ?? 'Checking package'),
            ]);
        };

        try {
            $result = uvoc_check($db, $config, $token, $progress);
            $reader = is_array($result['reader'] ?? null) ? $result['reader'] : null;
            $checks[] = [
                'token' => $token,
                'item' => $result['item'],
                'reader' => $reader === null ? null : [
                    'engine' => (string)($reader['engine'] ?? ''),
                    'name_count' => (int)($reader['name_count'] ?? 0),
                    'import_count' => (int)($reader['import_count'] ?? 0),
                    'export_count' => (int)($reader['export_count'] ?? 0),
                ],
                'candidates' => is_array($result['candidates'] ?? null) ? $result['candidates'] : [],
                'analysis_error' => is_array($result['analysis_error'] ?? null) ? $result['analysis_error'] : null,
                'error' => null,
            ];

            uvoc_batch_progress($progressToken, [
                'stage' => 'file_complete',
                'done' => $index + 1,
                'total' => $total,
                'percent' => min(98, (int)floor((($index + 1) / $total) * 100)),
                'current_index' => $index,
                'file_percent' => 100,
                'message' => 'Completed ' . ($index + 1) . ' of ' . $total . ': ' . (string)($result['item']['original_name'] ?? 'queued file'),
            ]);
        } catch (Throwable $error) {
            error_log('[UnrealDB object check batch] ' . $error->getMessage());
            $checks[] = ['token' => $token, 'item' => null, 'reader' => null, 'candidates' => [], 'analysis_error' => null, 'error' => $error->getMessage()];
            uvoc_batch_progress($progressToken, [
                'stage' => 'file_error',
                'done' => $index + 1,
                'total' => $total,
                'percent' => min(98, (int)floor((($index + 1) / $total) * 100)),
                'current_index' => $index,
                'file_percent' => 100,
                'message' => 'File ' . ($index + 1) . ' of ' . $total . ' could not be checked: ' . $error->getMessage(),
            ]);
        }
    }

    uvoc_batch_progress($progressToken, [
        'stage' => 'rendering',
        'done' => $total,
        'total' => $total,
        'percent' => 99,
        'current_index' => max(0, $total - 1),
        'file_percent' => 100,
        'message' => 'Rendering compact Object Check results',
    ]);

    catalog_head('Queued Package Object Check');
    echo <<<'CSS'
<style>
.uvoc-batch-file { margin-bottom:16px; }
.uvoc-batch-file:last-child { margin-bottom:0; }
.uvoc-batch-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
.uvoc-batch-actions { display:flex; justify-content:flex-end; margin-top:12px; }
@media (max-width:800px) { .uvoc-batch-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Queued Package Object Check',
        $total . ' selected queued file(s) inspected. Batch results show compact summaries; open one file for complete Names, Imports and Exports tables.'
    );

    foreach ($checks as $index => $check) {
        echo '<section class="ui-section uvoc-batch-file"><div class="ui-section__header"><div>';
        if ($check['item'] !== null) {
            $item = $check['item'];
            echo '<h2>' . catalog_h((string)$item['original_name']) . '</h2>';
            echo '<p>Queued in ' . catalog_h((string)$item['game']['name']) . '. Package key: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span>.</p>';
        } else {
            echo '<h2>Selected file ' . ($index + 1) . '</h2>';
        }
        echo '</div></div><div class="ui-section__body">';

        if ($check['error'] !== null) {
            echo CatalogUi::alert('danger', 'Object Check could not open this queued file.', (string)$check['error']);
            echo '</div></section>';
            continue;
        }

        if ($check['analysis_error'] !== null) {
            echo CatalogUi::alert('warning', 'Object tables could not be read for this queued file.', (string)($check['analysis_error']['message'] ?? 'The queued file was not changed.'));
        } elseif ($check['reader'] !== null) {
            $reader = $check['reader'];
            echo '<div class="uvoc-batch-stats">';
            echo '<div class="stat"><h2>' . catalog_h($reader['engine']) . '</h2><p>Reader</p></div>';
            echo '<div class="stat"><h2>' . (int)$reader['name_count'] . '</h2><p>Names</p></div>';
            echo '<div class="stat"><h2>' . (int)$reader['import_count'] . '</h2><p>Imports</p></div>';
            echo '<div class="stat"><h2>' . (int)$reader['export_count'] . '</h2><p>Exports</p></div>';
            echo '</div>';
            uvoc_batch_candidate_table($check['candidates']);
        }

        echo '<div class="uvoc-batch-actions"><a class="button secondary" target="_blank" rel="noopener" href="unverified-object-check.php?token=' . rawurlencode((string)$check['token']) . '">Open full package tables</a></div>';
        echo '</div></section>';
    }

    uvoc_batch_result_marker('complete');
    uvoc_batch_progress($progressToken, [
        'stage' => 'done',
        'done' => $total,
        'total' => $total,
        'percent' => 100,
        'current_index' => max(0, $total - 1),
        'file_percent' => 100,
        'message' => 'Object Check complete',
    ]);
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB object check batch] ' . $error->getMessage());
    uvoc_batch_progress($progressToken, [
        'stage' => 'failed',
        'done' => 0,
        'total' => 0,
        'percent' => 100,
        'message' => 'Object Check failed: ' . $error->getMessage(),
    ]);

    catalog_head('Queued Package Object Check Error');
    echo CatalogUi::alert('danger', 'Queued package Object Check could not be opened.', $error->getMessage());
    uvoc_batch_result_marker('error', $error->getMessage());
    catalog_foot();
}
