<?php
/**
 * Landing page for administrator-blocked site IPs.
 *
 * This entry point intentionally loads CatalogSupportCore rather than
 * CatalogSupport so a blocked visitor can reach the unblock-request form.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupportCore.php';
require_once __DIR__ . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Infrastructure\Security\CatalogSiteBlocklist;

catalog_start_session();

$config = catalog_config();
$db = catalog_db($config);
$ip = (new CatalogPublicAccessGuard($config))->clientIp();
$blocklist = new CatalogSiteBlocklist($db, $config);
$blocked = $blocklist->isBlocked($ip);
$submitted = false;
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        catalog_check_csrf('site_block_feedback');
        if (!$blocked) {
            throw new RuntimeException('This IP address is not currently blocked.');
        }

        $email = substr(trim((string)($_POST['email'] ?? '')), 0, 254);
        $message = trim((string)($_POST['message'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address or leave it blank.');
        }
        if (mb_strlen($message, 'UTF-8') < 20) {
            throw new InvalidArgumentException('Please provide at least 20 characters explaining the request.');
        }
        if (mb_strlen($message, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('The request cannot exceed 4,000 characters.');
        }

        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            throw new RuntimeException('The blocked IP address could not be validated.');
        }

        $recent = $db->prepare(
            'SELECT COUNT(*) FROM ue_site_block_feedback '
            . 'WHERE ip_address=? AND created_at>=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 HOUR)'
        );
        $recent->execute([$packed]);
        if ((int)$recent->fetchColumn() >= 2) {
            throw new RuntimeException('A removal request was already submitted recently. Please allow time for review.');
        }

        $statement = $db->prepare(
            'INSERT INTO ue_site_block_feedback(ip_address,email,message,status,created_at) '
            . 'VALUES(?,?,?,"open",CURRENT_TIMESTAMP(6))'
        );
        $statement->execute([$packed, $email !== '' ? $email : null, $message]);
        $submitted = true;
    } catch (Throwable $error) {
        $errorMessage = $error->getMessage();
    }
}

http_response_code($blocked ? 403 : 200);
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Access blocked - UnrealDB</title>'
    . '<link rel="stylesheet" href="assets/catalog.css">'
    . '<link rel="stylesheet" href="assets/catalog-ui.css">'
    . '</head><body><main style="max-width:900px;margin:40px auto">';

if (!$blocked) {
    echo '<div class="ui-page-header"><div><h1>This IP is not blocked</h1>'
        . '<p class="ui-page-header__description">Access from <span class="mono">' . catalog_h($ip)
        . '</span> is not currently on the UnrealDB site blocklist.</p></div></div>'
        . '<p><a class="ui-button ui-button--primary" href="index.php">Return to UnrealDB</a></p>';
} elseif ($submitted) {
    echo '<div class="ui-page-header"><div><h1>Removal request submitted</h1>'
        . '<p class="ui-page-header__description">The administrator can now review your request. '
        . 'Access remains blocked until the IP is manually removed from the blocklist.</p></div></div>';
} else {
    echo '<div class="ui-page-header"><div><h1>Access blocked</h1>'
        . '<p class="ui-page-header__description">This IP address has been blocked from accessing UnrealDB because of traffic or usage patterns requiring administrator review.</p></div></div>';
    if ($errorMessage !== '') {
        echo '<div class="ui-alert ui-alert--danger"><div></div><div><strong class="ui-alert__title">Request not submitted</strong>'
            . '<div class="ui-alert__message">' . catalog_h($errorMessage) . '</div></div></div>';
    }
    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Request removal</h2>'
        . '<p>Explain why this IP should be allowed access again. Do not include passwords or private keys.</p></div></div>'
        . '<div class="ui-section__body"><p class="muted small">Blocked IP: <span class="mono">' . catalog_h($ip) . '</span></p>'
        . '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('site_block_feedback')) . '">'
        . '<p><label class="ui-field"><span class="ui-field__label">Email (optional)</span>'
        . '<input class="ui-input" type="email" name="email" maxlength="254"></label></p>'
        . '<p><label class="ui-field"><span class="ui-field__label">Why should access be restored?</span>'
        . '<textarea class="ui-input" name="message" required minlength="20" maxlength="4000" rows="8"></textarea></label></p>'
        . '<p><button class="ui-button ui-button--primary" type="submit">Submit removal request</button></p>'
        . '</form></div></section>';
}
echo '</main></body></html>';
