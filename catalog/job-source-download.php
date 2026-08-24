<?php
/**
 * Streams the retained physical source behind a Background Jobs file tree row.
 *
 * This is an administrator diagnostic download only. It never generates,
 * repackages or transforms content. Child workflow rows resolve back through
 * their ancestry so the original retained source/container is preferred.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobSourceContextResolver;

/** @return array{start:int,end:int,length:int,partial:bool} */
function catalog_job_source_download_range(string $header, int $size): array
{
    if ($size < 1 || trim($header) === '') {
        return ['start' => 0, 'end' => max(0, $size - 1), 'length' => $size, 'partial' => false];
    }

    if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $matches) !== 1) {
        throw new RuntimeException('Only one valid byte range may be requested.');
    }

    $first = (string)$matches[1];
    $last = (string)$matches[2];
    if ($first === '' && $last === '') {
        throw new RuntimeException('The requested byte range is empty.');
    }

    if ($first === '') {
        $suffix = (int)$last;
        if ($suffix < 1) {
            throw new RuntimeException('The requested suffix range is invalid.');
        }
        $length = min($size, $suffix);
        $start = $size - $length;
        $end = $size - 1;
    } else {
        $start = (int)$first;
        $end = $last === '' ? $size - 1 : min($size - 1, (int)$last);
        if ($start < 0 || $start >= $size || $end < $start) {
            throw new RuntimeException('The requested byte range is outside the retained source file.');
        }
        $length = $end - $start + 1;
    }

    return ['start' => $start, 'end' => $end, 'length' => $length, 'partial' => true];
}

function catalog_job_source_download_name(string $name, string $path): string
{
    $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = rtrim(trim($name), ' .');
    if ($name === '' || $name === '.' || $name === '..') {
        $name = basename($path);
    }
    $name = rtrim(trim($name), ' .');
    return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'source.bin';
}

function catalog_job_source_prepare_binary_output(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Encoding');
    }
}

/** @return list<array{id:int,parent_job_id:int,payload:array<string,mixed>}> */
function catalog_job_source_chain(PDO $db, int $jobId): array
{
    $chain = [];
    $seen = [];
    $current = $jobId;

    for ($depth = 0; $depth < 64 && $current > 0; $depth++) {
        if (isset($seen[$current])) {
            throw new RuntimeException('Background job ancestry contains a cycle.');
        }
        $seen[$current] = true;

        $statement = $db->prepare(
            'SELECT id,parent_job_id,payload_json FROM ue_background_jobs WHERE id=? LIMIT 1'
        );
        $statement->execute([$current]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            if ($chain === []) {
                throw new RuntimeException('Background job #' . $jobId . ' was not found.');
            }
            break;
        }

        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $parentId = max(
            0,
            (int)($row['parent_job_id'] ?? 0),
            (int)($payload['archive_parent_job_id'] ?? 0)
        );
        $chain[] = [
            'id' => (int)$row['id'],
            'parent_job_id' => $parentId,
            'payload' => $payload,
        ];
        $current = $parentId;
    }

    if (count($chain) >= 64 && $current > 0) {
        throw new RuntimeException('Background job ancestry exceeds the supported depth.');
    }
    return $chain;
}

/** @return array{path:string,name:string,source_job_id:int,storage:string} */
function catalog_job_source_resolve(PDO $db, array $config, int $jobId): array
{
    $chain = catalog_job_source_chain($db, $jobId);
    $resolver = new CatalogJobSourceContextResolver($db, $config);

    // Prefer the oldest retained ancestor: that is the original file/container
    // an operator selected. If it has already been cleaned, walk back toward the
    // requested child and use the nearest retained physical source instead.
    foreach (array_reverse($chain) as $row) {
        $context = $resolver->forJobId((int)$row['id']);
        $candidates = [
            [
                'path' => (string)($context['archive_full_path'] ?? ''),
                'exists' => !empty($context['archive_full_path_exists']),
                'name' => (string)($context['archive_source_name'] ?? $context['job_original_name'] ?? ''),
                'storage' => (string)($context['archive_source_storage'] ?? 'archive'),
            ],
            [
                'path' => (string)($context['job_full_path'] ?? ''),
                'exists' => !empty($context['job_full_path_exists']),
                'name' => (string)($context['job_original_name'] ?? ''),
                'storage' => 'job-source',
            ],
        ];

        foreach ($candidates as $candidate) {
            $path = trim((string)$candidate['path']);
            if ($path === '' || empty($candidate['exists']) || !is_file($path) || !is_readable($path) || is_link($path)) {
                continue;
            }
            return [
                'path' => $path,
                'name' => catalog_job_source_download_name((string)$candidate['name'], $path),
                'source_job_id' => (int)$row['id'],
                'storage' => (string)$candidate['storage'],
            ];
        }
    }

    throw new RuntimeException('No retained physical source is available for this file/job ancestry.');
}

function catalog_job_source_stream(string $path, int $start, int $length): void
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('The retained source could not be opened for download.');
    }
    try {
        if ($start > 0 && fseek($handle, $start) !== 0) {
            throw new RuntimeException('The retained source could not be positioned for download.');
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $buffer = fread($handle, min(1024 * 1024, $remaining));
            if (!is_string($buffer) || $buffer === '') {
                if (feof($handle)) {
                    break;
                }
                throw new RuntimeException('The retained source stopped while downloading.');
            }
            echo $buffer;
            $remaining -= strlen($buffer);
            if (function_exists('fastcgi_finish_request')) {
                // Do not call it here; it terminates the response. Kept explicit so
                // future transport tuning does not accidentally truncate streaming.
            }
            flush();
        }
        if ($remaining !== 0 && !connection_aborted()) {
            throw new RuntimeException('The retained source ended before the requested byte range was sent.');
        }
    } finally {
        fclose($handle);
    }
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        http_response_code(403);
        throw new RuntimeException('Administrator access is required.');
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        throw new RuntimeException('Only GET and HEAD are supported.');
    }

    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    if ($jobId < 1) {
        http_response_code(400);
        throw new RuntimeException('A positive job_id is required.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $source = catalog_job_source_resolve($db, $config, $jobId);
    $path = $source['path'];
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false || (int)$size < 1) {
        throw new RuntimeException('The retained source size is unavailable.');
    }

    $rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    try {
        $range = catalog_job_source_download_range($rangeHeader, (int)$size);
    } catch (Throwable $rangeError) {
        http_response_code(416);
        header('Content-Range: bytes */' . (int)$size);
        throw $rangeError;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(0);
    catalog_job_source_prepare_binary_output();

    if ($range['partial']) {
        http_response_code(206);
        header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . (int)$size);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $range['length']);
    header('Content-Disposition: attachment; filename="' . addcslashes($source['name'], "\\\"")
        . '"; filename*=UTF-8\'\'' . rawurlencode($source['name']));
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-transform');
    header('X-UnrealDB-Requested-Job: ' . $jobId);
    header('X-UnrealDB-Source-Job: ' . $source['source_job_id']);

    if ($method === 'HEAD') {
        exit;
    }
    catalog_job_source_stream($path, $range['start'], $range['length']);
} catch (Throwable $error) {
    $status = http_response_code();
    if ($status < 400) {
        $status = 404;
    }
    http_response_code($status);
    error_log('[UnrealDB][' . catalog_request_id() . '] retained job source download failed: '
        . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo $error->getMessage();
}
