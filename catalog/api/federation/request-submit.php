<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';

/** @return array<string,mixed>|null */
function request_submit_package_match(PDO $db, string $package, string $gameName, string $engineKey): ?array
{
    if ($engineKey !== '') {
        $match = catalog_one(
            $db,
            'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
             FROM ue_files f
             JOIN ue_games g ON g.id=f.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             WHERE f.package_name=? AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))=UPPER(?)
             ORDER BY f.id LIMIT 1',
            [$package, $engineKey]
        );
        if ($match) {
            $match['federation_match_method'] = 'package name and engine profile';
            return $match;
        }
    }

    if ($gameName !== '') {
        $match = catalog_one(
            $db,
            'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
             FROM ue_files f
             JOIN ue_games g ON g.id=f.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             WHERE f.package_name=? AND f.scan_status="verified" AND g.name=?
             ORDER BY f.id LIMIT 1',
            [$package, $gameName]
        );
        if ($match) {
            $match['federation_match_method'] = 'package name and game';
            return $match;
        }
    }

    $match = catalog_one(
        $db,
        'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         WHERE f.package_name=? AND f.scan_status="verified"
         ORDER BY f.id LIMIT 1',
        [$package]
    );
    if ($match) {
        $match['federation_match_method'] = 'package name';
    }
    return $match;
}

/**
 * Older children submitted one row per missing object while newer children submit
 * one row per missing package. Normalize both formats to one request item per
 * game/engine/package so the parent view always matches the child package list.
 *
 * @param array<int,mixed> $items
 * @return list<array<string,mixed>>
 */
function request_submit_normalize_items(array $items): array
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
                '_object_paths' => [],
            ];
        } else {
            $groups[$key]['use_count'] = max((int)$groups[$key]['use_count'], max(0, (int)($item['use_count'] ?? 0)));
            $groups[$key]['object_count'] = max((int)$groups[$key]['object_count'], max(0, (int)($item['object_count'] ?? 0)));
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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may submit dependency requests.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $rawItems = $payload['items'] ?? [];
    if (!is_array($rawItems) || !$rawItems) {
        fed_json_response(['ok' => false, 'error' => 'Request has no items'], 400);
    }
    if (count($rawItems) > 5000) {
        fed_json_response(['ok' => false, 'error' => 'A dependency request contains too many raw rows.'], 413);
    }

    $items = request_submit_normalize_items($rawItems);
    if (!$items) {
        fed_json_response(['ok' => false, 'error' => 'Request has no valid package items'], 400);
    }
    if (count($items) > 950) {
        fed_json_response(['ok' => false, 'error' => 'A dependency request may contain no more than 950 distinct packages.'], 413);
    }

    $requestHash = hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $title = trim((string)($payload['title'] ?? 'Missing file request'));
    $notes = trim((string)($payload['notes'] ?? ''));

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE ue_federation_requests SET status="updated" WHERE peer_id=? AND direction="child_to_parent" AND status IN ("submitted","approved","part_approved")')->execute([(int)$peer['id']]);

        $stmt = $db->prepare('INSERT INTO ue_federation_requests(peer_id,direction,status,request_hash,title,notes,submitted_at) VALUES(?,"child_to_parent","submitted",?,?,?,NOW())');
        $stmt->execute([(int)$peer['id'], $requestHash, $title, $notes]);
        $requestId = (int)$db->lastInsertId();

        $itemStmt = $db->prepare('INSERT INTO ue_federation_request_items(request_id,required_package,required_object_path,wanted_guid,wanted_md5,local_file_id,peer_file_id,status,status_message) VALUES(?,?,?,?,?,?,?,?,?)');
        $count = 0;
        foreach ($items as $item) {
            $requiredPackage = trim((string)$item['required_package']);
            $requiredPath = trim((string)$item['required_object_path']);
            $wantedGuid = trim((string)$item['wanted_guid']) ?: null;
            $wantedMd5 = strtolower(trim((string)$item['wanted_md5'])) ?: null;
            $requestedGameName = trim((string)$item['game_name']);
            $requestedEngineKey = trim((string)$item['engine_key']);
            $useCount = max(0, (int)$item['use_count']);
            $objectCount = max(1, (int)$item['object_count']);
            $localFile = null;
            $peerFile = null;
            $status = 'requested';
            $msg = '';
            $match = null;

            if ($wantedGuid) {
                $match = catalog_one($db, 'SELECT * FROM ue_files WHERE package_guid=? AND scan_status="verified" LIMIT 1', [$wantedGuid]);
                if ($match) {
                    $localFile = (int)$match['id'];
                    $msg = 'Available on this parent; matched by GUID.';
                }
            }
            if (!$localFile && $requiredPackage !== '') {
                $match = request_submit_package_match($db, $requiredPackage, $requestedGameName, $requestedEngineKey);
                if ($match) {
                    $localFile = (int)$match['id'];
                    $msg = 'Available on this parent; matched by ' . (string)$match['federation_match_method'];
                    if (!empty($match['match_game_name'])) {
                        $msg .= ' (' . (string)$match['match_game_name'] . ')';
                    }
                    $msg .= '.';
                }
            }
            if ($match && base_game_file_is_protected($db, $match)) {
                $status = 'denied';
                $msg = base_game_block_message($match);
            }
            if (!$localFile) {
                $msg = 'Not found in this parent\'s catalog. This package cannot be approved until the parent imports a matching file.';
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
            $msg .= ' Request context: ' . implode(', ', $context) . '.';

            $itemStmt->execute([$requestId, $requiredPackage, $requiredPath, $wantedGuid, $wantedMd5, $localFile, $peerFile, $status, $msg]);
            $count++;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'REQUEST_SUBMIT', 'Received child request ' . $requestId . ' with ' . $count . ' distinct package item(s); raw rows=' . count($rawItems) . '.');
    fed_json_response(['ok' => true, 'request_id' => $requestId, 'status' => 'submitted', 'items' => $count]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
