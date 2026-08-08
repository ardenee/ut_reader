<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns child dependency-request normalization, availability checks, policy filtering and request persistence.
 * Why: Dependency request endpoints should authenticate/parse/serialize; compatibility normalization and request transactions belong to Infrastructure.
 * Role: Infrastructure federation dependency-request protocol service preserving current request-submit and package-availability contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationDependencyRequestService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/BaseGameProtection.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
        require_once $root . '/lib/FederationPackageAvailability.php';
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(array $peer, array $payload): array
    {
        \base_game_ensure($this->db);
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException(
                'Only a paired child may submit dependency requests.',
                403
            );
        }

        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            throw new CatalogFederationApiException('Request has no items', 400);
        }
        if (count($rawItems) > 5000) {
            throw new CatalogFederationApiException(
                'A dependency request contains too many raw rows.',
                413
            );
        }

        $items = $this->normalizeItems($rawItems);
        if ($items === []) {
            throw new CatalogFederationApiException('Request has no valid package items', 400);
        }

        $ignoreBaseGame = \federation_ignore_base_game_files($this->db);
        if ($ignoreBaseGame) {
            $items = array_values(array_filter(
                $items,
                function (array $item): bool {
                    if (!empty($item['is_base_game_dependency'])) {
                        return false;
                    }
                    return \federation_base_game_package_match(
                        $this->db,
                        (string)($item['required_package'] ?? ''),
                        (string)($item['game_name'] ?? ''),
                        (string)($item['engine_key'] ?? '')
                    ) === null;
                }
            ));
        }
        if ($items === []) {
            throw new CatalogFederationApiException(
                'Every selected package is excluded by the parent Ignore base-game files policy.',
                422,
                null,
                ['policy' => \federation_parent_base_game_policy($this->db)]
            );
        }
        if (count($items) > 950) {
            throw new CatalogFederationApiException(
                'A dependency request may contain no more than 950 distinct packages.',
                413
            );
        }

        $requestHash = hash(
            'sha256',
            json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $title = trim((string)($payload['title'] ?? 'Missing file request'));
        $notes = trim((string)($payload['notes'] ?? ''));

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE ue_federation_requests SET status="updated" '
                . 'WHERE peer_id=? AND direction="child_to_parent" '
                . 'AND status IN ("submitted","approved","part_approved")'
            )->execute([(int)$peer['id']]);

            $statement = $this->db->prepare(
                'INSERT INTO ue_federation_requests('
                . 'peer_id,direction,status,request_hash,title,notes,submitted_at'
                . ') VALUES(?,"child_to_parent","submitted",?,?,?,NOW())'
            );
            $statement->execute([(int)$peer['id'], $requestHash, $title, $notes]);
            $requestId = (int)$this->db->lastInsertId();

            $itemStatement = $this->db->prepare(
                'INSERT INTO ue_federation_request_items('
                . 'request_id,required_package,required_object_path,wanted_guid,wanted_md5,'
                . 'local_file_id,peer_file_id,status,status_message'
                . ') VALUES(?,?,?,?,?,?,?,?,?)'
            );
            $count = 0;
            $baseGameItems = 0;
            foreach ($items as $item) {
                $requiredPackage = trim((string)$item['required_package']);
                $requiredPath = trim((string)$item['required_object_path']);
                $wantedGuid = trim((string)$item['wanted_guid']) ?: null;
                $wantedMd5 = strtolower(trim((string)$item['wanted_md5'])) ?: null;
                $requestedGameName = trim((string)$item['game_name']);
                $requestedEngineKey = trim((string)$item['engine_key']);
                $useCount = max(0, (int)$item['use_count']);
                $objectCount = max(1, (int)$item['object_count']);

                $availability = \federation_package_availability($this->db, $item);
                if (!empty($availability['policy_excluded'])) {
                    continue;
                }
                $localFile = !empty($availability['available'])
                    && (int)($availability['file_id'] ?? 0) > 0
                        ? (int)$availability['file_id']
                        : null;
                $peerFile = null;
                $status = 'requested';
                $isBaseGame = !empty($availability['is_base_game']);
                if ($isBaseGame) {
                    $baseGameItems++;
                }

                if (empty($availability['available'])) {
                    $message = 'Not available on this parent yet. The parent may approve the request now; '
                        . 'it will remain active until a matching file is imported.';
                } else {
                    $message = 'Available on this parent; matched by '
                        . (string)($availability['match_method'] ?? 'package identity');
                    if (!empty($availability['game_name'])) {
                        $message .= ' (' . (string)$availability['game_name'] . ')';
                    }
                    $message .= '.';
                }
                if ($isBaseGame) {
                    $message .= ' This official base-game package is included because the parent policy permits '
                        . 'base-game federation participation.';
                }

                $context = [];
                if ($requestedGameName !== '') {
                    $context[] = 'child game ' . $requestedGameName;
                }
                if ($requestedEngineKey !== '') {
                    $context[] = 'engine ' . $requestedEngineKey;
                }
                $context[] = $objectCount . ' missing object(s)';
                if ($useCount > 0) {
                    $context[] = 'needed by ' . $useCount . ' child file(s)';
                }
                $message .= ' Request context: ' . implode(', ', $context) . '.';

                $itemStatement->execute([
                    $requestId,
                    $requiredPackage,
                    $requiredPath,
                    $wantedGuid,
                    $wantedMd5,
                    $localFile,
                    $peerFile,
                    $status,
                    $message,
                ]);
                $count++;
            }

            if ($count < 1) {
                throw new RuntimeException(
                    'No request items remain after applying the base-game federation policy.'
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'REQUEST_SUBMIT',
            'Received child request ' . $requestId . ' with ' . $count
            . ' distinct package item(s), including ' . $baseGameItems
            . ' base-game item(s) permitted by policy; raw rows=' . count($rawItems) . '.'
        );

        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'submitted',
            'items' => $count,
            'base_game_items' => $baseGameItems,
            'policy' => \federation_parent_base_game_policy($this->db),
        ];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function availability(array $peer, array $payload): array
    {
        \base_game_ensure($this->db);
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException(
                'Only a paired child may check parent package availability.',
                403
            );
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return [
                'ok' => true,
                'policy' => \federation_parent_base_game_policy($this->db),
                'items' => [],
            ];
        }
        if (count($items) > 950) {
            throw new CatalogFederationApiException(
                'Availability checks are limited to 950 packages per request.',
                413
            );
        }

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string)($item['key'] ?? ''));
            $requiredPackage = trim((string)($item['required_package'] ?? ''));
            if ($key === '' || $requiredPackage === '') {
                continue;
            }
            $availability = \federation_package_availability($this->db, $item);
            $results[] = ['key' => $key, 'required_package' => $requiredPackage] + $availability;
        }

        return [
            'ok' => true,
            'policy' => \federation_parent_base_game_policy($this->db),
            'items' => $results,
        ];
    }

    /** @param array<int,mixed> $items @return list<array<string,mixed>> */
    private function normalizeItems(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $requiredPackage = trim((string)($item['required_package'] ?? ''));
            $requiredPath = trim((string)($item['required_object_path'] ?? ''));
            if ($requiredPackage === '' && $requiredPath === '') {
                continue;
            }

            $gameName = trim((string)($item['game_name'] ?? ''));
            $engineKey = trim((string)($item['engine_key'] ?? ''));
            $identity = $requiredPackage !== '' ? $requiredPackage : $requiredPath;
            $key = strtolower($gameName) . "\0" . strtolower($engineKey) . "\0" . strtolower($identity);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'required_package' => $requiredPackage,
                    'required_object_path' => $requiredPath,
                    'wanted_guid' => trim((string)($item['wanted_guid'] ?? '')),
                    'wanted_md5' => strtolower(trim((string)($item['wanted_md5'] ?? ''))),
                    'game_name' => $gameName,
                    'engine_key' => $engineKey,
                    'use_count' => max(0, (int)($item['use_count'] ?? 0)),
                    'object_count' => max(0, (int)($item['object_count'] ?? 0)),
                    'is_base_game_dependency' => !empty($item['is_base_game_dependency']),
                    '_object_paths' => [],
                ];
            } else {
                $groups[$key]['use_count'] = max(
                    (int)$groups[$key]['use_count'],
                    max(0, (int)($item['use_count'] ?? 0))
                );
                $groups[$key]['object_count'] = max(
                    (int)$groups[$key]['object_count'],
                    max(0, (int)($item['object_count'] ?? 0))
                );
                $groups[$key]['is_base_game_dependency'] = !empty($groups[$key]['is_base_game_dependency'])
                    || !empty($item['is_base_game_dependency']);
                if ((string)$groups[$key]['required_object_path'] === '' && $requiredPath !== '') {
                    $groups[$key]['required_object_path'] = $requiredPath;
                }
            }

            if ($requiredPath !== '') {
                $groups[$key]['_object_paths'][strtolower($requiredPath)] = true;
            }
        }

        $normalized = [];
        foreach ($groups as $group) {
            $distinctPaths = count($group['_object_paths']);
            $group['object_count'] = max(1, (int)$group['object_count'], $distinctPaths);
            unset($group['_object_paths']);
            $normalized[] = $group;
        }
        return $normalized;
    }
}
