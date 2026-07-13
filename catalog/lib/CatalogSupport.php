<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSecurity.php';
require_once __DIR__ . '/CatalogRememberMe.php';
require_once __DIR__ . '/CatalogUi.php';

catalog_apply_runtime_safeguards();

function catalog_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalog_db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Missing PHP extension: pdo_mysql');
    }

    $d = $config['db'] ?? [];
    $dsn = 'mysql:host=' . $d['host'] . ';port=' . (int)$d['port'] . ';dbname=' . $d['database'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $d['username'], $d['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function catalog_one(PDO $db, string $sql, array $args = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    return $row ?: null;
}

function catalog_all(PDO $db, string $sql, array $args = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function catalog_count(PDO $db, string $sql, array $args = []): int
{
    return (int)(catalog_one($db, $sql, $args)['c'] ?? 0);
}

function catalog_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return ($i ? number_format($value, 2) : (string)$bytes) . ' ' . $units[$i];
}

function catalog_clean_unreal_package_stem(string $stem): string
{
    $stem = trim(str_replace(["\0", '/', '\\'], ['', '.', '.'], $stem));
    $stem = preg_replace('/\s+/', ' ', $stem) ?? $stem;
    $stem = trim($stem, " \t\n\r\0\x0B.");

    do {
        $previous = $stem;
        $stem = preg_replace('/\s+\([0-9]+\)$/', '', $stem) ?? $stem;
        $stem = preg_replace('/_(?:[2-9]|[1-9][0-9]+)$/', '', $stem) ?? $stem;
        $stem = preg_replace('/\s+-\s+copy(?:\s*\([0-9]+\))?$/i', '', $stem) ?? $stem;
        $stem = preg_replace('/\s+copy(?:\s*\([0-9]+\))?$/i', '', $stem) ?? $stem;
        $stem = trim($stem, " \t\n\r\0\x0B.");
    } while ($stem !== $previous);

    $stem = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $stem) ?? $stem;
    $stem = trim($stem, " \t\n\r\0\x0B.");

    return $stem !== '' ? $stem : 'package';
}

function catalog_clean_unreal_extension(string $extension): string
{
    $extension = strtolower(trim($extension));
    $extension = preg_replace('/[^A-Za-z0-9_]+/', '', $extension) ?? '';

    if (!str_contains($extension, '_') && preg_match('/^([a-z]{3})(uax|umx|utx|usx|ukx|upx|ugx)$/', $extension, $match)) {
        return $match[1] . '_' . $match[2];
    }

    return $extension;
}

