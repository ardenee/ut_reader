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
    echo '<header><div class="brand"><a href="' . catalog_h($root . 'dashboard.php') . '"><span class="brand-mark">U</span><span><strong>UnrealDB</strong><small>package catalog</small></span></a></div><nav>';
    echo '<a href="' . catalog_h($root . 'games.php') . '">Games</a>';
    echo '<a href="' . catalog_h($root . 'index.php?page=search') . '">Search</a>';
    if (catalog_support_is_admin()) {
        echo '<span class="nav-sep"></span>';
        echo '<a href="' . catalog_h($root . 'dashboard.php') . '">Dashboard</a>';
        echo '<a href="' . catalog_h($root . 'library.php') . '">Library</a>';
        echo '<a href="' . catalog_h($root . 'game-manager.php') . '">Game Admin</a>';
        echo '<a href="' . catalog_h($root . 'federation/admin.php') . '">Federation</a>';
        echo '<a href="' . catalog_h($root . 'transfers.php') . '">Transfers</a>';
        echo '<a href="' . catalog_h($root . 'download-admin.php') . '">Downloads</a>';
        echo '<a href="' . catalog_h($root . 'source-scan.php') . '">Uploads</a>';
        echo '<a href="' . catalog_h($root . 'federation/settings.php') . '">Settings</a>';
        echo '<a class="logout" href="' . catalog_h($root . 'index.php?page=logout') . '">Logout</a>';
    } else {
        echo '<span class="nav-sep"></span><a href="' . catalog_h($root . 'index.php?page=login') . '">Admin Login</a>';
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
    echo '<p class="page-links">';
    foreach ($links as $label => $href) {
        echo '<a class="button" href="' . catalog_h($href) . '">' . catalog_h($label) . '</a> ';
    }
    echo '</p>';
}

function catalog_head(string $title): void
{
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . catalog_h($title) . '</title>';
    echo '<style>:root{--bg:#080d19;--panel:#101827;--panel2:#121d31;--line:#263651;--line2:#38517a;--text:#eef5ff;--muted:#9fb0c8;--blue:#76a9ff;--green:#32d583;--red:#ff6b7a;--amber:#f6c453;--shadow:0 18px 60px rgba(0,0,0,.28)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,#17284a 0,#080d19 380px),var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Arial,sans-serif}a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}header{position:sticky;top:0;z-index:10;background:rgba(8,13,25,.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--line);padding:12px 18px;display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap}.brand a{display:flex;gap:10px;align-items:center;color:var(--text)}.brand-mark{width:34px;height:34px;border-radius:12px;display:inline-grid;place-items:center;background:linear-gradient(135deg,#4169e1,#8b5cf6);font-weight:800;box-shadow:var(--shadow)}.brand small{display:block;color:var(--muted);font-size:11px;margin-top:-2px}nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}nav a{color:#dbeafe;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.07);padding:7px 10px;border-radius:999px}nav a:hover{background:rgba(118,169,255,.15);text-decoration:none}.nav-sep{width:1px;height:24px;background:var(--line);margin:0 4px}.logout{opacity:.8}main{width:min(1440px,100%);margin:0 auto;padding:20px}.card{background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.025));border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}.hero{border-color:var(--line2);background:linear-gradient(135deg,rgba(65,105,225,.20),rgba(139,92,246,.10))}.hero h1,.card h1{margin:0 0 6px;font-size:28px}.card h2{margin:0 0 10px;font-size:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}.stat{display:block;background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:16px;padding:14px}.stat h2{font-size:22px;margin:0 0 4px}.stat p{margin:0;color:var(--muted)}.tool-card{transition:transform .12s ease,border-color .12s ease,background .12s ease}.tool-card:hover{transform:translateY(-1px);text-decoration:none;border-color:var(--blue);background:rgba(118,169,255,.10)}.warning{border-color:rgba(255,107,122,.65)}.good{border-color:rgba(50,213,131,.65)}.attention{border-color:rgba(246,196,83,.7)}table{width:100%;border-collapse:separate;border-spacing:0}th,td{border-bottom:1px solid var(--line);padding:9px;text-align:left;vertical-align:top}th{color:#cfe1ff;background:rgba(255,255,255,.04);font-weight:650}.muted{color:var(--muted)}.mono{font-family:Consolas,ui-monospace,monospace}.button,button{display:inline-block;background:linear-gradient(180deg,#2c4270,#23365e);border:1px solid var(--line2);color:var(--text);padding:8px 11px;border-radius:10px;cursor:pointer;margin:0 4px 6px 0}.button:hover,button:hover{text-decoration:none;filter:brightness(1.1)}input,select,textarea{background:#0b1220;color:var(--text);border:1px solid var(--line2);border-radius:10px;padding:8px;max-width:100%}.page-links{margin:12px 0 0}.pill,.dep{display:inline-block;font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.05);margin:2px}.amber,.package_only{border-color:rgba(246,196,83,.75);color:#ffe29a}.resolved,.good-pill{border-color:rgba(50,213,131,.75);color:#a7f3d0}.missing,.bad-pill{border-color:rgba(255,107,122,.75);color:#fecdd3}.common{border-color:#5c6688}.compressed{border-color:rgba(246,196,83,.75)}.uncompressed{border-color:rgba(50,213,131,.75)}.path{word-break:break-all}.small{font-size:12px}.two-col{display:grid;grid-template-columns:2fr 1fr;gap:14px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.subtle{font-size:13px;color:var(--muted)}@media(max-width:850px){.two-col{grid-template-columns:1fr}header{align-items:flex-start}nav{width:100%}}</style>';
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
