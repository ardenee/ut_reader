<?php
declare(strict_types=1);

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

function catalog_support_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function catalog_support_root_prefix(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($script, '/catalog/federation/') ? '../' : '';
}

function catalog_admin_nav(): void
{
    $root = catalog_support_root_prefix();
    echo '<header><strong><a href="' . catalog_h($root . 'dashboard.php') . '">Unreal File Catalog</a></strong><nav>';
    if (catalog_support_is_admin()) {
        echo '<a class="primary" href="' . catalog_h($root . 'dashboard.php') . '">Dashboard</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'library.php') . '">Library</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'setup.php') . '">Setup</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'missing.php') . '">Missing Files</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'federation/admin.php') . '">Federation</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'transfers.php') . '">Transfers</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'download-admin.php') . '">Downloads</a>';
        echo '<a class="secondary" href="' . catalog_h($root . 'federation/settings.php') . '">Settings</a>';
        echo '<a class="secondary" href="' . catalog_h($root . 'federation/docs.php') . '">Docs</a>';
        echo '<a class="secondary" href="' . catalog_h($root . 'index.php?page=logout') . '">Logout ' . catalog_h($_SESSION['user']['username'] ?? '') . '</a>';
    } else {
        echo '<a class="primary" href="' . catalog_h($root . 'games.php') . '">Games</a>';
        echo '<a class="primary" href="' . catalog_h($root . 'index.php?page=search') . '">Search</a>';
        echo '<a class="secondary" href="' . catalog_h($root . 'index.php?page=login') . '">Admin Login</a>';
    }
    echo '</nav></header>';
}

function catalog_tool_card(string $title, string $href, string $description, string $badge = ''): void
{
    $badgeHtml = $badge !== '' ? '<span class="dep package_only">' . catalog_h($badge) . '</span>' : '';
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
    echo '<p class="page-links">';
    foreach ($links as $label => $href) {
        echo '<a class="button" href="' . catalog_h($href) . '">' . catalog_h($label) . '</a> ';
    }
    echo '</p>';
}

function catalog_head(string $title): void
{
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . catalog_h($title) . '</title>';
    echo '<style>body{margin:0;background:#0b1020;color:#eef3ff;font:14px system-ui,Segoe UI,Arial}a{color:#8ab4ff;text-decoration:none}a:hover{text-decoration:underline}header{background:#090d19;border-bottom:1px solid #2a375f;padding:14px 18px;display:flex;gap:16px;flex-wrap:wrap;align-items:center}header strong a{color:#eef3ff}nav a{background:#17213d;padding:7px 10px;border-radius:8px;margin-right:6px;display:inline-block;margin-bottom:4px}.primary{border:1px solid #3b5599}.secondary{opacity:.82}main{padding:16px}.card{background:#121a31;border:1px solid #2a375f;border-radius:14px;padding:14px;margin-bottom:14px}.hero{border-color:#3b5599;background:#141f3b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.stat{display:block;background:#17213d;border:1px solid #2a375f;border-radius:12px;padding:12px}.tool-card h2{font-size:18px}.tool-card p{min-height:38px}.warning{border-color:#a63b43}.good{border-color:#1d8f54}.attention{border-color:#b48a2a}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #2a375f;padding:7px;text-align:left;vertical-align:top}th{background:#17213d}.muted{color:#9fb0d0}.mono{font-family:Consolas,monospace}.button,button{display:inline-block;background:#23325f;border:1px solid #3b5599;color:#eef3ff;padding:7px 10px;border-radius:8px;cursor:pointer;margin-bottom:4px}.page-links .button{margin-right:6px}.dep{display:inline-block;font-size:12px;padding:2px 7px;border-radius:999px;border:1px solid #2a375f;background:#17213d;margin:2px}.resolved{border-color:#1d8f54}.missing{border-color:#a63b43}.common{border-color:#5c6688}.package_only{border-color:#b48a2a}.compressed{border-color:#b48a2a}.uncompressed{border-color:#1d8f54}.path{word-break:break-all}.small{font-size:12px}.two-col{display:grid;grid-template-columns:2fr 1fr;gap:12px}@media(max-width:850px){.two-col{grid-template-columns:1fr}}</style>';
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
    $file = __DIR__ . '/../config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Missing catalog/config.php');
    }
    return require $file;
}
