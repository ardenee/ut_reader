<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Maintains federation request-item matching and aggregate request status.
 * Why: Legacy-denial repair, current-catalog relinking and request-header state belong to one lifecycle boundary.
 * Role: Infrastructure federation service; approval actions, authentication and transfer execution remain separate.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use UnrealDb\Catalog\Infrastructure\Games\CatalogBaseGameProtectionService;

final class CatalogFederationRequestLifecycleService
{
    private readonly CatalogBaseGameProtectionService $baseGameProtection;
    private readonly CatalogFederationPackageAvailabilityService $availability;

    public function __construct(private readonly PDO $db)
    {
        $this->baseGameProtection = new CatalogBaseGameProtectionService($db);
        $this->availability = new CatalogFederationPackageAvailabilityService($db);
    }

    public static function legacyUnavailableDenial(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        foreach ([
            'not found in this parent catalog; this package cannot be approved.',
            'not found in this parent\'s catalog. this package cannot be approved until the parent imports a matching file.',
            'parent does not currently have a matching file.',
            'parent does not have matching file.',
        ] as $legacy) {
            if ($message === strtolower($legacy)) {
                return true;
            }
        }

        return false;
    }

    public static function legacyBaseGameDenial(string $message): bool
    {
        $message = strtolower(trim($message));
        return $message !== ''
            && str_contains($message, 'official base-game package')
            && !str_contains($message, 'denied by this parent administrator');
    }

    public static function waitingMessage(bool $isBaseGameDependency = false): string
    {
        $message = 'Approved by this parent; waiting for a matching file to become available. The request remains active and will be linked automatically when the parent imports the file.';
        if ($isBaseGameDependency) {
            $message .= ' This is a base-game package included through the missing-dependency exception.';
        }
        return $message;
    }

    public function recalculateHeader(int $requestId): string
    {
        $counts = \catalog_one(
            $this->db,
            'SELECT COUNT(*) total,
                    SUM(status="approved") approved,
                    SUM(status="denied") denied,
                    SUM(status="requested") requested,
                    SUM(status IN ("queued","downloading","downloaded")) transferring,
                    SUM(status="imported") imported,
                    SUM(status="failed") failed
             FROM ue_federation_request_items
             WHERE request_id=?',
            [$requestId]
        ) ?: [];

        $total = (int)($counts['total'] ?? 0);
        if ($total <= 0) {
            return 'submitted';
        }

        $approved = (int)($counts['approved'] ?? 0);
        $denied = (int)($counts['denied'] ?? 0);
        $requested = (int)($counts['requested'] ?? 0);
        $transferring = (int)($counts['transferring'] ?? 0);
        $imported = (int)($counts['imported'] ?? 0);
        $failed = (int)($counts['failed'] ?? 0);

        if ($imported >= $total) {
            $status = 'completed';
        } elseif ($transferring > 0) {
            $status = 'downloading';
        } elseif ($denied >= $total) {
            $status = 'denied';
        } elseif ($approved > 0 && ($denied > 0 || $requested > 0)) {
            $status = 'part_approved';
        } elseif ($approved > 0 && $requested === 0) {
            $status = 'approved';
        } elseif ($failed > 0 && ($approved + $requested + $transferring + $imported) === 0) {
            $status = 'failed';
        } else {
            $status = 'submitted';
        }

        $this->db->prepare(
            'UPDATE ue_federation_requests
             SET status=?,
                 approved_at=CASE WHEN ? IN ("approved","part_approved") THEN COALESCE(approved_at,NOW()) ELSE approved_at END
             WHERE id=?'
        )->execute([$status, $status, $requestId]);

        return $status;
    }

    /** @return array<string,int|string> */
    public function refreshMatches(int $requestId): array
    {
        $this->baseGameProtection->ensureSchema();

        $rows = \catalog_all(
            $this->db,
            'SELECT i.*
             FROM ue_federation_request_items i
             WHERE i.request_id=?
               AND i.status IN ("requested","approved","denied")
             ORDER BY i.id',
            [$requestId]
        );

        $linked = 0;
        $baseLinked = 0;
        $waiting = 0;
        $legacyRepaired = 0;
        $legacyBaseRepaired = 0;

        $update = $this->db->prepare(
            'UPDATE ue_federation_request_items
             SET local_file_id=?, status=?, status_message=?
             WHERE id=? AND request_id=?'
        );

        foreach ($rows as $row) {
            $itemId = (int)$row['id'];
            $status = (string)$row['status'];
            $message = (string)($row['status_message'] ?? '');
            $localFileId = $row['local_file_id'] !== null ? (int)$row['local_file_id'] : 0;

            if ($status === 'denied' && self::legacyUnavailableDenial($message)) {
                $status = 'approved';
                $message = self::waitingMessage();
                $localFileId = 0;
                $legacyRepaired++;
            } elseif ($status === 'denied' && self::legacyBaseGameDenial($message)) {
                $status = 'requested';
                $message = 'Base-game dependency request restored after the federation policy change. Awaiting parent approval.';
                $legacyBaseRepaired++;
            }

            if ($status === 'denied') {
                continue;
            }

            if ($localFileId > 0) {
                $file = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [$localFileId]);
                if (!$file) {
                    $localFileId = 0;
                } else {
                    $isBaseGame = $this->baseGameProtection->fileIsProtected($file);
                    if ($isBaseGame && !str_contains(strtolower($message), 'base-game')) {
                        $message = trim($message . ' This official base-game file is available through the missing-dependency exception.');
                    }
                    $update->execute([$localFileId, $status, $message, $itemId, $requestId]);
                    continue;
                }
            }

            $availability = $this->availability->availability([
                'required_package' => (string)$row['required_package'],
                'wanted_guid' => (string)($row['wanted_guid'] ?? ''),
                'wanted_md5' => (string)($row['wanted_md5'] ?? ''),
            ]);
            $isBaseGameDependency = !empty($availability['is_base_game']);

            if (!empty($availability['available']) && (int)($availability['file_id'] ?? 0) > 0) {
                $matchedId = (int)$availability['file_id'];
                $method = trim((string)($availability['match_method'] ?? 'package identity'));
                $newMessage = $status === 'approved'
                    ? 'Approved request is now available on this parent; matched by ' . $method . '. It is ready for the child download worker.'
                    : 'Available on this parent; matched by ' . $method . '. Awaiting parent approval.';
                if ($isBaseGameDependency) {
                    $newMessage .= ' This official base-game file is included through the missing-dependency exception.';
                    $baseLinked++;
                }
                $update->execute([$matchedId, $status, $newMessage, $itemId, $requestId]);
                $linked++;
                continue;
            }

            if ($status === 'approved') {
                $update->execute([null, 'approved', self::waitingMessage($isBaseGameDependency), $itemId, $requestId]);
                $waiting++;
            } elseif ($status === 'requested') {
                $newMessage = 'Not available on this parent yet. The parent may approve the request now; it will remain active until a matching file is imported.';
                if ($isBaseGameDependency) {
                    $newMessage .= ' This is a base-game package included through the missing-dependency exception.';
                }
                $update->execute([null, 'requested', $newMessage, $itemId, $requestId]);
            }
        }

        $headerStatus = $this->recalculateHeader($requestId);

        return [
            'linked' => $linked,
            'base_game_dependency_linked' => $baseLinked,
            'waiting' => $waiting,
            'base_denied' => 0,
            'legacy_repaired' => $legacyRepaired,
            'legacy_base_game_repaired' => $legacyBaseRepaired,
            'request_status' => $headerStatus,
        ];
    }
}
