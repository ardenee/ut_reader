<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUnverifiedRename.php';

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

    $result = catalog_unverified_rename_file($db, $config, (int)$fileId, $newName);
    $_SESSION['flash_unverified_rename'] = 'Renamed '
        . ((string)$result['old_name'] !== '' ? (string)$result['old_name'] : (string)$result['old_queue_name'])
        . ' to ' . (string)$result['new_name'] . '.';
    header('Location: unverified-file-details.php?id=' . (int)$result['file_id'], true, 303);
    exit;
} catch (Throwable $error) {
    $fileId = max(0, (int)($_POST['id'] ?? 0));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash_unverified_rename'] = 'Rename failed: ' . trim($error->getMessage());
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
