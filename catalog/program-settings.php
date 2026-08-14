<?php
/**
 * Administrator-facing program settings that affect shared runtime behaviour.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Settings\CatalogProgramSettingsStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Program Settings')) {
        exit;
    }

    $store = new CatalogProgramSettingsStore($db, $config);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('program_settings');
        $normalMiB = filter_var($_POST['normal_upload_limit_mib'] ?? null, FILTER_VALIDATE_INT);
        $containerGiB = filter_var($_POST['container_upload_limit_gib'] ?? null, FILTER_VALIDATE_INT);
        if ($normalMiB === false || $normalMiB < 16 || $normalMiB > 1048576) {
            throw new InvalidArgumentException('Normal package upload limit must be between 16 MiB and 1 TiB.');
        }
        if ($containerGiB === false || $containerGiB < 1 || $containerGiB > 1024) {
            throw new InvalidArgumentException('PAK/container upload limit must be between 1 GiB and 1 TiB.');
        }

        $normalBytes = (int)$normalMiB * 1024 * 1024;
        $containerBytes = (int)$containerGiB * 1024 * 1024 * 1024;
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $saved = $store->saveUploadLimits($normalBytes, $containerBytes, $userId);
        catalog_start_session();
        $_SESSION['program_settings_flash'] = 'Program settings saved. Normal package limit: '
            . catalog_bytes((int)$saved['normal_upload_limit_bytes']) . '; PAK/container limit: '
            . catalog_bytes((int)$saved['container_upload_limit_bytes']) . '.';
        session_write_close();
        header('Location: program-settings.php', true, 303);
        exit;
    }

    $limits = $store->uploadLimits();
    $normalMiB = max(16, (int)round($limits['normal_upload_limit_bytes'] / (1024 * 1024)));
    $containerGiB = max(1, (int)round($limits['container_upload_limit_bytes'] / (1024 * 1024 * 1024)));

    catalog_head('Program Settings');
    catalog_page_header(
        'Program Settings',
        'Shared administrator-controlled runtime limits. These values override the tracked config defaults without editing config.php.',
        ['Upload Files' => 'profiled-upload.php', 'Upload Bucket' => 'upload-bucket.php', 'Background Jobs' => 'background-jobs.php']
    );

    if (isset($_SESSION['program_settings_flash'])) {
        catalog_flash((string)$_SESSION['program_settings_flash']);
        unset($_SESSION['program_settings_flash']);
    }

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('program_settings')) . '">'
        . '<div class="card"><h2>Upload limits</h2>'
        . '<p><label><strong>Normal Unreal package upload limit</strong><br>'
        . '<input type="number" min="16" max="1048576" step="1" required name="normal_upload_limit_mib" value="' . $normalMiB . '"> MiB</label></p>'
        . '<p class="muted">Applies to normal Unreal package uploads such as UE1/UE2/UE3 packages and UE4/UE5 package files. Files above this limit are rejected in browser preflight before network transfer.</p>'
        . '<p><label><strong>PAK/container upload limit</strong><br>'
        . '<input type="number" min="1" max="1024" step="1" required name="container_upload_limit_gib" value="' . $containerGiB . '"> GiB</label></p>'
        . '<p class="muted">Applies to UE4/UE5 .pak container uploads. The container limit cannot be lower than the normal package limit.</p>'
        . '<p><strong>Current effective values:</strong> normal ' . catalog_h(catalog_bytes((int)$limits['normal_upload_limit_bytes']))
        . '; container ' . catalog_h(catalog_bytes((int)$limits['container_upload_limit_bytes'])) . '.</p>'
        . '</div><p><button class="primary" type="submit">Save program settings</button></p></form>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] program settings failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Program Settings error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Program settings could not be loaded or saved');
    catalog_foot();
}
