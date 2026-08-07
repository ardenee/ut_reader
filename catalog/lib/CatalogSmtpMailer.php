<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog smtp mailer.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

function catalog_smtp_setting_names(): array
{
    return [
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_email',
        'smtp_from_name',
        'smtp_timeout_seconds',
    ];
}

function catalog_smtp_settings(PDO $db): array
{
    $defaults = [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'encryption' => 'starttls',
        'username' => '',
        'password' => '',
        'from_email' => 'info@unrealdb.com',
        'from_name' => 'UnrealDB',
        'timeout_seconds' => 20,
    ];
    $names = catalog_smtp_setting_names();
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $db->prepare(
        'SELECT setting_name,setting_value FROM ue_federation_settings WHERE setting_name IN (' . $placeholders . ')'
    );
    $statement->execute($names);
    $values = [];
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $values[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
    }

    $storedPassword = (string)($values['smtp_password'] ?? '');
    $password = $storedPassword;
    if ($storedPassword !== '' && function_exists('fed_secret_for_crypto')) {
        try {
            $password = fed_secret_for_crypto($storedPassword);
        } catch (Throwable $error) {
            throw new RuntimeException('The saved SMTP password could not be decrypted: ' . $error->getMessage(), 0, $error);
        }
    }

    $encryption = strtolower(trim((string)($values['smtp_encryption'] ?? $defaults['encryption'])));
    if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
        $encryption = 'starttls';
    }

    return [
        'enabled' => catalog_public_access_bool($values['smtp_enabled'] ?? null, false),
        'host' => substr(trim((string)($values['smtp_host'] ?? $defaults['host'])), 0, 255),
        'port' => catalog_public_access_int($values['smtp_port'] ?? null, 587, 1, 65535),
        'encryption' => $encryption,
        'username' => substr(trim((string)($values['smtp_username'] ?? $defaults['username'])), 0, 255),
        'password' => $password,
        'password_is_set' => $storedPassword !== '',
        'from_email' => substr(trim((string)($values['smtp_from_email'] ?? $defaults['from_email'])), 0, 254),
        'from_name' => substr(trim((string)($values['smtp_from_name'] ?? $defaults['from_name'])), 0, 180),
        'timeout_seconds' => catalog_public_access_int($values['smtp_timeout_seconds'] ?? null, 20, 3, 120),
    ];
}

function catalog_smtp_header_value(string $value, int $maximum = 500): string
{
    $value = preg_replace('/[\r\n\0]+/', ' ', $value) ?? '';
    return substr(trim($value), 0, $maximum);
}

function catalog_smtp_address(string $email, string $label): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException($label . ' is not a valid email address.');
    }
    return $email;
}

function catalog_smtp_encoded_header(string $value): string
{
    $value = catalog_smtp_header_value($value, 500);
    if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function catalog_smtp_read_response($stream): array
{
    $lines = [];
    $code = 0;
    while (($line = fgets($stream, 8192)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $match) === 1) {
            $code = (int)$match[1];
            if ($match[2] === ' ') {
                break;
            }
        } elseif (count($lines) > 100) {
            break;
        }
    }
    if ($lines === []) {
        throw new RuntimeException('The SMTP server closed the connection without a response.');
    }
    return ['code' => $code, 'text' => implode("\n", $lines)];
}

function catalog_smtp_expect($stream, array $expected, string $context): array
{
    $response = catalog_smtp_read_response($stream);
    if (!in_array((int)$response['code'], $expected, true)) {
        throw new RuntimeException($context . ' failed: ' . (string)$response['text']);
    }
    return $response;
}

function catalog_smtp_command($stream, string $command, array $expected, string $context): array
{
    $written = fwrite($stream, $command . "\r\n");
    if ($written === false || $written !== strlen($command) + 2) {
        throw new RuntimeException('Could not write the SMTP ' . $context . ' command.');
    }
    return catalog_smtp_expect($stream, $expected, $context);
}

