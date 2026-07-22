<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogSearchService.php';
require_once __DIR__ . '/lib/CatalogPublicRateLimit.php';
require_once __DIR__ . '/lib/CatalogMfa.php';

use UnrealDb\Catalog\Infrastructure\Security\FileLoginRateLimiter;

catalog_start_session();

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function catalog_search_highlight(string $value, string $query): string
{
    $query = trim($query);
    if ($query === '') {
        return catalog_h($value);
    }
    $parts = preg_split('/(' . preg_quote($query, '/') . ')/iu', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return catalog_h($value);
    }
    $html = '';
    foreach ($parts as $index => $part) {
        if ($part === '') {
            continue;
        }
        $html .= $index % 2 === 1
            ? '<mark class="search-match-highlight">' . catalog_h($part) . '</mark>'
            : catalog_h($part);
    }
    return $html;
}

function catalog_search_game_id(array $games): int
{
    $requested = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);
    if ($requested === false || $requested === null || $requested < 1) {
        return 0;
    }
    foreach ($games as $game) {
        if ((int)$game['id'] === (int)$requested) {
            return (int)$requested;
        }
    }
    return 0;
}

function catalog_login_rate_limiter(array $config): FileLoginRateLimiter
{
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    return new FileLoginRateLimiter(
        rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'login',
        max(3, min((int)($auth['login_max_attempts'] ?? 8), 50)),
        max(60, min((int)($auth['login_window_seconds'] ?? 900), 86400)),
        max(60, min((int)($auth['login_block_seconds'] ?? 900), 86400))
    );
}

