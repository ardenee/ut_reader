<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Processes the administrator POST action for renaming one unverified file.
 * Why: HTTP/session concerns remain here while rename validation, filesystem rollback and persistence are delegated.
 * Role: Thin web action endpoint for the unverified rename use case.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedRenameService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Rename Unverified File')) {
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Rename requires POST.');
    }

    catalog_check_csrf('unverified-file-rename');
    $fileId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $newName = trim((string)($_POST['new_name'] ?? ''));
    if ($fileId === false || $fileId === null || $fileId < 1) {
        throw new RuntimeException('Invalid unverified file ID.');
    }

    // Never hold the administrator's PHP session lock while waiting for the
    // filesystem or MySQL. Otherwise every page opened in the same browser waits
    // behind this rename request even when the other page needs no related data.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    try {
        $db->exec('SET SESSION innodb_lock_wait_timeout=5');
        $db->exec('SET SESSION lock_wait_timeout=5');
    } catch (Throwable) {
        // Compatible servers may expose only one of these session variables.
    }

    $result = (new CatalogUnverifiedRenameService($db, $config))->rename((int)$fileId, $newName);

    catalog_start_session();
    $_SESSION['flash_unverified_rename'] = 'Renamed '
        . ((string)$result['old_name'] !== ''
            ? (string)$result['old_name']
            : (string)$result['old_queue_name'])
        . ' to ' . (string)$result['new_name'] . '.';
    session_write_close();

    header('Location: unverified-file-details.php?id=' . (int)$result['file_id'], true, 303);
    exit;
} catch (Throwable $error) {
    $fileId = max(0, (int)($_POST['id'] ?? 0));
    try {
        catalog_start_session();
        $_SESSION['flash_unverified_rename'] = 'Rename failed: ' . trim($error->getMessage());
        session_write_close();
    } catch (Throwable) {
        // Preserve the original rename error when session recovery also fails.
    }
    if ($fileId > 0 && !headers_sent()) {
        header('Location: unverified-file-details.php?id=' . $fileId, true, 303);
        exit;
    }

    if (!headers_sent()) {
        catalog_head('Rename Unverified File Error');
    }
    echo CatalogUi::alert('danger', 'The staged file could not be renamed.', $error->getMessage());
    echo '<p><a class="button" href="unverified-files.php">Back to Unverified Files</a></p>';
    catalog_foot();
}
