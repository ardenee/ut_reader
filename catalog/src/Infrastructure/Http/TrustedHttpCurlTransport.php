<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs bounded HTTPS transfers against an already validated/pinned trusted endpoint.
 * Why: cURL setup, response limits, streaming and error decoding are transport concerns separate from URL/DNS trust policy.
 * Role: Infrastructure HTTP transport preserving the established timeout, TLS, status and response-size contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Http;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class TrustedHttpCurlTransport
{
    public function __construct(private readonly bool $allowUntrustedTls = false)
    {
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public function bytes(array $source, string $url, int $maxBytes, string $label): string
    {
        $data = '';
        $this->request(
            $source,
            $url,
            $label,
            30,
            static function (string $chunk) use (&$data, $maxBytes): bool {
                if (strlen($data) + strlen($chunk) > $maxBytes) {
                    return false;
                }
                $data .= $chunk;
                return true;
            },
            $maxBytes
        );
        return $data;
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public function toFile(array $source, string $url, string $destination, int $maxBytes, string $label): int
    {
        $out = @fopen($destination, 'xb');
        if ($out === false) {
            throw new RuntimeException('Could not create temporary ' . $label . ' file.');
        }
        $written = 0;
        try {
            $this->request(
                $source,
                $url,
                $label,
                120,
                static function (string $chunk) use ($out, &$written, $maxBytes): bool {
                    $length = strlen($chunk);
                    if ($written + $length > $maxBytes || fwrite($out, $chunk) !== $length) {
                        return false;
                    }
                    $written += $length;
                    return true;
                },
                $maxBytes
            );
            return $written;
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($out);
        }
    }

    /**
     * @param array{base:string,host:string,ip:string} $source
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    public function postJson(
        array $source,
        string $url,
        array $headers,
        string $body,
        int $maxResponseBytes = 8388608,
        int $timeout = 60
    ): array {
        $maxResponseBytes = max(1024, min($maxResponseBytes, 64 * 1024 * 1024));
        $response = '';
        $curl = $this->curl($source, $url, max(5, min($timeout, 300)));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxResponseBytes): int {
                if (strlen($response) + strlen($chunk) > $maxResponseBytes) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $this->finish($curl, 'federation POST', [200, 201, 202], $response);
        return $this->decodeJson($response, 'Federation POST');
    }

    /**
     * @param array{base:string,host:string,ip:string} $source
     * @param list<string> $headers
     */
    public function postBodyToFile(
        array $source,
        string $url,
        array $headers,
        string $body,
        string $destination,
        int $maxBytes,
        int $timeout = 300,
        ?callable $progress = null
    ): int {
        $maxBytes = max(1, $maxBytes);
        $out = @fopen($destination, 'xb');
        if ($out === false) {
            throw new RuntimeException('Could not create federation download file.');
        }
        $written = 0;
        $curl = $this->curl($source, $url, max(5, min($timeout, 3600)));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, &$written, $maxBytes): int {
                $length = strlen($chunk);
                if ($written + $length > $maxBytes || fwrite($out, $chunk) !== $length) {
                    return 0;
                }
                $written += $length;
                return $length;
            },
        ]);
        if ($progress !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt(
                $curl,
                CURLOPT_XFERINFOFUNCTION,
                static function ($handle, float $downloadTotal, float $downloadNow) use ($progress): int {
                    $progress((int)$downloadNow, (int)$downloadTotal);
                    return 0;
                }
            );
        }
        try {
            $this->finish($curl, 'federation download', [200]);
            return $written;
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($out);
        }
    }

    /**
     * @param array{base:string,host:string,ip:string} $source
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    public function putFileJson(
        array $source,
        string $url,
        array $headers,
        string $sourceFile,
        int $maxResponseBytes = 1048576,
        int $timeout = 3600,
        ?callable $progress = null
    ): array {
        $size = filesize($sourceFile);
        if ($size === false || $size < 1 || !is_file($sourceFile) || !is_readable($sourceFile) || is_link($sourceFile)) {
            throw new RuntimeException('Federation upload source is unavailable.');
        }
        $in = @fopen($sourceFile, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not open federation upload source.');
        }
        $response = '';
        $maxResponseBytes = max(1024, min($maxResponseBytes, 16 * 1024 * 1024));
        $curl = $this->curl($source, $url, max(5, min($timeout, 7200)));
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $in,
            CURLOPT_INFILESIZE => (int)$size,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxResponseBytes): int {
                if (strlen($response) + strlen($chunk) > $maxResponseBytes) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($progress !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt(
                $curl,
                CURLOPT_XFERINFOFUNCTION,
                static function (
                    $handle,
                    float $downloadTotal,
                    float $downloadNow,
                    float $uploadTotal,
                    float $uploadNow
                ) use ($progress): int {
                    $progress((int)$uploadNow, (int)$uploadTotal);
                    return 0;
                }
            );
        }
        try {
            $this->finish($curl, 'federation upload', [200, 201, 202], $response);
        } finally {
            fclose($in);
        }
        return $this->decodeJson($response, 'Federation upload');
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public function headSize(array $source, string $url): ?int
    {
        $length = null;
        $curl = $this->curl($source, $url, 20);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            static function ($handle, string $line) use (&$length): int {
                if (stripos($line, 'Content-Length:') === 0) {
                    $value = trim(substr($line, 15));
                    $length = ctype_digit($value) ? (int)$value : null;
                }
                return strlen($line);
            }
        );
        try {
            $this->finish($curl, 'remote metadata', [200, 204]);
            return $length;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array{base:string,host:string,ip:string} $source
     * @param callable(string):bool $write
     */
    private function request(
        array $source,
        string $url,
        string $label,
        int $timeout,
        callable $write,
        int $maxBytes
    ): void {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Maximum bytes must be positive.');
        }
        $declared = null;
        $curl = $this->curl($source, $url, $timeout);
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            static function ($handle, string $line) use (&$declared, $maxBytes): int {
                if (stripos($line, 'Content-Length:') === 0) {
                    $value = trim(substr($line, 15));
                    $declared = ctype_digit($value) ? (int)$value : null;
                    if ($declared !== null && $declared > $maxBytes) {
                        return 0;
                    }
                }
                return strlen($line);
            }
        );
        curl_setopt(
            $curl,
            CURLOPT_WRITEFUNCTION,
            static fn($handle, string $chunk): int => $write($chunk) ? strlen($chunk) : 0
        );
        $this->finish($curl, $label);
        if ($declared !== null && $declared > $maxBytes) {
            throw new RuntimeException(ucfirst($label) . ' exceeds its configured size limit.');
        }
    }

    /** @param array{base:string,host:string,ip:string} $source */
    private function curl(array $source, string $url, int $timeout)
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP request.');
        }
        $ip = str_contains($source['ip'], ':') ? '[' . $source['ip'] . ']' : $source['ip'];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'UnrealDB/1.0 secure-http-client',
            CURLOPT_SSL_VERIFYPEER => !$this->allowUntrustedTls,
            CURLOPT_SSL_VERIFYHOST => $this->allowUntrustedTls ? 0 : 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$source['host'] . ':443:' . $ip],
        ]);
        return $curl;
    }

    /** @param list<int> $allowed */
    private function finish($curl, string $label, array $allowed = [200], ?string &$response = null): void
    {
        try {
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if ($ok === false || !in_array($status, $allowed, true)) {
                $detail = $this->responseErrorDetail((string)($response ?? ''));
                throw new RuntimeException(
                    ucfirst($label) . ' request failed'
                    . ($status ? ' with HTTP ' . $status : '')
                    . ($detail !== '' ? ': ' . $detail : ($error !== '' ? ': ' . $error : '.'))
                );
            }
        } finally {
            curl_close($curl);
        }
    }

    private function responseErrorDetail(string $response): string
    {
        $response = trim($response);
        if ($response === '') {
            return '';
        }

        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $message = trim((string)($decoded['error'] ?? $decoded['message'] ?? ''));
                $reference = trim((string)($decoded['reference'] ?? ''));
                if ($reference !== '') {
                    $message .= ($message !== '' ? ' ' : '') . 'Reference: ' . $reference;
                }
                if ($message !== '') {
                    return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 1000, 'UTF-8');
                }
            }
        } catch (Throwable) {
            // Fall through to a bounded plain-text response.
        }

        return mb_substr(preg_replace('/\s+/', ' ', $response) ?? $response, 0, 1000, 'UTF-8');
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $response, string $label): array
    {
        try {
            $decoded = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' returned invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' returned a non-object response.');
        }
        return $decoded;
    }
}
