<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    // The local administrator always uses the child-side easy join workflow.
    // It submits the locally generated identity automatically and handles the
    // role transition. The public form below remains only for legacy children
    // posting a manual request to a parent deployment.
    if (catalog_support_is_admin()) {
        header('Location: join-main-parent.php');
        exit;
    }

    if ((string)fed_setting($db, 'join_requests_enabled', '1') !== '1') {
        catalog_head('Join Requests Disabled');
        catalog_page_header('Join requests disabled', 'This federation parent is not accepting public join requests right now.');
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
            throw new RuntimeException('This parent requires HTTPS federation site URLs.');
        }

        $expected = fed_site_fingerprint($siteUrl, $siteId);
        if (!hash_equals($expected, $fingerprint)) {
            throw new RuntimeException('Fingerprint does not match the submitted site URL and site ID. Expected: ' . $expected);
        }

        $existingPeer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId]);
        if ($existingPeer) {
            throw new RuntimeException('This site ID is already paired or known to this parent.');
        }

        $existingPending = catalog_one($db, 'SELECT id FROM ue_federation_join_requests WHERE site_id=? AND status="pending" LIMIT 1', [$siteId]);
        if ($existingPending) {
            throw new RuntimeException('A pending join request already exists for this site ID.');
        }

        $stmt = $db->prepare('INSERT INTO ue_federation_join_requests(status, requested_role, site_name, site_url, site_id, site_fingerprint, contact_name, contact_email, notes) VALUES("pending", "child", ?,?,?,?,?,?,?,?)');
        $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $contactName ?: null, $contactEmail ?: null, $notes ?: null]);
        $id = (int)$db->lastInsertId();
        fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_SUBMITTED', 'Legacy join request #' . $id . ' from ' . $siteName . ' / ' . $siteUrl);
        $_SESSION['fed_join_submitted'] = 'Join request submitted. Request ID: ' . $id . '. Wait for the parent admin to approve it, then use the claim page on your child site.';
        header('Location: join.php');
        exit;
    }

    catalog_head('Request Federation Access');
    catalog_flash($_SESSION['fed_join_submitted'] ?? null);
    unset($_SESSION['fed_join_submitted']);

    catalog_page_header('Request Federation Access', 'This is the legacy public join form for older child deployments.');

    echo '<div class="card"><h2>Automatic join recommended</h2>';
    echo '<p>Current UnrealDB deployments should log in as administrator on the child site and open <span class="mono">/catalog/federation/join.php</span>. The child sends its identity automatically.</p>';
    echo '</div>';

    echo '<details class="card"><summary><strong>Legacy manual identity request</strong></summary>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join')) . '">';
    echo '<p><label>Child site name<br><input name="site_name" required style="min-width:420px"></label></p>';
    echo '<p><label>Child site URL<br><input name="site_url" required style="min-width:640px" placeholder="https://child.example.com/catalog"></label></p>';
    echo '<p><label>Child site ID<br><input name="site_id" required style="min-width:420px"></label></p>';
    echo '<p><label>Child site fingerprint<br><input name="site_fingerprint" required style="min-width:420px"></label></p>';
    echo '<p><label>Contact name<br><input name="contact_name" style="min-width:420px"></label></p>';
    echo '<p><label>Contact email<br><input name="contact_email" style="min-width:420px"></label></p>';
    echo '<p><label>Notes / reason<br><textarea name="notes" rows="5" style="width:100%" placeholder="Who are you, what files/games do you host, etc."></textarea></label></p>';
    echo '<p><button>Submit legacy request</button></p></form></details>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Join request error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
