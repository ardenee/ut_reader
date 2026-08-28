<?php
/**
 * Administrator-facing program settings that affect shared runtime behaviour.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Settings\CatalogProgramSettingsStore;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogPublicUploadSettingsStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Program Settings')) {
        exit;
    }

    $store = new CatalogProgramSettingsStore($db, $config);
    $publicUploadStore = new CatalogPublicUploadSettingsStore($db, $config);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('program_settings');
        $section = strtolower(trim((string)($_POST['settings_section'] ?? 'upload_limits')));
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

        if ($section === 'public_upload') {
            $publicMaxMiB = filter_var($_POST['public_upload_max_file_mib'] ?? null, FILTER_VALIDATE_INT);
            $filesPerHour = filter_var($_POST['public_upload_files_per_hour'] ?? null, FILTER_VALIDATE_INT);
            $bytesPerHourGiB = filter_var($_POST['public_upload_bytes_per_hour_gib'] ?? null, FILTER_VALIDATE_INT);
            $maxOutstanding = filter_var($_POST['public_upload_max_outstanding'] ?? null, FILTER_VALIDATE_INT);
            $minFreeGiB = filter_var($_POST['public_upload_min_free_gib'] ?? null, FILTER_VALIDATE_INT);
            $reservationHours = filter_var($_POST['public_upload_reservation_hours'] ?? null, FILTER_VALIDATE_INT);
            if ($publicMaxMiB === false || $publicMaxMiB < 16 || $publicMaxMiB > 1048576) {
                throw new InvalidArgumentException('Public maximum file size must be between 16 MiB and 1 TiB.');
            }
            if ($filesPerHour === false || $filesPerHour < 1 || $filesPerHour > 100000) {
                throw new InvalidArgumentException('Public files per hour must be between 1 and 100,000.');
            }
            if ($bytesPerHourGiB === false || $bytesPerHourGiB < 1 || $bytesPerHourGiB > 1048576) {
                throw new InvalidArgumentException('Public bytes per hour must be between 1 GiB and 1 PiB.');
            }
            if ($maxOutstanding === false || $maxOutstanding < 1 || $maxOutstanding > 1000) {
                throw new InvalidArgumentException('Public outstanding reservations must be between 1 and 1,000.');
            }
            if ($minFreeGiB === false || $minFreeGiB < 1 || $minFreeGiB > 1048576) {
                throw new InvalidArgumentException('Public minimum free-space reserve must be at least 1 GiB.');
            }
            if ($reservationHours === false || $reservationHours < 1 || $reservationHours > 168) {
                throw new InvalidArgumentException('Public reservation lifetime must be between 1 and 168 hours.');
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $saved = $publicUploadStore->save([
                'enabled' => !empty($_POST['public_upload_enabled']),
                'max_file_bytes' => (int)$publicMaxMiB * 1024 * 1024,
                'files_per_hour' => (int)$filesPerHour,
                'bytes_per_hour' => (int)$bytesPerHourGiB * 1024 * 1024 * 1024,
                'max_outstanding' => (int)$maxOutstanding,
                'min_free_bytes' => (int)$minFreeGiB * 1024 * 1024 * 1024,
                'reservation_seconds' => (int)$reservationHours * 3600,
            ], $userId);
            catalog_start_session();
            $_SESSION['program_settings_flash'] = 'Public contribution upload settings saved.';
        } else {
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
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $saved = $store->saveUploadLimits($normalBytes, $containerBytes, $userId);
            catalog_start_session();
            $_SESSION['program_settings_flash'] = 'Program settings saved. Normal package limit: '
                . catalog_bytes((int)$saved['normal_upload_limit_bytes']) . '; PAK/container limit: '
                . catalog_bytes((int)$saved['container_upload_limit_bytes']) . '.';
        }

        session_write_close();
        header('Location: program-settings.php', true, 303);
        exit;
    }

    $limits = $store->uploadLimits();
    $publicUpload = $publicUploadStore->settings();
    $normalMiB = max(16, (int)round($limits['normal_upload_limit_bytes'] / (1024 * 1024)));
    $containerGiB = max(1, (int)round($limits['container_upload_limit_bytes'] / (1024 * 1024 * 1024)));

    catalog_head('Program Settings');
    catalog_page_header(
        'Program Settings',
        'Shared administrator-controlled runtime limits. These values override the tracked config defaults without editing config.php.',
        ['Upload Files' => 'profiled-upload.php', 'Upload Bucket' => 'upload-bucket-v2.php', 'Background Jobs' => 'background-jobs.php']
    );

    if (isset($_SESSION['program_settings_flash'])) {
        catalog_flash((string)$_SESSION['program_settings_flash']);
        unset($_SESSION['program_settings_flash']);
    }

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('program_settings')) . '">'
        . '<input type="hidden" name="settings_section" value="upload_limits">'
        . '<div class="card"><h2>Upload limits</h2>'
        . '<p><label><strong>Normal Unreal package upload limit</strong><br>'
        . '<input type="number" min="16" max="1048576" step="1" required name="normal_upload_limit_mib" value="' . $normalMiB . '"> MiB</label></p>'
        . '<p class="muted">Applies to normal profiled game uploads such as UE1/UE2/UE3 packages and UE4/UE5 package files. Files above this limit are rejected in browser preflight before network transfer.</p>'
        . '<p><label><strong>PAK/container upload limit</strong><br>'
        . '<input type="number" min="1" max="1024" step="1" required name="container_upload_limit_gib" value="' . $containerGiB . '"> GiB</label></p>'
        . '<p class="muted">Applies to UE4/UE5 .pak container uploads. The container limit cannot be lower than the normal package limit.</p>'
        . '<p><strong>Current effective values:</strong> normal ' . catalog_h(catalog_bytes((int)$limits['normal_upload_limit_bytes']))
        . '; container ' . catalog_h(catalog_bytes((int)$limits['container_upload_limit_bytes'])) . '.</p>'
        . '</div><p><button class="primary" type="submit">Save program settings</button></p></form>';

    $publicMaxMiB = max(16, (int)round((int)$publicUpload['max_file_bytes'] / (1024 * 1024)));
    $publicBytesGiB = max(1, (int)round((int)$publicUpload['bytes_per_hour'] / (1024 * 1024 * 1024)));
    $publicMinFreeGiB = max(1, (int)round((int)$publicUpload['min_free_bytes'] / (1024 * 1024 * 1024)));
    $publicReservationHours = max(1, (int)round((int)$publicUpload['reservation_seconds'] / 3600));
    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('program_settings')) . '">'
        . '<input type="hidden" name="settings_section" value="public_upload">'
        . '<div class="card"><h2>Public contribution upload</h2>'
        . '<p><label><input type="checkbox" name="public_upload_enabled" value="1"' . (!empty($publicUpload['enabled']) ? ' checked' : '') . '> <strong>Enable public contribution uploads</strong></label></p>'
        . '<p><label><strong>Maximum public file size</strong><br><input type="number" min="16" max="1048576" name="public_upload_max_file_mib" value="' . $publicMaxMiB . '"> MiB</label></p>'
        . '<p><label><strong>Files per hour / IP</strong><br><input type="number" min="1" max="100000" name="public_upload_files_per_hour" value="' . (int)$publicUpload['files_per_hour'] . '"></label></p>'
        . '<p><label><strong>Bytes per hour / IP</strong><br><input type="number" min="1" max="1048576" name="public_upload_bytes_per_hour_gib" value="' . $publicBytesGiB . '"> GiB</label></p>'
        . '<p><label><strong>Maximum outstanding reservations / IP</strong><br><input type="number" min="1" max="1000" name="public_upload_max_outstanding" value="' . (int)$publicUpload['max_outstanding'] . '"></label></p>'
        . '<p><label><strong>Minimum free-space reserve</strong><br><input type="number" min="1" max="1048576" name="public_upload_min_free_gib" value="' . $publicMinFreeGiB . '"> GiB</label></p>'
        . '<p class="muted">New public bytes are rejected before transfer when accepting the next chunk would cross this free-space reserve.</p>'
        . '<p><label><strong>Reservation lifetime</strong><br><input type="number" min="1" max="168" name="public_upload_reservation_hours" value="' . $publicReservationHours . '"> hours</label></p>'
        . '<p class="muted">The public client sends at most 100 identities per preflight and transfers only one accepted file at a time.</p>'
        . '</div><p><button class="primary" type="submit">Save public upload settings</button></p></form>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] program settings failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Program Settings error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Program settings could not be loaded or saved');
    catalog_foot();
}
