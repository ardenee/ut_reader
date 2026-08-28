<?php
/**
 * Shared public file-feedback form and persistence helpers.
 *
 * File feedback is intentionally anonymous. The database records only the
 * referenced file, the short correction note, the resolved client IP and the
 * submission time.
 */
declare(strict_types=1);

const CATALOG_FILE_FEEDBACK_MAX_LENGTH = 500;

function catalog_file_feedback_return_path(): string
{
    $current = catalog_public_current_return_path();
    $parts = parse_url($current);
    if (!is_array($parts)) {
        return 'index.php';
    }

    $path = (string)($parts['path'] ?? 'index.php');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    unset($query['file_feedback']);

    $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return $path . ($encodedQuery !== '' ? '?' . $encodedQuery : '');
}

function catalog_file_feedback_form_html(int $fileId): string
{
    if ($fileId <= 0) {
        return '';
    }

    $returnTo = catalog_file_feedback_return_path();
    $result = strtolower(trim((string)($_GET['file_feedback'] ?? '')));
    $notice = '';
    if ($result === 'sent') {
        $notice = '<p><strong>Feedback submitted.</strong> Thank you for helping correct this file.</p>';
    } elseif ($result === 'error') {
        $notice = '<p class="muted"><strong>Feedback could not be saved.</strong> Please try again.</p>';
    } elseif ($result === 'invalid') {
        $notice = '<p class="muted"><strong>Feedback was not submitted.</strong> Enter between 1 and '
            . CATALOG_FILE_FEEDBACK_MAX_LENGTH . ' characters.</p>';
    } elseif ($result === 'limited') {
        $notice = '<p class="muted"><strong>Feedback was not submitted.</strong> Too many submissions were received from this address. Please try again later.</p>';
    }

    return '<style>'
        . '.file-feedback-card>summary{cursor:pointer;font-weight:650}'
        . '.file-feedback-card textarea{width:100%;max-width:720px;resize:vertical}'
        . '.file-feedback-card .file-feedback-honeypot{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}'
        . '</style>'
        . '<details class="card file-feedback-card" id="file-feedback"' . ($result !== '' ? ' open' : '') . '>'
        . '<summary>Report incorrect file information</summary>'
        . $notice
        . '<p class="muted">Tell us what may need correcting for this file. No name or email address is required.</p>'
        . '<form method="post" action="file-feedback-submit.php">'
        . '<input type="hidden" name="file_id" value="' . $fileId . '">'
        . '<input type="hidden" name="return_to" value="' . catalog_h($returnTo) . '">'
        . '<div class="file-feedback-honeypot" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<p><label>Correction or note<br><textarea name="message" required maxlength="'
        . CATALOG_FILE_FEEDBACK_MAX_LENGTH . '" rows="3"></textarea></label></p>'
        . '<p class="muted small">Maximum ' . CATALOG_FILE_FEEDBACK_MAX_LENGTH . ' characters.</p>'
        . '<p><button class="primary" type="submit">Submit feedback</button></p>'
        . '</form></details>';
}

function catalog_file_feedback_same_origin_request(): bool
{
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
        return false;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || !isset($parts['host'])) {
        return false;
    }

    $currentHost = strtolower((string)(preg_replace('/:\\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))) ?? ''));
    $originHost = strtolower(trim((string)$parts['host']));
    return $currentHost !== '' && $originHost !== '' && hash_equals($currentHost, $originHost);
}

function catalog_file_feedback_insert(PDO $db, int $fileId, string $message, string $clientIp): void
{
    $message = trim($message);
    $length = mb_strlen($message, 'UTF-8');
    if ($length < 1 || $length > CATALOG_FILE_FEEDBACK_MAX_LENGTH) {
        throw new InvalidArgumentException(
            'File feedback must contain between 1 and ' . CATALOG_FILE_FEEDBACK_MAX_LENGTH . ' characters.'
        );
    }

    $exists = catalog_one($db, 'SELECT id FROM ue_files WHERE id=? LIMIT 1', [$fileId]);
    if (!$exists) {
        throw new InvalidArgumentException('The referenced file does not exist.');
    }

    $stmt = $db->prepare(
        "INSERT INTO ue_file_feedback(file_id,feedback_text,submitter_ip,submitted_at) "
        . "VALUES(?,?,INET6_ATON(NULLIF(?,'unknown')),CURRENT_TIMESTAMP(6))"
    );
    $stmt->execute([$fileId, $message, $clientIp]);
}
