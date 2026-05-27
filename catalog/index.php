<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function legacy_u(array $p = []): string
{
    return 'index.php' . ($p ? '?' . http_build_query($p) : '');
}

function legacy_csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function legacy_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function legacy_flash(string $url, string $message): void
{
    $_SESSION['flash'] = $message;
    header('Location: ' . $url);
    exit;
}

function legacy_show_setup_missing(): void
{
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup Required</title></head><body>';
    echo '<h1>Setup required</h1><p>Copy <code>catalog/config.example.php</code> to <code>catalog/config.php</code>, edit DB settings, import <code>catalog/install.sql</code>, and make <code>catalog/storage/</code> writable by PHP.</p>';
    echo '</body></html>';
    exit;
}

try {
    if (!is_file(__DIR__ . '/config.php')) {
        legacy_show_setup_missing();
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $page = (string)($_GET['page'] ?? 'home');

    if ($page === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if ($page === 'admin') {
        header('Location: admin.php');
        exit;
    }

    if ($page === 'home') {
        header('Location: games.php');
        exit;
    }

    if ($page === 'game') {
        header('Location: game-files.php?id=' . (int)($_GET['id'] ?? 0));
        exit;
    }

    if ($page === 'file' || $page === 'examine') {
        header('Location: file-info.php?id=' . (int)($_GET['id'] ?? 0));
        exit;
    }

    if ($page === 'download') {
        header('Location: download.php?id=' . (int)($_GET['id'] ?? 0));
        exit;
    }

    if ($page === 'upload') {
        header('Location: profiled-upload.php');
        exit;
    }

    if ($page === 'save_game') {
        header('Location: game-manager.php');
        exit;
    }

    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        legacy_check_csrf();
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($count === 0) {
            if ($username === '' || strlen($password) < 8) {
                throw new RuntimeException('Username required and password must be at least 8 characters.');
            }
            $stmt = $db->prepare('INSERT INTO ue_users(username,password_hash,role) VALUES(?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        }

        $user = catalog_one($db, 'SELECT * FROM ue_users WHERE username=?', [$username]);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            throw new RuntimeException('Invalid login');
        }

        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'role' => (string)$user['role']];
        legacy_flash('admin.php', 'Logged in');
    }

    if ($page === 'login') {
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        catalog_head($count ? 'Admin Login' : 'Create first admin user');
        if (isset($_SESSION['flash'])) {
            echo '<div class="card"><strong>' . catalog_h($_SESSION['flash']) . '</strong></div>';
            unset($_SESSION['flash']);
        }
        echo '<div class="card"><h1>' . ($count ? 'Admin Login' : 'Create first admin user') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(legacy_csrf()) . '"><p><input name="username" required placeholder="Username"></p><p><input type="password" name="password" required placeholder="Password"></p><button>' . ($count ? 'Login' : 'Create admin') . '</button></form></div>';
        catalog_foot();
        exit;
    }

    if ($page === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        catalog_head('Search');
        echo '<div class="card"><h1>Search</h1><form><input type="hidden" name="page" value="search"><input name="q" value="' . catalog_h($q) . '" placeholder="MD5, SHA1, GUID, package, import/export object, file name" style="min-width:420px"> <button>Search</button></form></div>';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $rows = catalog_all($db, 'SELECT DISTINCT f.* FROM ue_files f LEFT JOIN ue_imports i ON i.file_id=f.id LEFT JOIN ue_exports e ON e.file_id=f.id WHERE f.md5=? OR f.sha1=? OR f.package_guid LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ? OR i.full_path LIKE ? OR e.full_path LIKE ? ORDER BY f.package_name LIMIT 200', [$q, $q, $like, $like, $like, $like, $like]);
            echo '<div class="card"><h2>Results</h2><table><tr><th>Package</th><th>File</th><th>MD5</th><th>Open</th></tr>';
            foreach ($rows as $row) {
                echo '<tr><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td><a class="button" href="file-info.php?id=' . (int)$row['id'] . '">details</a></td></tr>';
            }
            echo '</table></div>';
        }
        catalog_foot();
        exit;
    }

    throw new RuntimeException('Unknown page.');
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Catalog Error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
