<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Delivers plain-text mail over SMTP using the configured transport/authentication policy.
 * Why: SMTP socket protocol, message construction and header safety are transport concerns separate from settings storage.
 * Role: Infrastructure email transport preserving the existing SMTP command, TLS and message format contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Email;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CatalogSmtpTransport
{
    private readonly CatalogSmtpSettingsStore $settings;

    public function __construct(PDO $db)
    {
        $this->settings = new CatalogSmtpSettingsStore($db);
    }

    public static function address(string $email, string $label): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException($label . ' is not a valid email address.');
        }
        return $email;
    }

    /**
     * @param array{reply_to_email?:string,reply_to_name?:string,headers?:array<string,string>} $options
     */
    public function send(string $recipient, string $subject, string $body, array $options = []): void
    {
        $settings = $this->settings->settings();
        if (!$settings['enabled']) {
            throw new RuntimeException('SMTP mail delivery is disabled in administrator settings.');
        }
        if ($settings['host'] === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        $recipient = self::address($recipient, 'Feedback recipient');
        $fromEmail = self::address((string)$settings['from_email'], 'SMTP From address');
        $replyToEmail = trim((string)($options['reply_to_email'] ?? ''));
        if ($replyToEmail !== '') {
            $replyToEmail = self::address($replyToEmail, 'Reply-To address');
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
            throw new RuntimeException(
                'Could not connect to SMTP server: '
                . ($errorText !== '' ? $errorText : 'connection failed')
                . ' (' . $errorNumber . ').'
            );
        }

        stream_set_timeout($stream, (int)$settings['timeout_seconds']);
        $hostname = preg_replace(
            '/[^A-Za-z0-9.-]+/',
            '',
            (string)($_SERVER['SERVER_NAME'] ?? 'unrealdb.local')
        ) ?: 'unrealdb.local';

        try {
            $this->expect($stream, [220], 'SMTP greeting');
            $this->command($stream, 'EHLO ' . $hostname, [250], 'EHLO');

            if ($encryption === 'starttls') {
                $this->command($stream, 'STARTTLS', [220], 'STARTTLS');
                $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new RuntimeException('Could not establish TLS encryption with the SMTP server.');
                }
                $this->command($stream, 'EHLO ' . $hostname, [250], 'EHLO after STARTTLS');
            }

            $username = (string)$settings['username'];
            if ($username !== '') {
                $this->command($stream, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                $this->command($stream, base64_encode($username), [334], 'SMTP username');
                $this->command(
                    $stream,
                    base64_encode((string)$settings['password']),
                    [235],
                    'SMTP password'
                );
            }

            $this->command($stream, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
            $this->command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO');
            $this->command($stream, 'DATA', [354], 'DATA');

            $fromName = self::encodedHeader((string)$settings['from_name']);
            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $hostname . '>';
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: ' . $messageId,
                'From: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $fromEmail . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . self::encodedHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: UnrealDB',
            ];
            if ($replyToEmail !== '') {
                $replyName = self::encodedHeader((string)($options['reply_to_name'] ?? ''));
                $headers[] = 'Reply-To: '
                    . ($replyName !== '' ? $replyName . ' ' : '')
                    . '<' . $replyToEmail . '>';
            }
            foreach ((array)($options['headers'] ?? []) as $name => $value) {
                $safeName = preg_replace('/[^A-Za-z0-9-]+/', '', (string)$name) ?? '';
                if ($safeName !== '') {
                    $headers[] = $safeName . ': ' . self::headerValue((string)$value);
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
            $this->expect($stream, [250], 'Message submission');
            try {
                $this->command($stream, 'QUIT', [221], 'QUIT');
            } catch (Throwable) {
                // The message was already accepted; a missing QUIT response is harmless.
            }
        } finally {
            fclose($stream);
        }
    }

    private static function headerValue(string $value, int $maximum = 500): string
    {
        $value = preg_replace('/[\r\n\0]+/', ' ', $value) ?? '';
        return substr(trim($value), 0, $maximum);
    }

    private static function encodedHeader(string $value): string
    {
        $value = self::headerValue($value, 500);
        if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @return array{code:int,text:string} */
    private function readResponse($stream): array
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

    /** @return array{code:int,text:string} */
    private function expect($stream, array $expected, string $context): array
    {
        $response = $this->readResponse($stream);
        if (!in_array((int)$response['code'], $expected, true)) {
            throw new RuntimeException($context . ' failed: ' . (string)$response['text']);
        }
        return $response;
    }

    /** @return array{code:int,text:string} */
    private function command($stream, string $command, array $expected, string $context): array
    {
        $written = fwrite($stream, $command . "\r\n");
        if ($written === false || $written !== strlen($command) + 2) {
            throw new RuntimeException('Could not write the SMTP ' . $context . ' command.');
        }
        return $this->expect($stream, $expected, $context);
    }
}
