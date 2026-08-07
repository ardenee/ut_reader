<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for Join requests disabled.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $db = catalog_db(catalog_config());

    // Logged-in administrators use the consolidated Connections page. This
    // public form remains only for legacy child deployments.
    if (catalog_support_is_admin()) {
        header('Location: connections.php');
        exit;
    }

    if ((string)fed_setting($db, 'join_requests_enabled', '0') !== '1') {
        catalog_head('Join Requests Disabled');
        catalog_page_header('Join requests disabled', 'This federation server is not accepting public child join requests.');
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('fed_join');
        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $siteId = strtolower(trim((string)($_POST['site_id'] ?? '')));
        $fingerprint = strtoupper(trim((string)($_POST['site_fingerprint'] ?? '')));
        $contactName = trim((string)($_POST['contact_name'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '') {
            throw new RuntimeException('Site name, site URL, site ID, and fingerprint are required.');
        }
        if (!preg_match('/^https?:\/\//i', $siteUrl)) {
            throw new RuntimeException('Site URL must start with http:// or https://');
        }
        if ((string)fed_setting($db, 'require_https_for_remote_sites', '1') === '1' && !str_starts_with(strtolower($siteUrl), 'https://')) {
            throw new RuntimeException('This Parent requires HTTPS federation site URLs.');
        }
        if (!hash_equals(fed_site_fingerprint($siteUrl, $siteId), $fingerprint)) {
            throw new RuntimeException('Fingerprint does not match the submitted site URL and site ID.');
        }
        if (catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId])) {
            throw new RuntimeException('This site ID is already paired or known.');
        }
        if (catalog_one($db, 'SELECT id FROM ue_federation_join_requests WHERE site_id=? AND status="pending" LIMIT 1', [$siteId])) {
            throw new RuntimeException('A pending join request already exists for this site ID.');
        }

        $stmt = $db->prepare('INSERT INTO ue_federation_join_requests(status,requested_role,site_name,site_url,site_id,site_fingerprint,contact_name,contact_email,notes) VALUES("pending","child",?,?,?,?,?,?,?)');
        $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $contactName ?: null, $contactEmail ?: null, $notes ?: null]);
        $id = (int)$db->lastInsertId();
        fed_log($db, null, null, 'INFO', 'LEGACY_JOIN_REQUEST_SUBMITTED', 'Legacy join request #' . $id . ' from ' . $siteName . '.');
        $_SESSION['fed_join_submitted'] = 'Join request submitted. Request ID: ' . $id . '.';
        header('Location: join.php');
        exit;
    }

    catalog_head('Request Federation Access');
    catalog_flash($_SESSION['fed_join_submitted'] ?? null);
    unset($_SESSION['fed_join_submitted']);
    catalog_page_header('Request Federation Access', 'Legacy public join form for older child deployments.');
    echo '<div class="card"><h2>Current deployments</h2><p>Current UnrealDB deployments should submit the request from the child server’s Federation → Connections page.</p></div>';
    echo '<details class="card"><summary><strong>Legacy manual identity request</strong></summary><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join')) . '">';
    echo '<p><label>Child site name<br><input name="site_name" required style="min-width:420px"></label></p>';
    echo '<p><label>Child site URL<br><input name="site_url" required style="min-width:640px" placeholder="https://child.example.com/catalog"></label></p>';
    echo '<p><label>Child site ID<br><input name="site_id" required style="min-width:420px"></label></p>';
    echo '<p><label>Child site fingerprint<br><input name="site_fingerprint" required style="min-width:420px"></label></p>';
    echo '<p><label>Contact name<br><input name="contact_name" style="min-width:420px"></label></p>';
    echo '<p><label>Contact email<br><input name="contact_email" style="min-width:420px"></label></p>';
    echo '<p><label>Notes<br><textarea name="notes" rows="5" style="width:100%"></textarea></label></p>';
    echo '<p><button>Submit legacy request</button></p></form></details>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Join request error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
