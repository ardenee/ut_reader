<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Feedback unavailable.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/CatalogSmtpMailer.php';

catalog_start_session();
$returnTo = 'index.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $settings = catalog_public_access_settings($db, $config);

    $returnCandidate = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['return_to'] ?? '')
        : ($_GET['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    $returnTo = catalog_public_safe_return_path($returnCandidate);

    if (!$settings['feedback_enabled']) {
        http_response_code(503);
        catalog_head('Feedback unavailable');
        echo '<div class="card hero"><h1>Feedback is temporarily unavailable</h1><p class="muted">The public feedback form is currently disabled.</p><p><a class="button" href="' . catalog_h($returnTo) . '">Return to UnrealDB</a></p></div>';
        catalog_foot();
        exit;
    }

    $diagnosticSessionKey = 'catalog_feedback_diagnostic_attachment';
    $preparedDiagnostic = $_SESSION[$diagnosticSessionKey] ?? null;
    if (is_array($preparedDiagnostic)
        && (int)($preparedDiagnostic['created_at'] ?? 0) > 0
        && time() - (int)$preparedDiagnostic['created_at'] > 1800) {
        unset($_SESSION[$diagnosticSessionKey]);
        $preparedDiagnostic = null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('public_feedback');

        if (!empty($_POST['prepare_diagnostic_log'])) {
            $diagnosticLog = str_replace("\0", '', (string)($_POST['diagnostic_log'] ?? ''));
            $diagnosticBytes = strlen($diagnosticLog);
            if ($diagnosticBytes < 1) {
                throw new InvalidArgumentException('The public upload diagnostic log is empty.');
            }
            if ($diagnosticBytes > 512 * 1024) {
                throw new InvalidArgumentException('The public upload diagnostic log cannot exceed 512 KiB.');
            }

            $_SESSION[$diagnosticSessionKey] = [
                'filename' => 'unrealdb-public-upload-' . gmdate('Ymd-His') . '.log.txt',
                'content' => $diagnosticLog,
                'created_at' => time(),
                'category' => 'bug',
                'page_url' => substr(trim((string)($_POST['page_url'] ?? '')), 0, 1000),
                'message' => 'Public upload error report. The browser troubleshooting log is attached.',
            ];
            header(
                'Location: feedback.php?return_to=' . rawurlencode($returnTo) . '&diagnostic=1',
                true,
                303
            );
            exit;
        }

        catalog_public_feedback_limit($db);

        // A hidden field catches simple form bots without confirming that their
        // submission was detected.
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            $_SESSION['catalog_global_flash'] = 'Thank you. Your feedback has been submitted.';
            header('Location: ' . $returnTo, true, 303);
            exit;
        }

        $name = substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $email = substr(trim((string)($_POST['email'] ?? '')), 0, 254);
        $category = strtolower(trim((string)($_POST['category'] ?? 'general')));
        $message = trim((string)($_POST['message'] ?? ''));
        $pageUrl = substr(trim((string)($_POST['page_url'] ?? '')), 0, 1000);
        $allowedCategories = [
            'bug' => 'Bug or broken function',
            'file' => 'Incorrect or missing file information',
            'dependency' => 'Missing dependency or package',
            'feature' => 'Feature suggestion',
            'general' => 'General feedback',
        ];
        if (!isset($allowedCategories[$category])) {
            $category = 'general';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address or leave the email field blank.');
        }
        if (mb_strlen($message, 'UTF-8') < 20) {
            throw new InvalidArgumentException('Feedback must contain at least 20 characters.');
        }
        if (mb_strlen($message, 'UTF-8') > 10000) {
            throw new InvalidArgumentException('Feedback cannot exceed 10,000 characters.');
        }

        $reference = catalog_request_id();
        $includeDiagnostic = !empty($_POST['include_diagnostic_log'])
            && is_array($preparedDiagnostic)
            && is_string($preparedDiagnostic['content'] ?? null)
            && (string)$preparedDiagnostic['content'] !== '';
        $diagnosticFilename = $includeDiagnostic
            ? (string)($preparedDiagnostic['filename'] ?? 'unrealdb-public-upload.log.txt')
            : '';
        $mailBody = implode("\n", [
            'UnrealDB public feedback',
            '',
            'Category: ' . $allowedCategories[$category],
            'Name: ' . ($name !== '' ? $name : 'Not supplied'),
            'Email: ' . ($email !== '' ? $email : 'Not supplied'),
            'Related page: ' . ($pageUrl !== '' ? $pageUrl : 'Not supplied'),
            'Request reference: ' . $reference,
            'Submitted from IP: ' . catalog_public_access_client_ip(),
            'Diagnostic attachment: ' . ($diagnosticFilename !== '' ? $diagnosticFilename : 'None'),
            '',
            'Message:',
            $message,
        ]);
        catalog_smtp_send(
            $db,
            (string)$settings['feedback_recipient'],
            '[UnrealDB feedback] ' . $allowedCategories[$category],
            $mailBody,
            [
                'reply_to_email' => $email,
                'reply_to_name' => $name,
                'headers' => ['X-UnrealDB-Feedback-Reference' => $reference],
                'attachments' => $includeDiagnostic ? [[
                    'filename' => $diagnosticFilename,
                    'content' => (string)$preparedDiagnostic['content'],
                    'content_type' => 'text/plain',
                ]] : [],
            ]
        );
        unset($_SESSION[$diagnosticSessionKey]);
        $_SESSION['catalog_global_flash'] = 'Thank you. Your feedback has been sent to the UnrealDB team.';
        header('Location: ' . $returnTo, true, 303);
        exit;
    }

    $preparedCategory = is_array($preparedDiagnostic)
        ? strtolower(trim((string)($preparedDiagnostic['category'] ?? 'bug')))
        : strtolower(trim((string)($_GET['category'] ?? 'general')));
    $preparedPageUrl = is_array($preparedDiagnostic)
        ? (string)($preparedDiagnostic['page_url'] ?? '')
        : substr(trim((string)($_GET['page_url'] ?? '')), 0, 1000);
    $preparedMessage = is_array($preparedDiagnostic)
        ? (string)($preparedDiagnostic['message'] ?? '')
        : '';

    catalog_head('Feedback');
    echo '<div class="card hero"><h1>Send feedback</h1><p class="muted">UnrealDB is under active development. Report a broken function, incorrect file information, missing dependency or suggestion for the public service.</p></div>';
    echo '<form method="post" class="card"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('public_feedback')) . '"><input type="hidden" name="return_to" value="' . catalog_h($returnTo) . '">';
    echo '<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>';
    echo '<p><label>Name (optional)<br><input name="name" maxlength="120" autocomplete="name" style="min-width:360px"></label></p>';
    echo '<p><label>Email (optional, used only to reply)<br><input type="email" name="email" maxlength="254" autocomplete="email" style="min-width:360px"></label></p>';
    echo '<p><label>Feedback type<br><select name="category">';
    foreach ([
        'bug' => 'Bug or broken function',
        'file' => 'Incorrect or missing file information',
        'dependency' => 'Missing dependency or package',
        'feature' => 'Feature suggestion',
        'general' => 'General feedback',
    ] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($preparedCategory === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Related page URL (optional)<br><input type="url" name="page_url" maxlength="1000" value="' . catalog_h($preparedPageUrl) . '" placeholder="https://unrealdb.com/catalog/..." style="width:100%;max-width:720px"></label></p>';
    if (is_array($preparedDiagnostic) && is_string($preparedDiagnostic['content'] ?? null)) {
        echo '<div class="msg"><strong>Diagnostic log attached:</strong> <span class="mono">'
            . catalog_h((string)($preparedDiagnostic['filename'] ?? 'unrealdb-public-upload.log.txt'))
            . '</span> (' . catalog_h(catalog_bytes(strlen((string)$preparedDiagnostic['content']))) . '). '
            . '<label><input type="checkbox" name="include_diagnostic_log" value="1" checked> Include this log with the feedback email</label></div>';
    }
    echo '<p><label>Feedback<br><textarea name="message" required minlength="20" maxlength="10000" rows="10" style="width:100%;max-width:720px">' . catalog_h($preparedMessage) . '</textarea></label></p>';
    echo '<p class="muted small">Submissions are limited to ' . (int)$settings['feedback_max_requests'] . ' per ' . catalog_h(catalog_public_access_window_label((int)$settings['feedback_window_seconds'])) . ' for each IP address. Do not include passwords, private keys or other secrets.</p>';
    echo '<p><button class="primary" type="submit">Send feedback</button> <a class="button" href="' . catalog_h($returnTo) . '">Cancel</a></p></form>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] feedback submission failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Feedback error');
    }
    $status = http_response_code();
    $message = ($error instanceof InvalidArgumentException || $status === 429)
        ? $error->getMessage()
        : 'Feedback could not be sent at this time. Please try again later.';
    echo CatalogUi::alert('danger', $message, 'Feedback could not be sent');
    echo '<p><a class="button" href="feedback.php?return_to=' . rawurlencode($returnTo) . '">Return to feedback form</a> <a class="button" href="' . catalog_h($returnTo) . '">Cancel</a></p>';
    catalog_foot();
}
