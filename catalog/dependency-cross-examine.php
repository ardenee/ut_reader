<?php
/**
 * Cross-examine sibling games for verified packages that can satisfy exact missing dependencies.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\PdoGameDependencyCrossExamineQuery;

function cross_exam_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function cross_exam_text(string $key): string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    return is_string($value) ? trim($value) : '';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Dependency Cross-Examine')) {
        exit;
    }

    $query = new PdoGameDependencyCrossExamineQuery($db, $config);
    $games = $query->games();
    $targetGameId = max(0, cross_exam_int('target_game_id', 0));
    $sourceGameId = max(0, cross_exam_int('source_game_id', 0));
    $limit = max(10, min(500, cross_exam_int('limit', 100)));
    $notice = cross_exam_text('notice');
    $error = cross_exam_text('error');

    $model = null;
    if ($targetGameId > 0) {
        $model = $query->fetch($targetGameId, $sourceGameId, $limit);
    }

    catalog_head('Dependency Cross-Examine');
    echo <<<'CSS'
<style>
.cross-controls{display:grid;grid-template-columns:minmax(220px,1.2fr) minmax(220px,1.2fr) 120px auto;gap:10px;align-items:end}.cross-controls label{display:flex;flex-direction:column;gap:4px}.cross-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:12px 0}.cross-table{min-width:1250px}.cross-table td{vertical-align:top}.cross-coverage strong{display:block}.cross-good{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;color:#b8f3cb;background:rgba(67,190,110,.15)}.cross-warn{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;color:#f5d98b;background:rgba(246,196,83,.13)}.cross-note{padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:rgba(255,255,255,.025);color:var(--muted);margin:0 0 12px}.cross-action{margin:0}.cross-action button{white-space:nowrap}@media(max-width:900px){.cross-controls{grid-template-columns:1fr 1fr}.cross-summary{grid-template-columns:1fr}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Dependency Cross-Examine',
        'Find packages already verified in same-engine sibling games that export objects currently recorded as missing by the target game.',
        [
            'Unverified files' => 'unverified-files.php',
            'Missing dependencies' => 'missing.php',
            'Background jobs' => 'background-jobs.php',
        ]
    );

    if ($notice !== '') {
        echo CatalogUi::alert('success', 'Copy queued', $notice);
    }
    if ($error !== '') {
        echo CatalogUi::alert('danger', 'Could not queue copy', $error);
    }

    echo '<p class="cross-note"><strong>How candidates are found:</strong> the target game\'s current dependency rows with status <span class="mono">missing</span> are the authoritative input. For each missing package/object path, the report checks verified files in the selected same-engine source game(s) and keeps a source only when its recorded exports contain that exact required object path. Package-version and target-profile ranges do not remove an otherwise exact provider from this report.</p>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Compare games</h2><p>Choose the game whose missing dependencies you want to repair.</p></div></div><div class="ui-section__body">';
    echo '<form class="cross-controls" method="get">';
    echo '<label>Target game<select name="target_game_id"><option value="">Choose target game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($targetGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name'] . ' / ' . (string)$game['engine_key']) . '</option>';
    }
    echo '</select></label>';

    $sourceGames = is_array($model['source_games'] ?? null) ? $model['source_games'] : [];
    echo '<label>Source game<select name="source_game_id"><option value="0">All same-engine games</option>';
    foreach ($sourceGames as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Max results<select name="limit">';
    foreach ([25, 50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($limit === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><div><button type="submit">Cross-examine</button></div></form></div></section>';

    if ($targetGameId < 1) {
        echo CatalogUi::emptyState('Choose a target game', 'The report will compare that game against other active games using the same engine profile family.');
        catalog_foot();
        exit;
    }

    $rows = is_array($model['rows'] ?? null) ? $model['rows'] : [];
    $target = is_array($model['target'] ?? null) ? $model['target'] : [];
    $diagnostics = is_array($model['diagnostics'] ?? null) ? $model['diagnostics'] : [];

    echo '<p class="cross-note"><strong>Scan input:</strong> '
        . number_format((int)($diagnostics['missing_dependency_rows'] ?? 0)) . ' actual missing dependency row(s) across '
        . number_format((int)($diagnostics['missing_packages'] ?? 0)) . ' package(s); '
        . number_format((int)($diagnostics['source_package_files'] ?? 0)) . ' verified source package file(s) had matching package names and were examined; '
        . number_format((int)($diagnostics['metadata_unreadable'] ?? 0)) . ' source metadata snapshot(s) could not be read.</p>';

    $exactTotal = 0;
    $ownerTotal = 0;
    foreach ($rows as $row) {
        $exactTotal += (int)($row['exact_object_matches'] ?? 0);
        $ownerTotal += (int)($row['exact_owner_count'] ?? 0);
    }
    echo '<div class="cross-summary">'
        . '<div class="stat"><h2>' . count($rows) . '</h2><p>Exact provider candidates</p></div>'
        . '<div class="stat"><h2>' . number_format($exactTotal) . '</h2><p>Exact missing dependency references covered</p></div>'
        . '<div class="stat"><h2>' . number_format($ownerTotal) . '</h2><p>Referencing files covered across candidates</p></div>'
        . '</div>';

    if ($rows === []) {
        echo CatalogUi::emptyState(
            'No exact sibling-game providers found',
            'The scan started from the target game\'s actual missing dependency rows. No verified package in the selected same-engine source scope exported one of those exact required object paths. The scan-input counts above show which stage produced no candidates.'
        );
        catalog_foot();
        exit;
    }

    echo '<div class="table-wrap"><table class="cross-table"><thead><tr>'
        . '<th>Source game</th><th>Package / file</th><th>Identity</th><th>Detected</th>'
        . '<th>Target need</th><th>Exact coverage</th><th>Action</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $sourceFileId = (int)$row['id'];
        $exact = (int)$row['exact_object_matches'];
        $missing = (int)$row['target_missing_count'];
        $owners = (int)$row['target_owner_count'];
        $exactOwners = (int)$row['exact_owner_count'];
        $coverage = number_format((float)$row['coverage_percent'], 1) . '%';
        $alreadyInTarget = !empty($row['already_in_target']);
        echo '<tr>';
        echo '<td><strong>' . catalog_h((string)$row['source_game_name']) . '</strong><small class="muted">'
            . catalog_h((string)$row['source_engine']) . '</small></td>';
        echo '<td><strong><a href="file-info.php?id=' . $sourceFileId . '">'
            . catalog_h((string)$row['package_name']) . '</a></strong><small>'
            . catalog_h((string)$row['original_name']) . '</small><small>'
            . catalog_h(catalog_bytes((int)$row['file_size'])) . '</small></td>';
        echo '<td><span class="mono small">GUID: ' . catalog_h((string)($row['package_guid'] ?? '')) . '</span><br>'
            . '<span class="mono small">MD5: ' . catalog_h((string)$row['md5']) . '</span><br>'
            . '<span class="mono small">SHA: ' . catalog_h((string)($row['sha1'] ?? '')) . '</span></td>';
        echo '<td class="mono">' . catalog_h((string)$row['detected_engine_key'])
            . ' v' . (int)$row['detected_package_version']
            . '<br><span class="muted">lic ' . (int)$row['detected_licensee_version'] . '</span></td>';
        echo '<td><strong>' . number_format($missing) . '</strong> missing dependency reference'
            . ($missing === 1 ? '' : 's') . '<small>' . number_format($owners) . ' referencing file'
            . ($owners === 1 ? '' : 's') . '</small></td>';
        echo '<td class="cross-coverage"><span class="cross-good">Exact object paths</span><strong>'
            . number_format($exact) . ' / ' . number_format($missing) . ' (' . catalog_h($coverage) . ')</strong><small>'
            . number_format($exactOwners) . ' referencing file' . ($exactOwners === 1 ? '' : 's')
            . ' receive at least one exact match.</small></td>';

        if ($alreadyInTarget) {
            $existingId = (int)($row['target_existing_file_id'] ?? 0);
            echo '<td><span class="cross-warn">Already in target</span><small>Identical MD5 is already verified as '
                . ($existingId > 0 ? '<a href="file-info.php?id=' . $existingId . '">file #' . $existingId . '</a>' : 'a target file')
                . '. Rebuild target dependencies rather than copying it again.</small></td></tr>';
            continue;
        }

        echo '<td><form class="cross-action" method="post" action="dependency-cross-examine-action.php">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('dependency-cross-examine')) . '">'
            . '<input type="hidden" name="source_file_id" value="' . $sourceFileId . '">'
            . '<input type="hidden" name="target_game_id" value="' . $targetGameId . '">'
            . '<input type="hidden" name="source_game_id" value="' . $sourceGameId . '">'
            . '<button type="submit">Queue copy to ' . catalog_h((string)$target['name']) . '</button>'
            . '</form></td></tr>';
    }
    echo '</tbody></table></div>';

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Dependency Cross-Examine Error');
    echo CatalogUi::alert('danger', 'Dependency Cross-Examine could not be loaded.', $error->getMessage());
    catalog_foot();
}
