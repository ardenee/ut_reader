<?php
declare(strict_types=1);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'data' => [
        'status' => 'ok',
        'service' => 'unrealdb-catalog',
        'process' => 'live',
        'time' => gmdate('c'),
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
