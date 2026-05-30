<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return (string)$_SESSION['csrf'];
}

function check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $page = (string)($_GET['page'] ?? 'home');

    if ($page === 'game') {
        redirect_to('game-files.php?id=' . (int)($_GET['id'] ?? 0));
    }

    if ($page === 'file') {
        redirect_to('file-info.php?id=' . (int)($_GET['id'] ?? 0));
    }

    if ($page === 'upload') {
        redirect_to('profiled-upload.php');
    }

    if ($page === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
        if (!$file) {
            throw new RuntimeException('File not found');
        }

        $path = realpath(__DIR__ . '/' . $file['relative_path']);
        $root = realpath(rtrim($config['storage_path'], DIRECTORY_SEPARATOR));
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            throw new RuntimeException('Stored file missing');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . addslashes((string)$file['original_name']) . '"');
        readfile($path);
        exit;
    }

    if ($page === 'logout') {
        session_destroy();
        redirect_to('index.php');
    }

    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        if ($count === 0) {
            $username = trim((string)$_POST['username']);
            $password = (string)$_POST['password'];
            if ($username === '' || strlen($password) < 8) {
                throw new RuntimeException('Username required and password must be at least 8 characters');
            }
            $stmt = $db->prepare('INSERT INTO ue_users(username,password_hash,role) VALUES(?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        }

        $user = catalog_one($db, 'SELECT * FROM ue_users WHERE username=?', [trim((string)$_POST['username'])]);
        if (!$user || !password_verify((string)$_POST['password'], (string)$user['password_hash'])) {
            throw new RuntimeException('Invalid login');
        }
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']];
        redirect_to('dashboard.php');
    }

    catalog_head($config['site_name'] ?? 'UnrealDB');

    if ($page === 'home') {
        $games = catalog_all($db, 'SELECT g.*, p.engine_key profile_engine, COUNT(f.id) file_count, COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id, p.engine_key ORDER BY g.name');
        echo '<div class="card hero"><h1>Unreal Games</h1><p class="muted">Browse verified Unreal packages, dependencies, imports, exports and MD5 hashes.</p></div><div class="grid">';
        foreach ($games as $game) {
            echo '<a class="stat tool-card" href="game-files.php?id=' . (int)$game['id'] . '"><h2>' . catalog_h($game['name']) . '</h2><p>' . catalog_h($game['profile_engine'] ?? 'no active profile') . '</p><p>' . (int)$game['file_count'] . ' files / ' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</p></a>';
        }
        echo '</div>';
    } elseif ($page === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        echo '<div class="card hero"><h1>Search</h1><form><input type="hidden" name="page" value="search"><input name="q" value="' . catalog_h($q) . '" placeholder="MD5, SHA1, GUID, package, import/export object, file name" style="min-width:420px"> <button>Search</button></form></div>';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $rows = catalog_all($db, 'SELECT DISTINCT f.* FROM ue_files f LEFT JOIN ue_imports i ON i.file_id=f.id LEFT JOIN ue_exports e ON e.file_id=f.id WHERE f.md5=? OR f.sha1=? OR f.package_guid LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ? OR i.full_path LIKE ? OR e.full_path LIKE ? ORDER BY f.package_name LIMIT 200', [$q, $q, $like, $like, $like, $like, $like]);
            echo '<div class="card"><h2>Results</h2><table><tr><th>Package</th><th>File</th><th>MD5</th><th>Open</th></tr>';
            foreach ($rows as $row) {
                echo '<tr><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td><a href="file-info.php?id=' . (int)$row['id'] . '">details</a></td></tr>';
            }
            echo '</table></div>';
        }
    } elseif ($page === 'login') {
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        echo '<div class="card hero"><h1>' . ($count ? 'Admin Login' : 'Create first admin user') . '</h1><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(csrf()) . '"><p><input name="username" required placeholder="Username"></p><p><input type="password" name="password" required placeholder="Password"></p><button>' . ($count ? 'Login' : 'Create admin') . '</button></form></div>';
    } elseif ($page === 'admin') {
        if (!catalog_support_is_admin()) {
            redirect_to('index.php?page=login');
        }
        redirect_to('dashboard.php');
    } elseif ($page === 'examine') {
        redirect_to('file-info.php?id=' . (int)($_GET['id'] ?? 0));
    } else {
        throw new RuntimeException('Unknown page');
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Catalog Error');
    }
    echo '<div class="msg err"><strong>Error:</strong> ' . catalog_h($e->getMessage()) . '</div>';
    echo '<div class="card"><h2>Setup checklist</h2><ol><li>Copy <code>catalog/config.example.php</code> to <code>catalog/config.php</code>.</li><li>Edit the database settings.</li><li>Import <code>catalog/install.sql</code>.</li><li>Make <code>catalog/storage/</code> writable by PHP.</li></ol></div>';
    catalog_foot();
}
