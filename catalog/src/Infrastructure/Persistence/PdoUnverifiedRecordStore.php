<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedRecordStore;

/** PDO adapter for database-staged unverified queue records. */
final class PdoUnverifiedRecordStore implements UnverifiedRecordStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function indexedQueueKeys(): array
    {
        $statement = $this->db->query(
            'SELECT unverified_queue_key FROM ue_files '
            . 'WHERE scan_status="unverified" AND unverified_queue_key IS NOT NULL'
        );

        $keys = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $key = trim((string)$value);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    public function deleteByQueue(int $queueGameId, string $queueName): void
    {
        $key = hash('sha256', $queueGameId . "\0" . basename($queueName));
        $statement = $this->db->prepare(
            'DELETE FROM ue_files '
            . 'WHERE scan_status="unverified" AND unverified_queue_key=?'
        );
        $statement->execute([$key]);
    }
}