function catalog_render_search_results(array $rows, string $query): void
{
    echo '<div class="card"><h2>Results</h2>';
    if ($rows === []) {
        echo '<p class="muted">No matching files found.</p></div>';
        return;
    }
    echo '<table id="catalog-search-results" data-sortable-table><thead><tr><th>Game</th><th>Package</th><th>File</th><th>Matched Field</th><th>Tables (N/I/E)</th><th>Size</th><th>Identity</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $fileId = (int)$row['id'];
        $gameId = (int)$row['game_id'];
        $gameName = (string)($row['game_name'] ?? 'Unknown game');
        $packageName = (string)$row['package_name'];
        $originalName = (string)$row['original_name'];
        $matches = is_array($row['matched_fields'] ?? null) ? $row['matched_fields'] : [];
        $matched = '';
        foreach ($matches as $match) {
            $field = trim((string)($match['field'] ?? 'Match'));
            $value = trim((string)($match['value'] ?? ''));
            if ($value !== '') {
                $matched .= '<div class="search-match mono small"><strong>' . catalog_h($field) . ':</strong> ' . catalog_search_highlight($value, $query) . '</div>';
            }
        }
        echo '<tr>';
        echo '<td><a href="game-files.php?id=' . $gameId . '">' . catalog_search_highlight($gameName, $query) . '</a></td>';
        echo '<td class="mono"><a href="file-info.php?id=' . $fileId . '">' . catalog_search_highlight($packageName, $query) . '</a></td>';
        echo '<td><a href="file-examine.php?id=' . $fileId . '">' . catalog_search_highlight($originalName, $query) . '</a></td>';
        echo '<td>' . ($matched !== '' ? $matched : '<span class="muted">match details unavailable</span>') . '</td>';
        echo '<td class="mono">' . (int)($row['name_count'] ?? 0) . ' / ' . (int)($row['import_count'] ?? 0) . ' / ' . (int)($row['export_count'] ?? 0) . '</td>';
        echo '<td>' . catalog_h(catalog_bytes((int)($row['file_size'] ?? 0))) . '</td>';
        echo '<td class="catalog-identity-cell">' . CatalogUi::identity(
            (string)($row['package_guid'] ?? ''),
            (string)($row['md5'] ?? ''),
            (string)($row['sha1'] ?? '')
        ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $page = strtolower(trim((string)($_GET['page'] ?? 'home')));

    if ($page === 'game') redirect_to('game-files.php?id=' . (int)($_GET['id'] ?? 0));
    if ($page === 'file') redirect_to('file-info.php?id=' . (int)($_GET['id'] ?? 0));
    if ($page === 'examine') redirect_to('file-examine.php?id=' . (int)($_GET['id'] ?? 0));
    if ($page === 'upload') redirect_to('profiled-upload.php');
    if ($page === 'download') redirect_to('download.php?id=' . (int)($_GET['id'] ?? 0));
    if ($page === 'admin') redirect_to(catalog_support_is_admin() ? 'dashboard.php' : 'index.php?page=login');

    if ($page === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            throw new RuntimeException('Logout requires POST.');
        }
        catalog_check_csrf('logout');
        catalog_remember_clear($db);
        catalog_destroy_session();
        redirect_to('index.php');
    }

    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('login');
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $mfaCode = trim((string)($_POST['mfa_code'] ?? ''));
        $clientIp = catalog_client_ip();
        $rateLimiter = catalog_login_rate_limiter($config);
        if ($rateLimiter->retryAfterSeconds($username, $clientIp) > 0) {
            usleep(random_int(200000, 350000));
            throw new RuntimeException('Too many login attempts. Try again later.');
        }

        $user = $username === '' ? null : catalog_one($db, 'SELECT * FROM ue_users WHERE username=?', [$username]);
        $valid = is_array($user) && password_verify($password, (string)$user['password_hash']);
        if ($valid && catalog_mfa_enabled($user)) {
            $valid = $mfaCode !== '' && catalog_mfa_verify($db, $user, $mfaCode);
        }
        if (!$valid) {
            $retryAfter = $rateLimiter->recordFailure($username, $clientIp);
            error_log('[UnrealDB][' . catalog_request_id() . '] administrator login failed ip=' . ($clientIp !== '' ? $clientIp : 'unknown'));
            usleep(random_int(200000, 350000));
            throw new RuntimeException($retryAfter > 0 ? 'Too many login attempts. Try again later.' : 'Invalid username, password, or authenticator code.');
        }

        $rateLimiter->clear($username, $clientIp);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'role' => (string)$user['role']];
        catalog_mark_authenticated_session();
        catalog_mark_recent_admin_auth();
        if (!empty($_POST['remember_me']) && !catalog_mfa_enabled($user)) {
            catalog_remember_set_for_user($db, $user, $config);
        } else {
            catalog_remember_clear($db);
        }
        redirect_to('dashboard.php');
    }

    catalog_head((string)($config['site_name'] ?? 'UnrealDB'));
    if ($page === 'home') {
        $games = catalog_all($db, 'SELECT g.*,p.engine_key profile_engine,COUNT(f.id) file_count,COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified" GROUP BY g.id,p.id ORDER BY g.name');
        echo '<div class="card hero"><h1>Unreal Games</h1><p class="muted">Browse verified Unreal packages, dependencies, imports, exports and file identities.</p></div><div class="grid">';
        foreach ($games as $game) {
            echo '<a class="stat tool-card" href="game-files.php?id=' . (int)$game['id'] . '"><h2>' . catalog_h($game['name']) . '</h2><p>' . catalog_h($game['profile_engine'] ?? 'no active profile') . '</p><p>' . (int)$game['file_count'] . ' files / ' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</p></a>';
        }
        echo '</div>';
    } elseif ($page === 'search') {
        $query = trim((string)($_GET['q'] ?? ''));
        $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
        $gameId = catalog_search_game_id($games);
        echo '<div class="card hero"><h1>Search</h1><form class="catalog-search-form"><input type="hidden" name="page" value="search"><label>Search <input name="q" value="' . catalog_h($query) . '" placeholder="GUID, MD5, SHA1, package, object, file name"></label><label>Game <select name="game_id"><option value="">All games</option>';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '"' . ((int)$game['id'] === $gameId ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
        }
        echo '</select></label><button>Search</button></form><p class="muted small">Exact GUID, MD5 and SHA1 lookups use indexed identity searches. Broad searches require at least three characters and return at most 200 files.</p></div>';
        if ($query !== '') {
            if (mb_strlen($query, 'UTF-8') < 3 && preg_match('/^[A-Fa-f0-9]{32,40}$/', $query) !== 1) {
                echo '<div class="card"><p class="muted">Enter at least three characters.</p></div>';
            } else {
                catalog_public_search_rate_limit();
                try {
                    catalog_render_search_results(CatalogSearchService::findFiles($db, $query, 200, $gameId ?: null), $query);
                } catch (CatalogSearchUnavailableException) {
                    echo '<div class="card"><h2>Search temporarily unavailable</h2><p class="muted">Retry with a more specific term.</p></div>';
                }
            }
        }
    } elseif ($page === 'login') {
        if (catalog_support_is_admin()) {
            redirect_to('dashboard.php');
        }
        $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users')['c'] ?? 0);
        if ($count === 0) {
            echo '<div class="card hero"><h1>Administrator setup required</h1><p class="muted">Create the first administrator from a trusted shell:</p><pre>php catalog/bin/create-admin.php --username=admin</pre></div>';
        } else {
            echo '<div class="card hero"><h1>Admin Login</h1><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('login')) . '"><p><input name="username" required autocomplete="username" placeholder="Username"></p><p><input type="password" name="password" required autocomplete="current-password" placeholder="Password"></p><p><input name="mfa_code" autocomplete="one-time-code" inputmode="numeric" placeholder="Authenticator or recovery code, when enabled"></p><p><label><input type="checkbox" name="remember_me" value="1" checked> Keep me logged in when MFA is not enabled</label></p><button>Login</button></form></div>';
        }
    } else {
        throw new RuntimeException('Unknown page.');
    }
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) catalog_head('Catalog Error');
    echo '<div class="msg err"><strong>Request failed.</strong> ' . catalog_h(catalog_public_error_message()) . '</div>';
    echo '<div class="card"><h2>Request failed</h2><p class="muted">Check the request and try again. Administrators can inspect the server error log using the reference above.</p></div>';
    catalog_foot();
}
