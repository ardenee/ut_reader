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

function catalog_head(string $title): void
{
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . catalog_h($title) . '</title>';
    echo '<style>body{margin:0;background:#0b1020;color:#eef3ff;font:14px system-ui,Segoe UI,Arial}a{color:#8ab4ff;text-decoration:none}a:hover{text-decoration:underline}main{padding:16px}.card{background:#121a31;border:1px solid #2a375f;border-radius:14px;padding:14px;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #2a375f;padding:7px;text-align:left;vertical-align:top}th{background:#17213d}.muted{color:#9fb0d0}.mono{font-family:Consolas,monospace}.button,button{display:inline-block;background:#23325f;border:1px solid #3b5599;color:#eef3ff;padding:7px 10px;border-radius:8px;cursor:pointer}.dep{display:inline-block;font-size:12px;padding:2px 7px;border-radius:999px;border:1px solid #2a375f;background:#17213d;margin:2px}.resolved{border-color:#1d8f54}.missing{border-color:#a63b43}.common{border-color:#5c6688}.package_only{border-color:#b48a2a}.compressed{border-color:#b48a2a}.uncompressed{border-color:#1d8f54}.path{word-break:break-all}.small{font-size:12px}</style>';
    echo '</head><body><main>';
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
