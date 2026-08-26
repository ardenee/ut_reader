<?php
/**
 * Accepts anonymous correction notes for verified catalog files.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogFileFeedback.php';

function file_feedback_redirect(string $returnTo, string $result): never
{
    $safe = catalog_public_safe_return_path($returnTo);
    $separator = str_contains($safe, '?') ? '&' : '?';
    header('Location: ' . $safe . $separator . 'file_feedback=' . rawurlencode($result) . '#file-feedback', true, 303);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed';
    exit;
}

$returnTo = (string)($_POST['return_to'] ?? 'index.php');
$fileId = max(0, (int)($_POST['file_id'] ?? 0));

try {
    if (!catalog_file_feedback_same_origin_request()) {
        http_response_code(403);
        throw new RuntimeException('Cross-site file feedback submission rejected.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    catalog_public_feedback_limit($db);

    // Silently accept the honeypot path so simple form bots are not told that
    // their submission was discarded.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        file_feedback_redirect($returnTo, 'sent');
    }

    catalog_file_feedback_insert(
        $db,
        $fileId,
        (string)($_POST['message'] ?? ''),
        catalog_public_access_client_ip()
    );

    file_feedback_redirect($returnTo, 'sent');
} catch (InvalidArgumentException $error) {
    file_feedback_redirect($returnTo, 'invalid');
} catch (Throwable $error) {
    error_log(
        '[UnrealDB][' . catalog_request_id() . '] file feedback submission failed for file #'
        . $fileId . ': ' . get_class($error) . ': ' . $error->getMessage()
    );
    file_feedback_redirect($returnTo, http_response_code() === 429 ? 'limited' : 'error');
}
