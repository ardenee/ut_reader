<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function engine_normalize_key(string $key): string
{
    return strtoupper(trim($key));
}

function engine_delete(PDO $db, int $engineId): void
{
    $engine = catalog_one($db, 'SELECT * FROM ue_engines WHERE id=?', [$engineId]);
    if (!$engine) {
        throw new RuntimeException('Engine not found.');
    }

    $profiles = catalog_all($db, 'SELECT profile_name, engine_key FROM ue_game_profiles WHERE engine_key=? ORDER BY profile_name, id', [(string)$engine['engine_key']]);
    if ($profiles) {
        $names = implode(', ', array_map(static fn($p) => (string)($p['profile_name'] ?: $p['engine_key']), $profiles));
        throw new RuntimeException('This engine is in use by profile(s): ' . $names . '. Change or delete those profiles first, then delete the engine.');
    }

    $db->prepare('DELETE FROM ue_engines WHERE id=?')->execute([$engineId]);
}

function engine_save(PDO $db, int $engineId, string $key, string $name, int $sortOrder, string $notes, int $active): int
{
    $key = engine_normalize_key($key);
    $name = trim($name);
    if ($key === '' || $name === '') {
        throw new RuntimeException('Engine key and name are required.');
    }
    if (!preg_match('/^[A-Z0-9_\-]+$/', $key)) {
        throw new RuntimeException('Engine key may only contain letters, numbers, underscore, and dash.');
    }

    if ($engineId > 0) {
        $existing = catalog_one($db, 'SELECT * FROM ue_engines WHERE id=?', [$engineId]);
        if (!$existing) {
            throw new RuntimeException('Engine not found.');
        }
        $oldKey = (string)$existing['engine_key'];
        $other = catalog_one($db, 'SELECT id FROM ue_engines WHERE engine_key=? AND id<>? LIMIT 1', [$key, $engineId]);
        if ($other) {
            throw new RuntimeException('Another engine already uses that key.');
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE ue_engines SET engine_key=?, engine_name=?, sort_order=?, notes=?, is_active=? WHERE id=?')->execute([$key, $name, $sortOrder, $notes ?: null, $active, $engineId]);
            if ($oldKey !== $key) {
                $db->prepare('UPDATE ue_game_profiles SET engine_key=? WHERE engine_key=?')->execute([$key, $oldKey]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $engineId;
    }

    $stmt = $db->prepare('INSERT INTO ue_engines(engine_key,engine_name,sort_order,notes,is_active) VALUES(?,?,?,?,?)');
    $stmt->execute([$key, $name, $sortOrder, $notes ?: null, $active]);
    return (int)$db->lastInsertId();
}

function engine_form(?array $engine, string $mode): void
{
    $isEdit = $mode === 'edit';
    $button = $isEdit ? 'Update' : 'Add';
    $title = $isEdit ? 'Edit engine: ' . (string)$engine['engine_key'] : 'Add new engine';

    echo '<div class="card"><h2>' . catalog_h($title) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('engines')) . '"><input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'add') . '"><input type="hidden" name="engine_id" value="' . (int)($engine['id'] ?? 0) . '"><table>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($engine['engine_key'] ?? '') . '" style="width:160px" placeholder="UE1"></td></tr>';
    echo '<tr><th>Engine name</th><td><input name="engine_name" required value="' . catalog_h($engine['engine_name'] ?? '') . '" style="min-width:420px" placeholder="Unreal Engine 1"></td></tr>';
    echo '<tr><th>Sort order</th><td><input name="sort_order" value="' . catalog_h((string)($engine['sort_order'] ?? '100')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Active</th><td><select name="is_active"><option value="1"' . ((int)($engine['is_active'] ?? 1) === 1 ? ' selected' : '') . '>yes</option><option value="0"' . ((int)($engine['is_active'] ?? 1) === 0 ? ' selected' : '') . '>no</option></select></td></tr>';
    echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" style="width:100%">' . catalog_h($engine['notes'] ?? '') . '</textarea></td></tr>';
    echo '</table><p><button class="button">' . catalog_h($button) . '</button> <a class="button" href="engines.php">Cancel</a></p></form></div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Engines')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            catalog_check_csrf('engines');
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'delete') {
                engine_delete($db, (int)($_POST['engine_id'] ?? 0));
                $_SESSION['engines_flash'] = 'Engine deleted.';
                header('Location: engines.php');
                exit;
            }
            if ($action === 'add' || $action === 'update') {
                $engineId = $action === 'update' ? (int)($_POST['engine_id'] ?? 0) : 0;
                $savedId = engine_save($db, $engineId, (string)($_POST['engine_key'] ?? ''), (string)($_POST['engine_name'] ?? ''), (int)($_POST['sort_order'] ?? 100), trim((string)($_POST['notes'] ?? '')), (int)($_POST['is_active'] ?? 1));
                $_SESSION['engines_flash'] = $action === 'add' ? 'Engine added.' : 'Engine updated.';
                header('Location: engines.php?engine_id=' . $savedId . '&mode=edit');
                exit;
            }
        } catch (Throwable $e) {
            $_SESSION['engines_flash'] = $e->getMessage();
            header('Location: engines.php');
            exit;
        }
    }

    catalog_head('Engines');
    catalog_flash($_SESSION['engines_flash'] ?? null);
    unset($_SESSION['engines_flash']);

    $engines = catalog_all($db, 'SELECT e.*, COUNT(p.id) profile_count FROM ue_engines e LEFT JOIN ue_game_profiles p ON p.engine_key=e.engine_key GROUP BY e.id ORDER BY e.sort_order, e.engine_key');
    $mode = (string)($_GET['mode'] ?? '');
    $editId = (int)($_GET['engine_id'] ?? 0);
    $edit = null;
    foreach ($engines as $engine) {
        if ((int)$engine['id'] === $editId) {
            $edit = $engine;
            break;
        }
    }

    catalog_page_header('Engines', 'Create and maintain Unreal engine keys used by reusable game profiles. Game profiles select from this list instead of using hard-coded text.', ['Game Profiles' => 'game-profiles.php', 'Game Admin' => 'game-manager.php']);

    echo '<div class="card"><h2>Engines</h2>';
    if (!$engines) {
        echo '<p class="muted">No engines configured yet.</p>';
    } else {
        echo '<table><tr><th>Key</th><th>Name</th><th>Sort</th><th>Active</th><th>Profiles</th><th>Notes</th><th>Actions</th></tr>';
        foreach ($engines as $engine) {
            echo '<tr><td><span class="pill good-pill">' . catalog_h($engine['engine_key']) . '</span></td><td>' . catalog_h($engine['engine_name']) . '</td><td>' . (int)$engine['sort_order'] . '</td><td>' . ((int)$engine['is_active'] ? 'yes' : 'no') . '</td><td>' . (int)$engine['profile_count'] . '</td><td>' . catalog_h($engine['notes']) . '</td><td><a class="button" href="engines.php?engine_id=' . (int)$engine['id'] . '&mode=edit">Edit</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this engine?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('engines')) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="engine_id" value="' . (int)$engine['id'] . '"><button class="button">Delete</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '<p><a class="button" href="engines.php?mode=new">New</a></p></div>';

    if ($mode === 'new') {
        engine_form(null, 'new');
    } elseif ($mode === 'edit') {
        if (!$edit) {
            echo '<div class="card"><h2>Engine not found</h2><p class="muted">The selected engine could not be found.</p></div>';
        } else {
            engine_form($edit, 'edit');
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Engines error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
