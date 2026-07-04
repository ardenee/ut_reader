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
    static $lastWriteAtByToken = [];
    static $lastStageByToken = [];
    static $lastPercentByToken = [];

    $safeToken = upload_progress_token($token);
    $now = hrtime(true);
    $stage = (string)($state['stage'] ?? '');
    $percent = max(0, min(100, (int)($state['percent'] ?? 0)));
    $terminal = $percent >= 100 || $stage === 'done' || $stage === 'failed';
    $lastWriteAt = (int)($lastWriteAtByToken[$safeToken] ?? 0);
    $changed = $stage !== ($lastStageByToken[$safeToken] ?? '') || $percent !== ($lastPercentByToken[$safeToken] ?? -1);

    if (!$terminal && (!$changed || ($lastWriteAt !== 0 && ($now - $lastWriteAt) < 200000000))) {
        return;
    }

    upload_progress_cleanup();
    $state['updated_at'] = microtime(true);
    $path = upload_progress_path($token);
    $tmpPath = $path . '.tmp';
    if (@file_put_contents($tmpPath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        return;
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return;
    }

    $lastWriteAtByToken[$safeToken] = $now;
    $lastStageByToken[$safeToken] = $stage;
    $lastPercentByToken[$safeToken] = $percent;
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

function upload_progress_cleanup(int $maxAgeSeconds = 86400): void
{
    static $checked = false;
    if ($checked || mt_rand(1, 100) !== 1) {
        return;
    }
    $checked = true;

    $cutoff = time() - max(60, $maxAgeSeconds);
    $pattern = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'unrealdb-upload-progress-*.json*';
    foreach (glob($pattern) ?: [] as $path) {
        $modifiedAt = @filemtime($path);
        if ($modifiedAt !== false && $modifiedAt < $cutoff) {
            @unlink($path);
        }
    }
}
