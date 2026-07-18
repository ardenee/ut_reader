<?php
declare(strict_types=1);

$base = require dirname(__DIR__, 2) . '/catalog/config.example.php';

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false || $value === '' ? $default : (string)$value;
};

$int = static function (string $name, int $default, int $min, int $max) use ($env): int {
    $raw = $env($name, (string)$default);
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        $value = $default;
    }
    return max($min, min((int)$value, $max));
};

$base['db'] = [
    'host' => $env('UNREALDB_DB_HOST', 'mysql'),
    'port' => $int('UNREALDB_DB_PORT', 3306, 1, 65535),
    'database' => $env('UNREALDB_DB_NAME', 'unrealdb'),
    'username' => $env('UNREALDB_DB_USER', 'unrealdb'),
    'password' => $env('UNREALDB_DB_PASSWORD'),
    'charset' => 'utf8mb4',
];
$base['site_name'] = $env('UNREALDB_SITE_NAME', 'Unreal File Catalog');
$base['storage_path'] = $env('UNREALDB_STORAGE_PATH', '/var/www/html/catalog/storage');
$base['max_upload_bytes'] = $int('UNREALDB_MAX_UPLOAD_BYTES', 256 * 1024 * 1024, 1, PHP_INT_MAX);
$base['auth']['remember_days'] = $int('UNREALDB_REMEMBER_DAYS', 30, 1, 365);
$base['auth']['login_max_attempts'] = $int('UNREALDB_LOGIN_MAX_ATTEMPTS', 8, 3, 50);
$base['auth']['login_window_seconds'] = $int('UNREALDB_LOGIN_WINDOW_SECONDS', 900, 60, 86400);
$base['auth']['login_block_seconds'] = $int('UNREALDB_LOGIN_BLOCK_SECONDS', 900, 60, 86400);
$base['queue']['name'] = $env('UNREALDB_QUEUE_NAME', 'catalog');
$base['queue']['lease_seconds'] = $int('UNREALDB_QUEUE_LEASE_SECONDS', 120, 30, 3600);

return $base;
