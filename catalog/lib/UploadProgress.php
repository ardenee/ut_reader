<?php
declare(strict_types=1);

function upload_progress_token(string $token): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '', $token) ?? '';
    return substr($safe, 0, 80);
}

function upload_progress_path(string $token): string
{
    $safe = upload_progress_token($token);
    if ($safe === '') {
        throw new RuntimeException('Missing upload progress token.');
    }
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'unrealdb-upload-progress-' . $safe . '.json';
}

function upload_progress_write(string $token, array $state): void
{
    $state['updated_at'] = microtime(true);
    $path = upload_progress_path($token);
    @file_put_contents($path . '.tmp', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @rename($path . '.tmp', $path);
}

function upload_progress_read(string $token): array
{
    $path = upload_progress_path($token);
    if (!is_file($path)) {
        return ['stage' => 'waiting', 'done' => 0, 'total' => 100, 'percent' => 0, 'message' => 'Waiting for server...'];
    }
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : ['stage' => 'unknown', 'done' => 0, 'total' => 100, 'percent' => 0, 'message' => 'Progress unavailable.'];
}

function upload_progress_clear(string $token): void
{
    $path = upload_progress_path($token);
    if (is_file($path)) {
        @unlink($path);
    }
}
