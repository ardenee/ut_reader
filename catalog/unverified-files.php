<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';

function unverified_files_int(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : max(0, (int)$value);
}

function unverified_files_return_url(int $sourceGameId): string
{
    return 'unverified-files.php' . ($sourceGameId > 0 ? '?source_game_id=' . $sourceGameId : '');
}

/** @return list<string> */
function unverified_files_selected_tokens(): array
{
    $posted = $_POST['tokens'] ?? [];
    if (!is_array($posted)) {
        return [];
    }

    $tokens = [];
    foreach ($posted as $token) {
        if (!is_string($token)) {
            continue;
        }
        $token = trim($token);
        if ($token !== '') {
            $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

function unverified_files_header_label(array $item): string
{
    $header = $item['header'] ?? [];
    $engine = (string)($header['engine'] ?? 'UNKNOWN');
    if (!empty($header['ok'])) {
        return $engine . ' v' . (int)($header['version'] ?? 0) . ' / lic ' . (int)($header['licensee'] ?? 0);
    }
    $note = trim((string)($header['note'] ?? ''));
    return $engine . ($note !== '' ? ' — ' . $note : '');
}

function unverified_files_identity_html(array $item): string
{
    $md5 = trim((string)($item['md5'] ?? ''));
    $guid = trim((string)($item['package_guid'] ?? ''));
    return '<span>MD5: ' . ($md5 !== '' ? catalog_h($md5) : '<span class="muted">unavailable</span>') . '</span>'
        . '<span>GUID: ' . ($guid !== '' ? catalog_h($guid) : '<span class="muted">unavailable</span>') . '</span>';
}

function unverified_files_reference_html(array $references): string
{
    if ($references === []) {
        return '<span class="muted">No catalog imports currently require this package name.</span>';
    }

    $html = '<div class="unverified-reference-list">';
    foreach ($references as $reference) {
        $html .= '<a class="pill amber" href="game-files.php?id=' . (int)$reference['game_id'] . '" title="Open game files that import this package name">'
            . catalog_h($reference['game_name']) . ': '
            . (int)$reference['import_count'] . ' import' . ((int)$reference['import_count'] === 1 ? '' : 's')
            . ' / ' . (int)$reference['owner_count'] . ' file' . ((int)$reference['owner_count'] === 1 ? '' : 's')
            . '</a>';
    }
    return $html . '</div>';
}

function unverified_files_bulk_action_label(string $action): string
{
    return match ($action) {
        'move' => 'Move queue',
        'import' => 'Import selected',
        'delete' => 'Delete file',
        default => 'Selected action',
    };
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Unverified Files')) {
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id, g.name, g.slug, p.engine_key profile_engine'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' ORDER BY g.name'
    );
    $sourceGameId = unverified_files_int('source_game_id');
    $knownGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
    if ($sourceGameId > 0 && !in_array($sourceGameId, $knownGameIds, true)) {
        $sourceGameId = 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('unverified-files');
        $action = (string)($_POST['action'] ?? '');
        $tokens = unverified_files_selected_tokens();
        $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
        $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
        $returnGameId = filter_input(INPUT_POST, 'return_game_id', FILTER_VALIDATE_INT);
        $returnGameId = $returnGameId === false || $returnGameId === null ? 0 : max(0, (int)$returnGameId);
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

        try {
            if (!in_array($action, ['move', 'import', 'delete'], true)) {
                throw new RuntimeException('Unknown unverified queue action.');
            }
            if ($tokens === []) {
                throw new RuntimeException('Select at least one queued file first.');
            }
            if (in_array($action, ['move', 'import'], true) && $targetGameId < 1) {
                throw new RuntimeException('Choose a target game before using ' . unverified_files_bulk_action_label($action) . '.');
            }

            $completed = [];
            $errors = [];
            foreach ($tokens as $token) {
                try {
                    if ($action === 'move') {
                        $result = uvf_move($db, $config, $token, $targetGameId);
                        $completed[] = $result['original_name'];
                    } elseif ($action === 'import') {
                        $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
                        $result = uvf_import($db, $config, $token, $targetGameId, $userId, $allowOverride);
                        $completed[] = $result['original_name'];
                    } else {
                        $result = uvf_discard($db, $config, $token);
                        $completed[] = $result['original_name'];
                    }
                } catch (Throwable $error) {
                    $errors[] = $error->getMessage();
                }
            }

            $label = unverified_files_bulk_action_label($action);
            $message = $label . ': ' . count($completed) . ' of ' . count($tokens) . ' selected file(s) processed.';
            if ($action === 'move' && $completed !== []) {
                $target = catalog_one($db, 'SELECT name FROM ue_games WHERE id=?', [$targetGameId]);
                if ($target) {
                    $message .= ' Moved to ' . $target['name'] . ' unverified queue.';
                }
            }
            if ($action === 'import' && $completed !== []) {
                $target = catalog_one($db, 'SELECT name FROM ue_games WHERE id=?', [$targetGameId]);
                if ($target) {
                    $message .= ' Imported into ' . $target['name'] . '.';
                }
            }
            if ($action === 'delete' && $completed !== []) {
                $message .= ' Deleted from unverified storage.';
            }
            if ($errors !== []) {
                $message .= ' ' . count($errors) . ' file(s) could not be processed.';
            }
            if ($completed === [] && $errors !== []) {
                throw new RuntimeException($message . ' ' . implode(' | ', array_slice($errors, 0, 3)));
            }

            $_SESSION['unverified_files_flash'] = [
                'type' => $errors === [] ? 'success' : 'warning',
                'message' => $message,
                'details' => $errors === [] ? '' : implode("\n", array_slice($errors, 0, 20)),
            ];
        } catch (Throwable $error) {
            $_SESSION['unverified_files_flash'] = ['type' => 'danger', 'message' => $error->getMessage(), 'details' => ''];
        }

        header('Location: ' . unverified_files_return_url($returnGameId));
        exit;
    }

    $items = uvf_list($db, $config, $sourceGameId > 0 ? $sourceGameId : null);
    $referencesByPackage = uvf_reference_matches($db, array_map(static fn(array $item): string => (string)$item['package_name'], $items));
    $flash = $_SESSION['unverified_files_flash'] ?? null;
    unset($_SESSION['unverified_files_flash']);

    catalog_head('Unverified Files');
    echo <<<'CSS'
<style>
.unverified-filter { display:flex; align-items:end; gap:10px; flex-wrap:wrap; }
.unverified-filter label { display:grid; gap:5px; }
.unverified-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:0 0 18px; }
.unverified-note { border-left:4px solid #f6c453; padding-left:12px; }
.unverified-workspace { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
.unverified-table { min-width:1340px; }
.unverified-table th, .unverified-table td { vertical-align:top; }
.unverified-select-col { width:42px; text-align:center; }
.unverified-select-col input { margin:3px 0 0; }
.unverified-name { min-width:210px; }
.unverified-identity { min-width:350px; white-space:nowrap; }
.unverified-identity span { display:block; }
.unverified-references { min-width:255px; }
.unverified-reference-list { display:flex; flex-wrap:wrap; gap:5px; }
.unverified-meta { display:block; margin-top:4px; }
.unverified-rejection-row td { padding-top:0; border-top:0; white-space:pre-wrap; overflow-wrap:anywhere; }
.unverified-rejection-row strong { color:var(--muted); }
.unverified-rejection-row .unverified-rejection-text { display:block; margin-top:4px; }
.unverified-bulk { position:sticky; top:12px; padding:14px; border:1px solid var(--line2); border-radius:10px; background:rgba(255,255,255,.025); }
.unverified-bulk h3 { margin:0 0 8px; }
.unverified-bulk p { margin:0 0 12px; }
.unverified-bulk label { display:grid; gap:5px; margin:0 0 10px; }
.unverified-bulk select { width:100%; }
.unverified-bulk .unverified-bulk-override { display:flex; align-items:flex-start; gap:6px; font-size:12px; color:var(--muted); }
.unverified-bulk-actions { display:grid; gap:7px; margin-top:12px; }
.unverified-bulk-actions .button { width:100%; text-align:center; }
.unverified-bulk-count { color:var(--muted); font-size:13px; }
.unverified-bulk-help { color:var(--muted); font-size:12px; line-height:1.4; }
.unverified-bulk [disabled] { opacity:.5; cursor:not-allowed; }
@media (max-width:1100px) { .unverified-workspace { grid-template-columns:1fr; } .unverified-bulk { position:static; } }
@media (max-width:700px) { .unverified-summary { grid-template-columns:1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Unverified Files',
        'Review rejected packages, identify games that require their package names, then process selected files together.',
        ['Upload Files' => 'profiled-upload.php', 'Game Profiles' => 'game-profiles.php', 'Sources' => 'sources.php', 'Storage Audit' => 'storage-audit.php']
    );

    if (is_array($flash) && !empty($flash['message'])) {
        echo CatalogUi::alert((string)($flash['type'] ?? 'info'), (string)$flash['message'], (string)($flash['details'] ?? ''));
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Queue filter</h2><p>Unverified files are physical files only. They do not have a ue_files catalog record until an import succeeds.</p></div></div><div class="ui-section__body">';
    echo '<form class="unverified-filter" method="get"><label for="unverified-source-game">Source queue<select id="unverified-source-game" name="source_game_id"><option value="">All game queues</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h($game['name']) . ' / ' . catalog_h((string)($game['profile_engine'] ?: 'no profile')) . '</option>';
    }
    echo '</select></label>' . CatalogUi::button('Apply filter', ['type' => 'submit', 'variant' => 'secondary']) . '</form>';
    echo '</div></section>';

    $totalBytes = array_sum(array_map(static fn(array $item): int => (int)$item['size'], $items));
    $referenceCandidateCount = count(array_filter($items, static function (array $item) use ($referencesByPackage): bool {
        return !empty($referencesByPackage[strtolower((string)$item['package_name'])] ?? []);
    }));
    echo '<div class="unverified-summary">';
    echo '<div class="stat"><h2>' . count($items) . '</h2><p>Queued unverified files</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes($totalBytes)) . '</h2><p>Queued storage</p></div>';
    echo '<div class="stat"><h2>' . $referenceCandidateCount . '</h2><p>Filename package candidates</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Unverified package queues</h2><p class="unverified-note">A Reference Candidate is a <strong>filename package-name match</strong>: one or more catalogued files in the listed game have Imports requiring this queued file’s package name. Select files using the first column, then use the single action panel on the right. <strong>Move queue</strong> moves selected physical files to the selected game’s unverified folder. <strong>Import selected</strong> catalogs selected files under the selected game. <strong>Object check</strong> opens a popup and does not change files. <strong>Delete file</strong> permanently removes the selected physical files and their rejection text.</p></div></div><div class="ui-section__body">';
    if ($items === []) {
        echo CatalogUi::emptyState('No unverified files found', 'There are no physical files in the selected unverified queue folders.');
    } else {
        echo '<form id="unverified-bulk-form" method="post" class="unverified-workspace">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('unverified-files')) . '">';
        echo '<input type="hidden" name="return_game_id" value="' . $sourceGameId . '">';
        echo '<div class="table-wrap"><table class="unverified-table"><thead><tr>';
        echo '<th class="unverified-select-col"><input type="checkbox" id="unverified-select-all" aria-label="Select all queued files"></th>';
        echo '<th>Source Queue</th><th>File</th><th class="unverified-identity">MD5 / GUID</th><th>Detected Header</th><th>Size</th><th class="unverified-references" title="Filename package-name candidates. Object check performs an actual exported-object comparison.">Reference Candidates</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            $packageKey = strtolower((string)$item['package_name']);
            $references = $referencesByPackage[$packageKey] ?? [];
            $reason = trim((string)$item['reason']);
            if ($reason === '') {
                $reason = 'No rejection reason file was found.';
            }
            $rowId = 'unverified-file-' . (string)$item['token'];

            echo '<tr id="' . catalog_h($rowId) . '">';
            echo '<td class="unverified-select-col"><input class="unverified-select" type="checkbox" name="tokens[]" value="' . catalog_h((string)$item['token']) . '" aria-label="Select ' . catalog_h((string)$item['original_name']) . '"></td>';
            echo '<td><strong>' . catalog_h((string)$item['game']['name']) . '</strong><span class="muted small unverified-meta">' . catalog_h((string)$item['game']['slug']) . '/unverified</span></td>';
            echo '<td class="unverified-name"><strong class="mono">' . catalog_h((string)$item['original_name']) . '</strong><span class="muted small unverified-meta">Package: ' . catalog_h((string)$item['package_name']) . ' · .' . catalog_h((string)$item['extension']) . '<br>Queued: ' . catalog_h(date('Y-m-d H:i', (int)$item['modified_at'])) . '</span></td>';
            echo '<td class="mono small unverified-identity">' . unverified_files_identity_html($item) . '</td>';
            echo '<td class="mono small">' . catalog_h(unverified_files_header_label($item)) . '</td>';
            echo '<td class="mono small">' . catalog_h(catalog_bytes((int)$item['size'])) . '</td>';
            echo '<td class="unverified-references">' . unverified_files_reference_html($references) . '</td>';
            echo '</tr>';
            echo '<tr class="unverified-rejection-row"><td></td><td colspan="6"><strong>Original rejection</strong><span class="unverified-rejection-text mono small">' . catalog_h($reason) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<aside class="unverified-bulk" aria-label="Selected file actions">';
        echo '<h3>Selected files</h3><p id="unverified-bulk-count" class="unverified-bulk-count">0 selected</p>';
        echo '<label for="unverified-target-game">Target game<select id="unverified-target-game" name="target_game_id"><option value="">Choose target game</option>';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '">' . catalog_h((string)$game['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="unverified-bulk-override"><input type="checkbox" name="allow_profile_override" value="1" checked> Allow profile/version/extension override for deliberate reassignment.</label>';
        echo '<div class="unverified-bulk-actions">';
        echo '<button class="button secondary" type="submit" name="action" value="move" data-requires-target disabled>Move queue</button>';
        echo '<button class="button" type="submit" name="action" value="import" data-requires-target disabled>Import selected</button>';
        echo '<button class="button secondary" type="button" id="unverified-object-check" disabled>Object check</button>';
        echo '<button class="button danger" type="submit" name="action" value="delete" data-delete-action disabled>Delete file</button>';
        echo '</div>';
        echo '<p class="unverified-bulk-help">Move does not import. Object check opens a separate popup and does not change any file. Delete file removes the queued package and its `.txt` reason permanently.</p>';
        echo '</aside>';
        echo '</form>';
    }
    echo '</div></section>';

    echo <<<'JS'
<script>
(function () {
    'use strict';
    var form = document.getElementById('unverified-bulk-form');
    if (!form) return;

    var selectAll = document.getElementById('unverified-select-all');
    var count = document.getElementById('unverified-bulk-count');
    var target = document.getElementById('unverified-target-game');
    var objectCheck = document.getElementById('unverified-object-check');
    var checkboxes = Array.prototype.slice.call(form.querySelectorAll('.unverified-select'));
    var actionButtons = Array.prototype.slice.call(form.querySelectorAll('button[name="action"]'));

    function selectedTokens() {
        return checkboxes.filter(function (box) { return box.checked; }).map(function (box) { return box.value; });
    }

    function updateSelection() {
        var selected = selectedTokens();
        count.textContent = selected.length + ' selected';
        if (selectAll) {
            selectAll.checked = selected.length > 0 && selected.length === checkboxes.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
        }
        actionButtons.forEach(function (button) {
            button.disabled = selected.length === 0;
        });
        objectCheck.disabled = selected.length === 0;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (box) { box.checked = selectAll.checked; });
            updateSelection();
        });
    }
    checkboxes.forEach(function (box) { box.addEventListener('change', updateSelection); });

    form.addEventListener('submit', function (event) {
        var submitter = event.submitter;
        if (!submitter) return;
        var selected = selectedTokens();
        if (selected.length === 0) {
            event.preventDefault();
            window.alert('Select at least one queued file first.');
            return;
        }
        if (submitter.hasAttribute('data-requires-target') && !target.value) {
            event.preventDefault();
            window.alert('Choose a target game first.');
            target.focus();
            return;
        }
        if (submitter.hasAttribute('data-delete-action') && !window.confirm('Delete ' + selected.length + ' selected queued file(s) and their rejection text permanently?')) {
            event.preventDefault();
        }
    });

    objectCheck.addEventListener('click', function () {
        var selected = selectedTokens();
        if (selected.length === 0) {
            window.alert('Select at least one queued file first.');
            return;
        }
        var query = new URLSearchParams();
        selected.forEach(function (token) { query.append('tokens[]', token); });
        var url = 'unverified-object-check.php?' + query.toString();
        var popup = window.open(url, 'unverified-object-check', 'popup=yes,width=1220,height=860,resizable=yes,scrollbars=yes');
        if (!popup) {
            window.location.assign(url);
            return;
        }
        popup.focus();
    });

    updateSelection();
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Unverified Files Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The unverified queue manager could not be loaded.');
    catalog_foot();
}
