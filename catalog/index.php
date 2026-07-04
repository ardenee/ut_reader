<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogSearchService.php';

catalog_start_session();

function redirect_to(string $url): void
{
    header('Location: ' . $url, true, 303);
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
    if ($page === 'examine') {
        redirect_to('file-examine.php?id=' . (int)($_GET['id'] ?? 0));
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
        $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            throw new RuntimeException('Stored file missing');
        }
        $downloadName = basename(str_replace(["\r", "\n", "\0"], '', (string)$file['original_name']));
        if ($downloadName === '') {
            $downloadName = 'download.bin';
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName), false);
        readfile($path);
        exit;
    }
    if ($page === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            throw new RuntimeException('Logout requires a POST request.');
        }
        catalog_check_csrf('logout');
        catalog_destroy_session();
        redirect_to('index.php');
    }
    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('login');
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        if ($count === 0) {
            throw new RuntimeException('No administrator exists. Create the first administrator with catalog/bin/create-admin.php.');
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $user = $username === '' ? null : catalog_one($db, 'SELECT * FROM ue_users WHERE username=?', [$username]);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            usleep(250000);
            throw new RuntimeException('Invalid username or password.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)$user['role'],
        ];
        redirect_to('dashboard.php');
    }

    catalog_head((string)($config['site_name'] ?? 'UnrealDB'));

    if ($page === 'home') {
        $games = catalog_all($db, 'SELECT g.*, p.engine_key profile_engine, COUNT(f.id) file_count, COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
        echo '<div class="card hero"><h1>Unreal Games</h1><p class="muted">Browse verified Unreal packages, dependencies, imports, exports and MD5 hashes.</p></div><div class="grid">';
        foreach ($games as $game) {
            echo '<a class="stat tool-card" href="game-files.php?id=' . (int)$game['id'] . '"><h2>' . catalog_h($game['name']) . '</h2><p>' . catalog_h($game['profile_engine'] ?? 'no active profile') . '</p><p>' . (int)$game['file_count'] . ' files / ' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</p></a>';
        }
        echo '</div>';
    } elseif ($page === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        echo '<div class="card hero"><h1>Search</h1><form><input type="hidden" name="page" value="search"><input name="q" value="' . catalog_h($q) . '" placeholder="MD5, SHA1, GUID, package, import/export object, file name" style="min-width:420px"> <button>Search</button></form><p class="muted small">Searches files, package names, imports and exports. Results are limited to 200 files.</p></div>';
        if ($q !== '') {
            if (strlen($q) < 2) {
                echo '<div class="card"><p class="muted">Enter at least two characters.</p></div>';
            } else {
                try {
                    $rows = CatalogSearchService::findFiles($db, $q, 200);
                } catch (CatalogSearchUnavailableException) {
                    echo '<div class="card"><h2>Search temporarily unavailable</h2><p class="muted">The catalog database did not complete this search. Please retry with a more specific term.</p></div>';
                    $rows = null;
                }

                if ($rows !== null) {
                    echo '<div class="card"><h2>Results</h2>';
                    if (!$rows) {
                        echo '<p class="muted">No matching files found.</p>';
                    } else {
                        echo '<table><tr><th>Package</th><th>File</th><th>MD5</th><th>Open</th></tr>';
                        foreach ($rows as $row) {
                            echo '<tr><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td><a href="file-info.php?id=' . (int)$row['id'] . '">details</a> | <a href="file-examine.php?id=' . (int)$row['id'] . '">examine</a></td></tr>';
                        }
                        echo '</table>';
                    }
                    echo '</div>';
                }
            }
        }
    } elseif ($page === 'login') {
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        if ($count === 0) {
            echo '<div class="card hero"><h1>Administrator setup required</h1><p class="muted">The first administrator can only be created through the CLI command:</p><pre>php catalog/bin/create-admin.php --username=admin</pre><p class="muted">Run it from a trusted shell before making the catalog publicly reachable.</p></div>';
        } else {
            echo '<div class="card hero"><h1>Admin Login</h1><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('login')) . '"><p><input name="username" required autocomplete="username" placeholder="Username"></p><p><input type="password" name="password" required autocomplete="current-password" placeholder="Password"></p><button>Login</button></form></div>';
        }
    } elseif ($page === 'admin') {
        if (!catalog_support_is_admin()) {
            redirect_to('index.php?page=login');
        }
        redirect_to('dashboard.php');
    } else {
        throw new RuntimeException('Unknown page');
    }

    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB][' . catalog_request_id() . '] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        catalog_head('Catalog Error');
    }
    echo '<div class="msg err"><strong>Request failed.</strong> ' . catalog_h(catalog_public_error_message()) . '</div>';
    echo '<div class="card"><h2>Request failed</h2><p class="muted">Check the request details and try again. Administrators can inspect the server error log using the reference above.</p></div>';
    catalog_foot();
}
