<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may submit dependency requests.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $items = $payload['items'] ?? [];
    if (!is_array($items) || !$items) {
        fed_json_response(['ok' => false, 'error' => 'Request has no items'], 400);
    }

    $requestHash = hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $title = trim((string)($payload['title'] ?? 'Missing dependency request'));
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
            if (!is_array($item)) {
                continue;
            }
            $requiredPackage = trim((string)($item['required_package'] ?? ''));
            $requiredPath = trim((string)($item['required_object_path'] ?? ''));
            if ($requiredPackage === '' && $requiredPath === '') {
                continue;
            }

            $wantedGuid = trim((string)($item['wanted_guid'] ?? '')) ?: null;
            $wantedMd5 = strtolower(trim((string)($item['wanted_md5'] ?? ''))) ?: null;
            $localFile = null;
            $peerFile = null;
            $status = 'requested';
            $msg = '';

            if ($wantedGuid) {
                $match = catalog_one($db, 'SELECT id FROM ue_files WHERE package_guid=? AND scan_status="verified" LIMIT 1', [$wantedGuid]);
                if ($match) {
                    $localFile = (int)$match['id'];
                    $msg = 'Matched by GUID on parent.';
                }
            }
            if (!$localFile && $requiredPackage !== '') {
                $match = catalog_one($db, 'SELECT id FROM ue_files WHERE package_name=? AND scan_status="verified" LIMIT 1', [$requiredPackage]);
                if ($match) {
                    $localFile = (int)$match['id'];
                    $msg = 'Matched by package name on parent.';
                }
            }
            if (!$localFile) {
                $msg = 'Parent does not currently have a matching file.';
            }

            $itemStmt->execute([$requestId, $requiredPackage, $requiredPath, $wantedGuid, $wantedMd5, $localFile, $peerFile, $status, $msg]);
            $count++;
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'REQUEST_SUBMIT', 'Received child request ' . $requestId . ' with ' . $count . ' item(s).');
    fed_json_response(['ok' => true, 'request_id' => $requestId, 'status' => 'submitted', 'items' => $count]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
