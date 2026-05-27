<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';

function pu_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function pu_csrf(): string
{
    $_SESSION['profiled_upload_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['profiled_upload_csrf'];
}

function pu_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['profiled_upload_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!pu_is_admin()) {
        catalog_head('Admin required');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        pu_check_csrf();
        $gameId = (int)($_POST['game_id'] ?? 0);
        $strict = ($_POST['strict_profile'] ?? '1') === '1';
        $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('Game not found');
        }

        $ok = 0;
        $dup = 0;
        $bad = 0;
        $messages = [];
        foreach ($_FILES['files']['tmp_name'] ?? [] as $i => $tmp) {
            $name = (string)($_FILES['files']['name'][$i] ?? 'upload.bin');
            $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $bad++;
                $messages[] = $name . ': upload error ' . $err;
                continue;
            }
            try {
                $result = scanner_scan_uploaded_file($db, $config, $gameId, $tmp, $name, $_SESSION['user']['id'] ?? null, $strict);
                if ($result[0] === 'duplicate') {
                    $dup++;
                } else {
                    $ok++;
                }
                $messages[] = $name . ': ' . $result[2];
            } catch (Throwable $e) {
                $bad++;
                scanner_store_failed_upload($config, $tmp, $name, (string)$game['slug'], $e->getMessage());
                $messages[] = $name . ': failed - ' . $e->getMessage();
            }
        }

        $_SESSION['profiled_upload_flash'] = 'Upload complete. Verified=' . $ok . ' Duplicate=' . $dup . ' Failed=' . $bad . '. ' . implode(' | ', array_slice($messages, 0, 12));
        header('Location: profiled-upload.php?game_id=' . $gameId);
        exit;
    }

    catalog_head('Profiled Upload Scanner');

    if (isset($_SESSION['profiled_upload_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['profiled_upload_flash']) . '</strong></div>';
        unset($_SESSION['profiled_upload_flash']);
    }

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    $games = catalog_all($db, 'SELECT g.*, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id ORDER BY g.name');

    echo '<div class="card hero"><h1>Profiled Upload Scanner</h1><p class="muted">Uploads are checked against the selected game profile before import. Mismatched files are moved to the unverified folder instead of being verified into the wrong game.</p>';
    catalog_page_links(['Setup' => 'setup.php', 'Game Profiles' => 'game-profiles.php', 'Library' => 'library.php']);
    echo '</div>';

    echo '<div class="card"><h2>Upload and scan</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . catalog_h(pu_csrf()) . '">';
    echo '<p><label>Target game<br><select name="game_id" required>';
    foreach ($games as $game) {
        $sel = ((int)$game['id'] === $selectedGameId) ? ' selected' : '';
        $label = $game['name'] . ' / ' . $game['engine_key'];
        if (!empty($game['profile_engine'])) {
            $label .= ' / profile ' . $game['profile_engine'];
        } else {
            $label .= ' / no active profile';
        }
        echo '<option value="' . (int)$game['id'] . '"' . $sel . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject/move mismatches to unverified</option><option value="0">Loose: allow scanner/parser to try anyway</option></select></label></p>';
    echo '<p><input type="file" name="files[]" multiple required> <button>Upload and profiled-scan</button></p>';
    echo '<p class="muted">Max per file: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '.</p></form></div>';

    echo '<div class="card"><h2>Configured game profiles</h2><table><tr><th>Game</th><th>Engine</th><th>Extensions</th><th>Version range</th><th>Open</th></tr>';
    foreach ($games as $game) {
        $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
        $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null) ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?')) : 'not fixed';
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td class="mono">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td><td class="mono">' . catalog_h($range) . '</td><td><a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">select</a></td></tr>';
    }
    echo '</table></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Profiled upload error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
