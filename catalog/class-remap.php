<?php
/** Admin-only game-scoped Unreal ClassRemap configuration. */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogClassRemapAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('ClassRemap')) {
        exit;
    }

    $service = new CatalogClassRemapAdminService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('class-remap');
        $result = $service->handle((string)($_POST['action'] ?? ''), $_POST);
        $_SESSION['class_remap_flash'] = $result['message'];
        header('Location: class-remap.php' . ($result['id'] > 0 ? '?edit=' . (int)$result['id'] : ''));
        exit;
    }

    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    $editRow = $editId ? $service->find((int)$editId) : null;
    $games = $service->games();
    $rows = $service->rows();

    catalog_head('ClassRemap');
    catalog_flash($_SESSION['class_remap_flash'] ?? null);
    unset($_SESSION['class_remap_flash']);

    echo <<<'CSS'
<style>
.class-remap-form { display:grid; gap:12px; max-width:900px; }
.class-remap-form label { display:grid; gap:5px; }
.class-remap-form textarea { width:100%; min-height:180px; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.class-remap-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.class-remap-value { white-space:pre-wrap; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.class-remap-table td { vertical-align:top; }
</style>
CSS;

    catalog_page_header(
        'ClassRemap',
        'Configure explicit game-scoped compatibility remaps used only after the original legacy UE1/UE2 VerifyImport object name cannot be found.',
        ['Missing Dependencies' => 'missing.php', 'Dependency Refresh' => 'dependency-refresh.php']
    );

    echo '<div class="card"><h2>Resolution policy</h2>'
        . '<p>Each line is <code>OldName=NewName</code>. Dependency linking always tries the serialized import name first. If it is not found, UnrealDB retries the mapped name against the same physical package provider while preserving ClassPackage, ClassName and the outer relationship. Remaps are game-specific, single-hop, and are not applied to UE3/UE4/UE5 resolution.</p>'
        . '</div>';

    $selectedGameId = (int)($editRow['game_id'] ?? 0);
    $mappingText = (string)($editRow['mappings_text'] ?? '');
    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>'
        . ($editRow ? 'Edit ClassRemap' : 'Add ClassRemap')
        . '</h2><p>One configuration row is stored per game. Saving a new entry for a game that already has one updates that game.</p></div></div><div class="ui-section__body">';
    echo '<form method="post" class="class-remap-form">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('class-remap')) . '">'
        . '<input type="hidden" name="action" value="save">'
        . '<input type="hidden" name="id" value="' . (int)($editRow['id'] ?? 0) . '">'
        . '<label>Game<select name="game_id" required><option value="">Choose game</option>';
    foreach ($games as $game) {
        $gameId = (int)$game['id'];
        echo '<option value="' . $gameId . '"' . ($selectedGameId === $gameId ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Class maps<textarea name="mappings_text" required placeholder="AnimatedParticleTemplate=Sprite3DParticleTemplate&#10;MultiSprite3DParticleTemplate=Sprite3DParticleTemplate">'
        . catalog_h($mappingText) . '</textarea></label>';
    echo '<div class="class-remap-actions"><button type="submit">' . ($editRow ? 'Save changes' : 'Add ClassRemap') . '</button>';
    if ($editRow) {
        echo CatalogUi::button('Cancel edit', ['href' => 'class-remap.php', 'variant' => 'secondary']);
    }
    echo '</div></form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Configured ClassRemaps</h2>'
        . '<p>Changes affect the next dependency rebuild/refresh; existing dependency rows are not rewritten by editing this page alone.</p></div></div><div class="ui-section__body">';
    if ($rows === []) {
        echo CatalogUi::emptyState('No ClassRemaps configured', 'Add a game-specific mapping above.');
    } else {
        echo '<div class="ui-table-region"><table class="class-remap-table"><thead><tr><th>Game</th><th>Mappings</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            echo '<tr><td>' . catalog_h((string)$row['game_name']) . '</td>';
            echo '<td class="class-remap-value">' . catalog_h((string)$row['mappings_text']) . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$row['updated_at']) . '</td>';
            echo '<td><div class="class-remap-actions">'
                . CatalogUi::button('Edit', ['href' => 'class-remap.php?edit=' . $id, 'variant' => 'secondary', 'size' => 'sm'])
                . '<form method="post" onsubmit="return confirm(\'Remove this ClassRemap configuration?\')">'
                . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('class-remap')) . '">'
                . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">'
                . '<button type="submit">Remove</button></form></div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('ClassRemap error');
    }
    echo CatalogUi::alert('danger', $e->getMessage(), 'ClassRemap failed.');
    catalog_foot();
}
