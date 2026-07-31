<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/CatalogSmtpMailer.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $settings = catalog_public_access_settings($db, $config);

    if (!$settings['feedback_enabled']) {
        http_response_code(503);
        catalog_head('Feedback unavailable');
        echo '<div class="card hero"><h1>Feedback is temporarily unavailable</h1><p class="muted">The public feedback form is currently disabled.</p><p><a class="button" href="index.php">Return to UnrealDB</a></p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('public_feedback');
        catalog_public_feedback_limit($db);

        // A hidden field catches simple form bots without confirming that their
        // submission was detected.
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            $_SESSION['feedback_flash'] = 'Thank you. Your feedback has been submitted.';
            header('Location: feedback.php', true, 303);
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
        $mailBody = implode("\n", [
            'UnrealDB public feedback',
            '',
            'Category: ' . $allowedCategories[$category],
            'Name: ' . ($name !== '' ? $name : 'Not supplied'),
            'Email: ' . ($email !== '' ? $email : 'Not supplied'),
            'Related page: ' . ($pageUrl !== '' ? $pageUrl : 'Not supplied'),
            'Request reference: ' . $reference,
            'Submitted from IP: ' . catalog_public_access_client_ip(),
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
            ]
        );
        $_SESSION['feedback_flash'] = 'Thank you. Your feedback has been sent to the UnrealDB team.';
        header('Location: feedback.php', true, 303);
        exit;
    }

    catalog_head('Feedback');
    echo '<div class="card hero"><h1>Send feedback</h1><p class="muted">UnrealDB is under active development. Report a broken function, incorrect file information, missing dependency or suggestion for the public service.</p></div>';
    if (isset($_SESSION['feedback_flash'])) {
        catalog_flash((string)$_SESSION['feedback_flash']);
        unset($_SESSION['feedback_flash']);
    }
    echo '<form method="post" class="card"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('public_feedback')) . '">';
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
        echo '<option value="' . catalog_h($value) . '">' . catalog_h($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Related page URL (optional)<br><input type="url" name="page_url" maxlength="1000" placeholder="https://unrealdb.com/catalog/..." style="min-width:620px"></label></p>';
    echo '<p><label>Feedback<br><textarea name="message" required minlength="20" maxlength="10000" rows="10" style="min-width:720px;max-width:100%"></textarea></label></p>';
    echo '<p class="muted small">Submissions are limited to ' . (int)$settings['feedback_max_requests'] . ' per ' . catalog_h(catalog_public_access_window_label((int)$settings['feedback_window_seconds'])) . ' for each IP address. Do not include passwords, private keys or other secrets.</p>';
    echo '<p><button class="primary" type="submit">Send feedback</button> <a class="button" href="index.php">Cancel</a></p></form>';
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
    echo '<p><a class="button" href="feedback.php">Return to feedback form</a></p>';
    catalog_foot();
}