/**
 * @param array{reply_to_email?:string,reply_to_name?:string,headers?:array<string,string>} $options
 */
function catalog_smtp_send(PDO $db, string $recipient, string $subject, string $body, array $options = []): void
{
    $settings = catalog_smtp_settings($db);
    if (!$settings['enabled']) {
        throw new RuntimeException('SMTP mail delivery is disabled in administrator settings.');
    }
    if ($settings['host'] === '') {
        throw new RuntimeException('SMTP host is not configured.');
    }

    $recipient = catalog_smtp_address($recipient, 'Feedback recipient');
    $fromEmail = catalog_smtp_address((string)$settings['from_email'], 'SMTP From address');
    $replyToEmail = trim((string)($options['reply_to_email'] ?? ''));
    if ($replyToEmail !== '') {
        $replyToEmail = catalog_smtp_address($replyToEmail, 'Reply-To address');
    }

    $host = (string)$settings['host'];
    $port = (int)$settings['port'];
    $encryption = (string)$settings['encryption'];
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $remote = $transport . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);
    $errorNumber = 0;
    $errorText = '';
    $stream = @stream_socket_client(
        $remote,
        $errorNumber,
        $errorText,
        (int)$settings['timeout_seconds'],
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!is_resource($stream)) {
        throw new RuntimeException('Could not connect to SMTP server: ' . ($errorText !== '' ? $errorText : 'connection failed') . ' (' . $errorNumber . ').');
    }

    stream_set_timeout($stream, (int)$settings['timeout_seconds']);
    $hostname = preg_replace('/[^A-Za-z0-9.-]+/', '', (string)($_SERVER['SERVER_NAME'] ?? 'unrealdb.local')) ?: 'unrealdb.local';

    try {
        catalog_smtp_expect($stream, [220], 'SMTP greeting');
        catalog_smtp_command($stream, 'EHLO ' . $hostname, [250], 'EHLO');

        if ($encryption === 'starttls') {
            catalog_smtp_command($stream, 'STARTTLS', [220], 'STARTTLS');
            $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('Could not establish TLS encryption with the SMTP server.');
            }
            catalog_smtp_command($stream, 'EHLO ' . $hostname, [250], 'EHLO after STARTTLS');
        }

        $username = (string)$settings['username'];
        if ($username !== '') {
            catalog_smtp_command($stream, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            catalog_smtp_command($stream, base64_encode($username), [334], 'SMTP username');
            catalog_smtp_command($stream, base64_encode((string)$settings['password']), [235], 'SMTP password');
        }

        catalog_smtp_command($stream, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
        catalog_smtp_command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO');
        catalog_smtp_command($stream, 'DATA', [354], 'DATA');

        $fromName = catalog_smtp_encoded_header((string)$settings['from_name']);
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $hostname . '>';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $messageId,
            'From: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . catalog_smtp_encoded_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: UnrealDB',
        ];
        if ($replyToEmail !== '') {
            $replyName = catalog_smtp_encoded_header((string)($options['reply_to_name'] ?? ''));
            $headers[] = 'Reply-To: ' . ($replyName !== '' ? $replyName . ' ' : '') . '<' . $replyToEmail . '>';
        }
        foreach ((array)($options['headers'] ?? []) as $name => $value) {
            $safeName = preg_replace('/[^A-Za-z0-9-]+/', '', (string)$name) ?? '';
            if ($safeName !== '') {
                $headers[] = $safeName . ': ' . catalog_smtp_header_value((string)$value);
            }
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        $body = preg_replace('/(^|\r\n)\./', '$1..', $body) ?? $body;
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset));
            if ($written === false || $written < 1) {
                throw new RuntimeException('Could not send the SMTP message body.');
            }
            $offset += $written;
        }
        catalog_smtp_expect($stream, [250], 'Message submission');
        try {
            catalog_smtp_command($stream, 'QUIT', [221], 'QUIT');
        } catch (Throwable) {
            // The message was already accepted; a missing QUIT response is harmless.
        }
    } finally {
        fclose($stream);
    }
}
