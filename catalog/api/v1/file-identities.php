<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $raw = trim((string)($_GET['ids'] ?? ''));
    if ($raw === '') {
        JsonResponse::send(['data' => ['files' => []]]);
    }

    $ids = [];
    foreach (preg_split('/[\s,]+/', $raw) ?: [] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if (count($ids) > 500) {
        JsonResponse::error('too_many_files', 'No more than 500 file identities can be requested at once.', 400);
    }
    if ($ids === []) {
        JsonResponse::send(['data' => ['files' => []]]);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = catalog_all(
        $application->db,
        'SELECT id,package_guid,md5,sha1 FROM ue_files WHERE id IN (' . $placeholders . ')',
        $ids
    );

    $files = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $files[(string)$id] = [
            'id' => $id,
            'guid' => trim((string)($row['package_guid'] ?? '')),
            'md5' => trim((string)($row['md5'] ?? '')),
            'sha' => trim((string)($row['sha1'] ?? '')),
        ];
    }

    JsonResponse::send(['data' => ['files' => $files]]);
} catch (Throwable $exception) {
    error_log('[UnrealDB file identities] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'File identities are temporarily unavailable.', 503);
}
