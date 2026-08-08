<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Signs and performs federation file download/upload HTTP transfers.
 * Why: Request authentication and streaming HTTP are transport concerns, separate from durable job state and import policy.
 * Role: Infrastructure federation transport client preserving existing v2 headers/endpoints/timeouts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class CatalogFederationTransferClient
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationTransferAuth.php';
        require_once $root . '/lib/TrustedHttpSourceClient.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    /** @return list<string> */
    public function jsonHeaders(array $job, string $url, string $body): array
    {
        $timestamp = date('c');
        $nonce = \fed_random_secret();
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $algorithm = \fed_outgoing_signature_algorithm();
        $headers = [
            'Content-Type: application/json',
            'User-Agent: UnrealFileCatalogFederation/2.0',
            'X-Site-Id: ' . \fed_setting($this->db, 'site_id', ''),
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature-Algorithm: ' . $algorithm,
        ];
        if ($algorithm === 'ed25519') {
            $public = \fed_ed25519_public_key();
            if ($public === '') {
                throw new RuntimeException(
                    'Ed25519 federation signing is selected but no local private key is configured.'
                );
            }
            $headers[] = 'X-Key-Id: ' . \fed_ed25519_key_id($public);
            $signature = \fed_sign_request_ed25519('POST', $path, $timestamp, $nonce, $body);
        } else {
            $secret = (string)($job['shared_secret_plain'] ?? '');
            if ($secret === '') {
                throw new RuntimeException('Peer has no stored API secret.');
            }
            $signature = \fed_sign_request(
                $secret,
                'POST',
                $path,
                $timestamp,
                $nonce,
                $body
            );
        }
        $headers[] = 'X-Signature: ' . $signature;
        return $headers;
    }

    /** @return list<string> */
    public function uploadHeaders(
        array $job,
        string $url,
        array $file,
        string $sha256,
        int $bytes
    ): array {
        $timestamp = date('c');
        $nonce = \fed_random_secret();
        $requestPath = parse_url($url, PHP_URL_PATH) ?: '/';
        $name = (string)$file['original_name'];
        $remoteId = (int)$file['id'];
        $algorithm = \fed_outgoing_signature_algorithm();
        $headers = [
            'Content-Type: application/octet-stream',
            'Expect:',
            'User-Agent: UnrealFileCatalogFederation/2.0',
            'X-Site-Id: ' . \fed_setting($this->db, 'site_id', ''),
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature-Algorithm: ' . $algorithm,
            'X-UE-Original-Name: ' . $name,
            'X-UE-Remote-File-Id: ' . $remoteId,
            'X-UE-File-Size: ' . $bytes,
            'X-UE-SHA256: ' . $sha256,
            'X-UE-MD5: ' . (string)$file['md5'],
            'X-UE-SHA1: ' . (string)$file['sha1'],
        ];
        if ($algorithm === 'ed25519') {
            $public = \fed_ed25519_public_key();
            if ($public === '') {
                throw new RuntimeException(
                    'Ed25519 federation signing is selected but no local private key is configured.'
                );
            }
            $headers[] = 'X-Key-Id: ' . \fed_ed25519_key_id($public);
            $signature = \fed_transfer_signature_ed25519(
                'PUT',
                $requestPath,
                $timestamp,
                $nonce,
                $sha256,
                $bytes,
                $remoteId,
                $name
            );
        } else {
            $secret = (string)($job['shared_secret_plain'] ?? '');
            if ($secret === '') {
                throw new RuntimeException('Peer has no stored API secret.');
            }
            $signature = \fed_transfer_signature(
                $secret,
                'PUT',
                $requestPath,
                $timestamp,
                $nonce,
                $sha256,
                $bytes,
                $remoteId,
                $name
            );
        }
        $headers[] = 'X-Signature: ' . $signature;
        return $headers;
    }

    /** @return array{0:string,1:array<string,mixed>,2:string} */
    public function downloadInfo(array $job): array
    {
        if ((string)$job['direction'] === 'parent_pull_from_child') {
            return [
                rtrim((string)$job['site_url'], '/') . '/api/federation/download-file.php',
                [
                    'remote_file_id' => (int)$job['remote_file_id'],
                    'ignore_base_game_files' => \federation_ignore_base_game_files($this->db),
                    'dependency_exception' => $this->parentPullDependencyException($job),
                ],
                'PARENT_PULL_DOWNLOADED',
            ];
        }
        if ((string)$job['direction'] === 'download_from_parent') {
            return [
                rtrim((string)$job['site_url'], '/') . '/api/federation/download-approved-file.php',
                ['request_item_id' => (int)$job['remote_request_item_id']],
                'CHILD_APPROVED_DOWNLOADED',
            ];
        }
        throw new RuntimeException('Unsupported download direction: ' . (string)$job['direction']);
    }

    public function downloadTo(
        array $job,
        string $partPath,
        int $maxBytes,
        callable $progress
    ): array {
        [$url, $payload, $logEvent] = $this->downloadInfo($job);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Could not encode federation payload.');
        }
        $headers = $this->jsonHeaders($job, $url, $body);
        $bytes = \TrustedHttpSourceClient::postBodyToFile(
            $url,
            $headers,
            $body,
            $partPath,
            $maxBytes,
            3600,
            $progress
        );
        return ['bytes' => $bytes, 'log_event' => $logEvent];
    }

    /** @return array<string,mixed> */
    public function uploadFile(
        array $job,
        array $file,
        string $path,
        string $sha256,
        int $bytes,
        callable $progress
    ): array {
        $url = rtrim((string)$job['site_url'], '/') . '/api/federation/upload-file.php';
        $headers = $this->uploadHeaders($job, $url, $file, $sha256, $bytes);
        return \TrustedHttpSourceClient::putFileJson(
            $url,
            $headers,
            $path,
            1048576,
            7200,
            $progress
        );
    }

    public function maxTransferBytes(): int
    {
        return max(
            1,
            min(
                8 * 1024 * 1024 * 1024,
                (int)(\fed_setting($this->db, 'max_transfer_file_size_mb', '1024') ?: 1024)
                    * 1024 * 1024
            )
        );
    }

    private function parentPullDependencyException(array $job): bool
    {
        $peerFile = \catalog_one(
            $this->db,
            'SELECT pf.* FROM ue_federation_peer_files pf '
            . 'WHERE pf.peer_id=? AND pf.remote_file_id=? ORDER BY pf.id DESC LIMIT 1',
            [(int)$job['peer_id'], (int)$job['remote_file_id']]
        );
        if (!$peerFile || empty($peerFile['is_base_game'])) {
            return false;
        }

        $args = [(string)$peerFile['package_name']];
        $gameSql = '';
        $remoteGame = trim((string)($peerFile['remote_game_name'] ?? ''));
        if ($remoteGame !== '') {
            $gameSql = ' AND g.name=?';
            $args[] = $remoteGame;
        } elseif (!empty($peerFile['game_id'])) {
            $gameSql = ' AND f.game_id=?';
            $args[] = (int)$peerFile['game_id'];
        }

        $source = PdoDependencyReadSource::sql($this->db);
        return \catalog_one(
            $this->db,
            'SELECT d.id FROM ' . $source . ' d '
            . 'JOIN ue_files f ON f.id=d.file_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE d.status="missing" AND LOWER(d.required_package)=LOWER(?)'
            . $gameSql . ' LIMIT 1',
            $args
        ) !== null;
    }
}