function catalog_clean_unreal_filename(string $filename): string
{
    $filename = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $filename));
    $filename = preg_replace('/\s+/', ' ', $filename) ?? $filename;
    $filename = trim($filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return 'package';
    }

    $extension = catalog_clean_unreal_extension((string)pathinfo($filename, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($filename, PATHINFO_FILENAME);
    $stem = catalog_clean_unreal_package_stem($stem);

    return $stem . ($extension !== '' ? '.' . $extension : '');
}

function catalog_support_is_admin(): bool
{
    catalog_start_session();
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    if (!catalog_remember_cookie_present()) {
        return false;
    }

    try {
        $config = catalog_config();
        $db = catalog_db($config);
        catalog_remember_restore($db, $config);
    } catch (Throwable $error) {
        error_log('[UnrealDB][' . catalog_request_id() . '] remember login restore failed: ' . $error->getMessage());
    }

    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function catalog_csrf_key(string $key): string
{
    $safe = preg_replace('/[^A-Za-z0-9_]+/', '_', $key) ?? 'default';
    $safe = trim($safe, '_');
    return 'catalog_csrf_' . ($safe !== '' ? $safe : 'default');
}

function catalog_csrf(string $key): string
{
    catalog_start_session();
    $sessionKey = catalog_csrf_key($key);
    $_SESSION[$sessionKey] ??= bin2hex(random_bytes(32));
    return (string)$_SESSION[$sessionKey];
}

function catalog_check_csrf(string $key): void
{
    catalog_start_session();
    $sessionKey = catalog_csrf_key($key);
    $actual = (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION[$sessionKey] ?? '');
    if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function catalog_support_root_prefix(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/catalog/federation/')) {
        return '../';
    }
    if (str_contains($script, '/catalog/')) {
        return '';
    }
    return 'catalog/';
}

function catalog_nav_link(string $label, string $href, string $class = ''): void
{
    echo '<a' . ($class !== '' ? ' class="' . catalog_h($class) . '"' : '') . ' href="' . catalog_h($href) . '">' . catalog_h($label) . '</a>';
}

function catalog_nav_menu(string $label, array $links): void
{
    echo '<details><summary>' . catalog_h($label) . '</summary><div class="nav-menu">';
    foreach ($links as $text => $href) {
        catalog_nav_link((string)$text, (string)$href);
    }
    echo '</div></details>';
}

function catalog_brand_mark(string $root): string
{
    return '<span class="brand-mark"><img src="' . catalog_h($root . 'assets/unreal-file-catalog-icon-32x32.png') . '" alt="" width="32" height="32"></span>';
}

function catalog_admin_nav(): void
{
    $root = catalog_support_root_prefix();
    $brandHref = catalog_support_is_admin() ? $root . 'dashboard.php' : $root . 'index.php';
    echo '<header class="site-header"><div class="brand"><a href="' . catalog_h($brandHref) . '">' . catalog_brand_mark($root) . '<span><strong>UnrealDB</strong><small>package catalog</small></span></a></div><nav class="primary-nav">';
    catalog_nav_link('Games', $root . 'games.php');
    catalog_nav_link('Search', $root . 'index.php?page=search');

    if (catalog_support_is_admin()) {
        echo '<span class="nav-sep"></span>';
        catalog_nav_menu('Admin', [
            'Dashboard' => $root . 'dashboard.php',
            'Library' => $root . 'library.php',
            'Game Admin' => $root . 'game-manager.php',
            'Game Profiles' => $root . 'game-profiles.php',
            'Full Sync' => $root . 'full-sync.php',
            'Package Normalizer' => $root . 'package-normalize.php',
        ]);
        catalog_nav_menu('Sources', [
            'Game Sources' => $root . 'sources.php',
            'Local Source Scan' => $root . 'source-scan.php',
            'HTTP Source Scan' => $root . 'http-source-scan.php',
            'Upload Files' => $root . 'profiled-upload.php',
            'Upload Bucket' => $root . 'upload-bucket.php',
            'Unverified Files' => $root . 'unverified-files.php',
            'Storage Audit' => $root . 'storage-audit.php',
        ]);
        catalog_nav_menu('Federation', [
            'Federation Admin' => $root . 'federation/admin.php',
            'Transfers' => $root . 'transfers.php',
            'Downloads' => $root . 'download-admin.php',
            'Settings' => $root . 'federation/settings.php',
        ]);
        echo '<form method="post" action="' . catalog_h($root . 'index.php?page=logout') . '" class="nav-logout">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('logout')) . '">';
        echo '<button type="submit" class="logout">Logout</button></form>';
    } else {
        echo '<span class="nav-sep"></span>';
        catalog_nav_link('Admin Login', $root . 'index.php?page=login');
    }

    echo '</nav></header>';
}

function catalog_tool_card(string $title, string $href, string $description, string $badge = ''): void
{
    $badgeHtml = $badge !== '' ? '<span class="pill amber">' . catalog_h($badge) . '</span>' : '';
    echo '<a class="stat tool-card" href="' . catalog_h($href) . '"><h2>' . catalog_h($title) . ' ' . $badgeHtml . '</h2><p>' . catalog_h($description) . '</p></a>';
}

function catalog_stat_card(string $title, string|int $value, string $description = '', string $class = ''): void
{
    echo '<div class="stat ' . catalog_h($class) . '"><h2>' . catalog_h($value) . '</h2><p>' . catalog_h($title) . '</p>';
    if ($description !== '') {
        echo '<p class="muted small">' . catalog_h($description) . '</p>';
    }
    echo '</div>';
}

function catalog_page_links(array $links): void
{
    if (!$links) {
        return;
    }
    echo '<p class="page-links">';
    foreach ($links as $label => $href) {
        echo '<a class="button" href="' . catalog_h($href) . '">' . catalog_h($label) . '</a> ';
    }
    echo '</p>';
}

function catalog_federation_links(): array
{
    return [
        'Federation Admin' => 'admin.php',
        'Settings' => 'settings.php',
        'Peers' => 'peers.php',
        'Queue' => 'queue.php',
        'Bulk Worker' => 'worker-run.php',
        'Conflicts' => 'conflicts.php',
        'Maintenance' => 'maintenance.php',
        'Logs' => 'logs.php'
    ];
}

function catalog_page_header(string $title, string $description = '', array $links = []): void
{
    echo CatalogUi::pageHeader($title, $description, $links);
}

function catalog_flash(?string $message): void
{
    if ($message === null || $message === '') {
        return;
    }
    echo CatalogUi::alert('info', $message, '', ['dismissible' => true]);
}

function catalog_require_admin_page(string $title = 'Admin required'): bool
{
    if (catalog_support_is_admin()) {
        return true;
    }
    catalog_head($title);
    echo CatalogUi::emptyState(
        'Admin required',
        'Log in with an administrator account to access this page.',
        ['label' => 'Admin Login', 'href' => catalog_support_root_prefix() . 'index.php?page=login']
    );
    catalog_foot();
    return false;
}

function catalog_head(string $title): void
{
    catalog_support_is_admin();
    $root = catalog_support_root_prefix();
    $uiScriptPath = __DIR__ . '/../assets/catalog-ui.js';
    $uiScriptVersion = is_file($uiScriptPath) ? (string)filemtime($uiScriptPath) : '1';
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . catalog_h($title) . '</title>';
    echo '<link rel="icon" href="' . catalog_h($root . 'assets/favicon.ico') . '">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . catalog_h($root . 'assets/unreal-file-catalog-icon-180x180.png') . '">';
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . catalog_h($root . 'assets/unreal-file-catalog-icon-32x32.png') . '">';
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . catalog_h($root . 'assets/unreal-file-catalog-icon-16x16.png') . '">';
    echo '<link rel="stylesheet" href="' . catalog_h($root . 'assets/catalog.css') . '">';
    echo '<link rel="stylesheet" href="' . catalog_h($root . 'assets/catalog-ui.css') . '">';
    echo '<script src="' . catalog_h($root . 'assets/catalog-ui.js?v=' . $uiScriptVersion) . '" defer></script>';
    if (in_array($scriptName, ['game-files.php', 'full-sync.php'], true)) {
        $popupLayoutPath = __DIR__ . '/../assets/catalog-maintenance-popup-layout.js';
        $popupLayoutVersion = is_file($popupLayoutPath) ? (string)filemtime($popupLayoutPath) : '1';
        echo '<script src="' . catalog_h($root . 'assets/catalog-maintenance-popup-layout.js?v=' . $popupLayoutVersion) . '" defer></script>';
    }
    if ($scriptName === 'game-files.php' && catalog_support_is_admin()) {
        $maintenanceScriptPath = __DIR__ . '/../assets/game-file-maintenance.js.php';
        $maintenanceScriptVersion = is_file($maintenanceScriptPath) ? (string)filemtime($maintenanceScriptPath) : '1';
        echo '<script src="' . catalog_h($root . 'assets/game-file-maintenance.js.php?v=' . $maintenanceScriptVersion) . '" defer></script>';
    }
    echo '</head><body>';
    catalog_admin_nav();
    echo '<main>';
}

function catalog_foot(): void
{
    echo '</main></body></html>';
}

function catalog_config(): array
{
    $configuredPath = trim((string)(getenv('UNREALDB_CATALOG_CONFIG') ?: ''));
    $file = $configuredPath !== '' ? $configuredPath : __DIR__ . '/../config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Catalog configuration file is missing.');
    }

    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('Catalog configuration must return an array.');
    }

    return $config;
}
