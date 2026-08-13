<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Checks an advisory browser-computed SHA-1 before ordinary package upload.
 * Why: Exact already-verified content can be skipped before network transfer while server-side import hashing remains authoritative.
 * Role: Thin authenticated HTTP API over CatalogProfiledUploadDuplicatePreflight.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadDuplicatePreflight;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('profiled_upload_preflight');

    // Authentication and CSRF no longer need the PHP session. Release its
    // exclusive lock before the database lookup so another page in this browser
    // can continue independently while a large folder is being preflighted.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $body = catalog_api_json_body();
    $gameId = (int)($body['game_id'] ?? 0);
    $sha1 = strtolower(trim((string)($body['sha1'] ?? '')));
    $fileSize = (int)($body['file_size'] ?? 0);

    if ($gameId < 1) {
        JsonResponse::error('invalid_game', 'A valid target game is required.', 400);
    }
    if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
        JsonResponse::error('invalid_sha1', 'A valid SHA-1 digest is required.', 400);
    }
    if ($fileSize < 1) {
        JsonResponse::error('invalid_size', 'A positive file size is required.', 400);
    }

    $application = catalog_api_application();
    $duplicate = (new CatalogProfiledUploadDuplicatePreflight($application->db))
        ->findVerifiedDuplicate($gameId, $sha1, $fileSize);

    JsonResponse::send([
        'duplicate' => $duplicate !== null,
        'match' => $duplicate,
        'advisory_only' => true,
        'authoritative_hashing' => 'background_worker',
    ]);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_preflight', $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('[UnrealDB profiled upload preflight][' . catalog_request_id() . '] ' . $error->getMessage());
    JsonResponse::error('preflight_unavailable', 'Duplicate preflight is temporarily unavailable.', 503);
}
