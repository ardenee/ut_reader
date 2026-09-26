<?php
/**
 * Administrator UI for game-scoped Unreal ClassRemap compatibility rules.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassRemapRepository;

catalog_start_session();

function class_remap_form(array $games, ?array $row = null): void
{
    $editing = is_array($row);
    $id = $editing ? (int)($row['id'] ?? 0) : 0;
    $gameId = $editing ? (int)($row['game_id'] ?? 0) : 0;
    $mappings = $editing ? (string)($row['mappings_text'] ?? '') : '';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>'
        . ($editing ? 'Edit ClassRemap' : 'Add ClassRemap')
        . '</h2><p>Mappings are game-specific. Enter one <span class="mono">OldClass=NewClass</span> mapping per line. A literal backslash separator is also accepted.</p></div></div><div class="ui-section__body">';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('class_remap')) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . $id . '">';
    echo '<p><label>Game<br><select name="game_id" required><option value="">Choose game</option>';
    foreach ($games as $game) {
        $selected = (int)$game['id'] === $gameId ? ' selected' : '';
        echo '<option value="' . (int)$game['id'] . '"' . $selected . '>' . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Class maps<br><textarea class="mono" name="mappings_text" rows="10" style="width:100%;max-width:900px" required placeholder="AnimatedParticleTemplate=Sprite3DParticleTemplate&#10;MultiSprite3DParticleTemplate=Sprite3DParticleTemplate">' . catalog_h($mappings) . '</textarea></label></p>';
    echo '<p class="muted small">Dependency resolution always tries the serialized import first. Only when that exact object cannot be found is the configured replacement object name tried; class package/name and outer matching rules remain unchanged.</p>';
    echo '<p><button class="button" type="submit">' . ($editing ? 'Update' : 'Add') . '</button> <a class="button" href="class-remap.php">Cancel</a></p></form></div></section>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('ClassRemap')) {
        exit;
    }

    $repository = new PdoClassRemapRepository($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            catalog_check_csrf('class_remap');
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $savedId = $repository->save(
                    $id > 0 ? $id : null,
                    (int)($_POST['game_id'] ?? 0),
                    (string)($_POST['mappings_text'] ?? '')
                );
                $_SESSION['class_remap_flash'] = ($id > 0 ? 'ClassRemap updated.' : 'ClassRemap added.')
                    . ' Run Dependency Refresh for the affected game to rewrite existing dependency results.';
                header('Location: class-remap.php?mode=edit&id=' . $savedId);
                exit;
            }
            if ($action === 'delete') {
                $repository->delete((int)($_POST['id'] ?? 0));
                $_SESSION['class_remap_flash'] = 'ClassRemap removed. Run Dependency Refresh for the affected game to rewrite existing dependency results.';
                header('Location: class-remap.php');
                exit;
            }
            throw new RuntimeException('Unknown ClassRemap action.');
        } catch (Throwable $error) {
            $_SESSION['class_remap_flash'] = $error->getMessage();
            header('Location: class-remap.php');
            exit;
        }
    }

    $games = $repository->games();
    $rows = $repository->listAll();
    $mode = (string)($_GET['mode'] ?? '');
    $edit = $mode === 'edit' ? $repository->find((int)($_GET['id'] ?? 0)) : null;

    catalog_head('ClassRemap');
    catalog_flash($_SESSION['class_remap_flash'] ?? null);
    unset($_SESSION['class_remap_flash']);

    catalog_page_header(
        'ClassRemap',
        'Configure game-specific Unreal class compatibility remaps used as a dependency-linking fallback.',
        ['Missing Dependencies' => 'missing.php', 'Dependency Refresh' => 'dependency-refresh.php', 'Games' => 'games.php']
    );

    echo '<div class="card"><h2>Configured ClassRemaps</h2><p class="muted">These mappings are intentionally configuration-driven. UnrealDB does not infer them from native class declarations or silently invent compatibility aliases.</p>';
    if ($rows === []) {
        echo '<p class="muted">No ClassRemaps configured.</p>';
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>Game</th><th>Mappings</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . catalog_h((string)$row['game_name']) . '</td>';
            echo '<td><pre class="mono" style="white-space:pre-wrap;margin:0">' . catalog_h((string)$row['mappings_text']) . '</pre></td>';
            echo '<td class="mono small">' . catalog_h((string)$row['updated_at']) . '</td>';
            echo '<td><a class="button" href="class-remap.php?mode=edit&id=' . (int)$row['id'] . '">Edit</a> '
                . '<form method="post" style="display:inline" onsubmit="return confirm(\'Remove this ClassRemap configuration?\')">'
                . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('class_remap')) . '">'
                . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$row['id'] . '">'
                . '<button class="button" type="submit">Remove</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p><a class="button" href="class-remap.php?mode=new">Add ClassRemap</a></p></div>';

    if ($mode === 'new') {
        class_remap_form($games);
    } elseif ($mode === 'edit') {
        if ($edit === null) {
            echo CatalogUi::alert('danger', 'The selected ClassRemap entry could not be found.');
        } else {
            class_remap_form($games, $edit);
        }
    }

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('ClassRemap error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'ClassRemap administration failed.');
    catalog_foot();
}
