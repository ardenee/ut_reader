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
        $token = (string)($_POST['token'] ?? '');
        $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
        $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
        $returnGameId = filter_input(INPUT_POST, 'return_game_id', FILTER_VALIDATE_INT);
        $returnGameId = $returnGameId === false || $returnGameId === null ? 0 : max(0, (int)$returnGameId);
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

        try {
            if ($action === 'move') {
                if ($targetGameId < 1) {
                    throw new RuntimeException('Choose a target game for the unverified queue move.');
                }
                $result = uvf_move($db, $config, $token, $targetGameId);
                $message = 'Moved ' . $result['original_name'] . ' from ' . $result['source_game'] . ' unverified queue to ' . $result['target_game'] . ' unverified queue. It remains unverified until imported.';
            } elseif ($action === 'import') {
                if ($targetGameId < 1) {
                    throw new RuntimeException('Choose a target game for the import.');
                }
                $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
                $result = uvf_import($db, $config, $token, $targetGameId, $userId, $allowOverride);
                $message = ($result['status'] === 'duplicate' ? 'Existing catalog match found; removed queued duplicate ' : 'Imported queued package ')
                    . $result['original_name'] . ' into ' . $result['target_game'] . '. ' . $result['message'];
            } elseif ($action === 'discard') {
                $result = uvf_discard($db, $config, $token);
                $message = 'Discarded ' . $result['original_name'] . ' from the ' . $result['source_game'] . ' unverified queue.';
            } else {
                throw new RuntimeException('Unknown unverified queue action.');
            }
            $_SESSION['unverified_files_flash'] = ['type' => 'success', 'message' => $message];
        } catch (Throwable $error) {
            $_SESSION['unverified_files_flash'] = ['type' => 'danger', 'message' => $error->getMessage()];
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
.unverified-table { min-width:1860px; }
.unverified-table th, .unverified-table td { vertical-align:top; }
.unverified-name { min-width:210px; }
.unverified-identity { min-width:350px; white-space:nowrap; }
.unverified-identity span { display:block; }
.unverified-references { min-width:255px; }
.unverified-reference-list { display:flex; flex-wrap:wrap; gap:5px; }
.unverified-actions { min-width:455px; }
.unverified-actions form { display:grid; grid-template-columns:minmax(130px,1fr) auto auto auto; gap:6px; align-items:center; margin:0; }
.unverified-actions select { width:100%; min-width:0; }
.unverified-actions .unverified-override,
.unverified-actions .unverified-action-hint { grid-column:1 / -1; margin:0; font-size:12px; color:var(--muted); }
.unverified-actions .unverified-override { display:flex; align-items:center; gap:6px; }
.unverified-actions .unverified-discard { grid-column:1 / -1; justify-self:start; margin-top:2px; }
.unverified-meta { display:block; margin-top:4px; }
.unverified-rejection-row td { padding-top:0; border-top:0; white-space:pre-wrap; overflow-wrap:anywhere; }
.unverified-rejection-row strong { color:var(--muted); }
.unverified-rejection-row .unverified-rejection-text { display:block; margin-top:4px; }
@media (max-width:700px) { .unverified-summary { grid-template-columns:1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Unverified Files',
        'Review rejected packages, identify games that require their package names, then move or import them deliberately.',
        ['Upload Files' => 'profiled-upload.php', 'Game Profiles' => 'game-profiles.php', 'Sources' => 'sources.php', 'Storage Audit' => 'storage-audit.php']
    );

    if (is_array($flash) && !empty($flash['message'])) {
        echo CatalogUi::alert((string)($flash['type'] ?? 'info'), (string)$flash['message']);
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

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Unverified package queues</h2><p class="unverified-note">A Reference Candidate is a <strong>filename package-name match</strong>: one or more catalogued files in the listed game have Imports requiring this queued file’s package name. The queued file itself has no database references yet. Use <strong>Object check</strong> to read this queued file’s actual Export table and compare it to those requested object paths. <strong>Move queue</strong> only changes its unverified folder. <strong>Import selected</strong> catalogs it directly under the currently selected target game; you do not need to move it first. <strong>Discard queued file</strong> permanently deletes the physical package and its rejection text.</p></div></div><div class="ui-section__body">';
    if ($items === []) {
        echo CatalogUi::emptyState('No unverified files found', 'There are no physical files in the selected unverified queue folders.');
    } else {
        echo '<div class="table-wrap"><table class="unverified-table"><thead><tr>';
        echo '<th>Source Queue</th><th>File</th><th class="unverified-identity">MD5 / GUID</th><th>Detected Header</th><th>Size</th><th class="unverified-references" title="Filename package-name candidates. Run Object check for an actual exported-object comparison.">Reference Candidates</th><th class="unverified-actions">Manage</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            $packageKey = strtolower((string)$item['package_name']);
            $references = $referencesByPackage[$packageKey] ?? [];
            $refsByGame = [];
            foreach ($references as $reference) {
                $refsByGame[(int)$reference['game_id']] = $reference;
            }
            $defaultTargetGameId = $references !== [] ? (int)$references[0]['game_id'] : (int)$item['game']['id'];
            $reason = trim((string)$item['reason']);
            if ($reason === '') {
                $reason = 'No rejection reason file was found.';
            }
            $rowId = 'unverified-file-' . (string)$item['token'];
            $objectCheckUrl = 'unverified-object-check.php?token=' . rawurlencode((string)$item['token']);

            echo '<tr id="' . catalog_h($rowId) . '">';
            echo '<td><strong>' . catalog_h((string)$item['game']['name']) . '</strong><span class="muted small unverified-meta">' . catalog_h((string)$item['game']['slug']) . '/unverified</span></td>';
            echo '<td class="unverified-name"><strong class="mono">' . catalog_h((string)$item['original_name']) . '</strong><span class="muted small unverified-meta">Package: ' . catalog_h((string)$item['package_name']) . ' · .' . catalog_h((string)$item['extension']) . '<br>Queued: ' . catalog_h(date('Y-m-d H:i', (int)$item['modified_at'])) . '</span></td>';
            echo '<td class="mono small unverified-identity">' . unverified_files_identity_html($item) . '</td>';
            echo '<td class="mono small">' . catalog_h(unverified_files_header_label($item)) . '</td>';
            echo '<td class="mono small">' . catalog_h(catalog_bytes((int)$item['size'])) . '</td>';
            echo '<td class="unverified-references">' . unverified_files_reference_html($references) . '</td>';
            echo '<td class="unverified-actions"><form method="post">';
            echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('unverified-files')) . '">';
            echo '<input type="hidden" name="token" value="' . catalog_h((string)$item['token']) . '">';
            echo '<input type="hidden" name="return_game_id" value="' . $sourceGameId . '">';
            echo '<select name="target_game_id" aria-label="Target game for ' . catalog_h((string)$item['original_name']) . '">';
            foreach ($games as $game) {
                $targetId = (int)$game['id'];
                $suffix = '';
                if (isset($refsByGame[$targetId])) {
                    $suffix = ' — ' . (int)$refsByGame[$targetId]['import_count'] . ' matching import' . ((int)$refsByGame[$targetId]['import_count'] === 1 ? '' : 's');
                }
                echo '<option value="' . $targetId . '"' . ($targetId === $defaultTargetGameId ? ' selected' : '') . '>'
                    . catalog_h((string)$game['name'] . $suffix) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" name="action" value="move" class="button secondary" title="Move only to the selected game unverified queue">Move queue</button>';
            echo '<button type="submit" name="action" value="import" class="button" title="Catalog this package under the selected target game">Import selected</button>';
            echo '<a class="button secondary" href="' . catalog_h($objectCheckUrl) . '" title="Read actual package exports and compare them to candidate dependency objects">Object check</a>';
            echo '<p class="unverified-action-hint">Move only reorganises the queue. Import selected catalogs directly into the selected game. Object check does not import.</p>';
            echo '<label class="unverified-override"><input type="checkbox" name="allow_profile_override" value="1" checked> Allow profile/version/extension override for this deliberate reassignment.</label>';
            echo '<button type="submit" name="action" value="discard" class="button danger unverified-discard" onclick="return confirm(\'Discard this queued file and its rejection text permanently?\');">Discard queued file</button>';
            echo '</form></td>';
            echo '</tr>';
            echo '<tr class="unverified-rejection-row"><td colspan="7"><strong>Original rejection</strong><span class="unverified-rejection-text mono small">' . catalog_h($reason) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Unverified Files Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The unverified queue manager could not be loaded.');
    catalog_foot();
}
