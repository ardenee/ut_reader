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
        $searchGames = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
        $searchGameId = catalog_search_game_id($searchGames);
        echo '<style>'
            . '.catalog-search-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }'
            . '.catalog-search-form label { display: flex; align-items: center; gap: 6px; }'
            . '.catalog-search-form input { min-width: 420px; }'
            . '@media (max-width: 700px) { .catalog-search-form input { min-width: min(100%, 420px); } }'
            . '</style>';
        echo '<div class="card hero"><h1>Search</h1><form class="catalog-search-form"><input type="hidden" name="page" value="search"><label for="catalog-search-query">Search <input id="catalog-search-query" name="q" value="' . catalog_h($q) . '" placeholder="MD5, SHA1, GUID, package, import/export object, file name"></label><label for="catalog-search-game">Game <select id="catalog-search-game" name="game_id"><option value="">All games</option>';
        foreach ($searchGames as $searchGame) {
            $gameId = (int)$searchGame['id'];
            echo '<option value="' . $gameId . '"' . ($searchGameId === $gameId ? ' selected' : '') . '>' . catalog_h($searchGame['name']) . '</option>';
        }
        echo '</select></label><button>Search</button></form><p class="muted small">Searches files, package names, imports and exports. Results are limited to 200 files.</p></div>';
        if ($q !== '') {
            if (strlen($q) < 2) {
                echo '<div class="card"><p class="muted">Enter at least two characters.</p></div>';
            } else {
                try {
                    $rows = CatalogSearchService::findFiles($db, $q, 200, $searchGameId ?: null);
                } catch (CatalogSearchUnavailableException) {
                    echo '<div class="card"><h2>Search temporarily unavailable</h2><p class="muted">The catalog database did not complete this search. Please retry with a more specific term.</p></div>';
                    $rows = null;
                }

                if ($rows !== null) {
                    echo '<style>'
                        . '.search-match { margin: 0 0 3px; overflow-wrap: anywhere; }'
                        . '.search-match:last-child { margin-bottom: 0; }'
                        . '.search-match strong { color: var(--muted); }'
                        . '.search-match-highlight { padding: 0 2px; border-radius: 3px; color: #1a1300; background: #f6c453; font-weight: 800; }'
                        . '#catalog-search-results th { cursor: pointer; user-select: none; }'
                        . '#catalog-search-results th:focus { outline: 2px solid var(--blue); outline-offset: -2px; }'
                        . '#catalog-search-results .search-guid-md5 { min-width: 310px; }'
                        . '#catalog-search-results .search-guid-md5 span { display: block; }'
                        . '#catalog-search-results .search-tables, #catalog-search-results .search-size { white-space: nowrap; }'
                        . '</style>';
                    echo '<div class="card"><h2>Results</h2>';
                    if (!$rows) {
                        echo '<p class="muted">No matching files found.</p>';
                    } else {
                        echo '<table id="catalog-search-results" data-sortable-table><thead><tr>';
                        echo '<th scope="col">Game</th><th scope="col">Package</th><th scope="col">File</th><th scope="col">Matched Field</th><th scope="col">Tables</th><th scope="col">Size</th><th scope="col">GUID / MD5</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($rows as $row) {
                            $fileId = (int)$row['id'];
                            $gameId = (int)$row['game_id'];
                            $gameName = (string)($row['game_name'] ?? 'Unknown game');
                            $packageName = (string)$row['package_name'];
                            $originalName = (string)$row['original_name'];
                            $guid = trim((string)($row['package_guid'] ?? ''));
                            $md5 = trim((string)($row['md5'] ?? ''));
                            $nameCount = (int)($row['name_count'] ?? 0);
                            $importCount = (int)($row['import_count'] ?? 0);
                            $exportCount = (int)($row['export_count'] ?? 0);
                            $fileSize = (int)($row['file_size'] ?? 0);
                            $matches = is_array($row['matched_fields'] ?? null) ? $row['matched_fields'] : [];
                            $matchedHtml = '';
                            $matchedSortValues = [];
                            foreach ($matches as $match) {
                                $field = trim((string)($match['field'] ?? 'Match'));
                                $value = trim((string)($match['value'] ?? ''));
                                if ($value === '') {
                                    continue;
                                }
                                $matchedSortValues[] = $field . ': ' . $value;
                                $matchedHtml .= '<div class="search-match mono small"><strong>' . catalog_h($field) . ':</strong> ' . catalog_search_highlight($value, $q) . '</div>';
                            }
                            if ($matchedHtml === '') {
                                $matchedHtml = '<span class="muted">match details unavailable</span>';
                            }
                            $matchedSort = implode(' | ', $matchedSortValues);
                            $tablesText = $nameCount . ' names / ' . $importCount . ' imports / ' . $exportCount . ' exports';
                            $tablesSort = str_pad((string)$nameCount, 10, '0', STR_PAD_LEFT) . '-' . str_pad((string)$importCount, 10, '0', STR_PAD_LEFT) . '-' . str_pad((string)$exportCount, 10, '0', STR_PAD_LEFT);
                            $identitySort = $guid . ' ' . $md5;

                            echo '<tr>';
                            echo '<td data-sort-value="' . catalog_h($gameName) . '"><a href="game-files.php?id=' . $gameId . '" title="Open game files">' . catalog_search_highlight($gameName, $q) . '</a></td>';
                            echo '<td class="mono" data-sort-value="' . catalog_h($packageName) . '"><a href="file-info.php?id=' . $fileId . '" title="View package details">' . catalog_search_highlight($packageName, $q) . '</a></td>';
                            echo '<td data-sort-value="' . catalog_h($originalName) . '"><a href="file-examine.php?id=' . $fileId . '" title="Examine file">' . catalog_search_highlight($originalName, $q) . '</a></td>';
                            echo '<td data-sort-value="' . catalog_h($matchedSort) . '">' . $matchedHtml . '</td>';
                            echo '<td class="mono small search-tables" data-sort-value="' . catalog_h($tablesSort) . '">' . catalog_h($tablesText) . '</td>';
                            echo '<td class="search-size" data-sort-value="' . $fileSize . '">' . catalog_h(catalog_bytes($fileSize)) . '</td>';
                            echo '<td class="mono small search-guid-md5" data-sort-value="' . catalog_h($identitySort) . '"><span>GUID: ' . ($guid !== '' ? catalog_search_highlight($guid, $q) : '<span class="muted">—</span>') . '</span><span>MD5: ' . ($md5 !== '' ? catalog_search_highlight($md5, $q) : '<span class="muted">—</span>') . '</span></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo <<<'JS'
<script>
(function () {
    'use strict';
    var table = document.getElementById('catalog-search-results');
    if (!table || !table.tHead || !table.tBodies.length) return;

    var headerRow = table.tHead.rows[0];
    var body = table.tBodies[0];
    var activeIndex = -1;
    var ascending = true;

    Array.from(headerRow.cells).forEach(function (header, index) {
        header.tabIndex = 0;
        header.setAttribute('role', 'button');
        header.setAttribute('title', 'Click to sort ascending. Click again to sort descending.');

        function sortByColumn() {
            if (activeIndex === index) {
                ascending = !ascending;
            } else {
                activeIndex = index;
                ascending = true;
            }

            Array.from(body.rows).sort(function (leftRow, rightRow) {
                var leftCell = leftRow.cells[index];
                var rightCell = rightRow.cells[index];
                var left = (leftCell.dataset.sortValue || leftCell.textContent || '').trim();
                var right = (rightCell.dataset.sortValue || rightCell.textContent || '').trim();
                var comparison = left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
                return ascending ? comparison : -comparison;
            }).forEach(function (row) {
                body.appendChild(row);
            });

            Array.from(headerRow.cells).forEach(function (otherHeader, otherIndex) {
                otherHeader.classList.remove('is-sort-ascending', 'is-sort-descending');
                otherHeader.removeAttribute('aria-sort');
                if (otherIndex === index) {
                    otherHeader.classList.add(ascending ? 'is-sort-ascending' : 'is-sort-descending');
                    otherHeader.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                }
            });
        }

        header.addEventListener('click', sortByColumn);
        header.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                sortByColumn();
            }
        });
    });
})();
</script>
JS;
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
