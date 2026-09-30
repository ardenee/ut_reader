<?php
/**
 * Publishes only the base UEDB5 SQL accelerators needed before dependency rebuild.
 * Dependency edges/summaries are deliberately cleared and published in the second game pass.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

final class PdoUedb5BaseProjectionPublisher
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $registration */
    public function publish(array $snapshot, array $registration): array
    {
        $fileId = (int)($snapshot['file']['id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('UEDB5 base projection requires a positive file ID.');
        }
        $projection = Uedb5SqlProjectionBuilder::build($snapshot, $registration);
        $searchRows = array_values((array)$projection['search_keys']);
        usort($searchRows, static fn(array $left, array $right): int =>
            strcmp(bin2hex((string)$left['fingerprint']), bin2hex((string)$right['fingerprint']))
        );
        $started = !$this->db->inTransaction();
        $maxAttempts = $started ? 5 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($started) { $this->db->beginTransaction(); }
            try {
            foreach ([
                'ue_uedb5_dependency_packages',
                'ue_uedb5_dependency_edges',
                'ue_uedb5_object_candidates',
                'ue_uedb5_name_candidates',
            ] as $table) {
                Uedb5StagingIsolationContract::assertWriteTable($table);
                $this->db->prepare('DELETE FROM ' . $table . ' WHERE file_id=?')->execute([$fileId]);
            }

            $providerCount = (new PdoUedb5ProviderKeyPublisher($this->db))->publish($fileId);
            Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_search_keys');
            $search = $this->db->prepare(
                'INSERT INTO ue_uedb5_search_keys(key_hash,key_length,key_fingerprint,normalized_text) '
                . 'VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE '
                . 'key_hash=VALUES(key_hash),key_length=VALUES(key_length),normalized_text=VALUES(normalized_text)'
            );
            foreach ($searchRows as $row) {
                $search->execute([$row['hash'], $row['length'], $row['fingerprint'], $row['normalized_text']]);
            }

            Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_name_candidates');
            $name = $this->db->prepare(
                'INSERT INTO ue_uedb5_name_candidates('
                . 'file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index'
                . ') VALUES(?,?,?,?,?)'
            );
            foreach ((array)$projection['name_candidates'] as $row) {
                $name->execute([
                    $row['file_id'], $row['name_key_hash'], $row['name_key_length'],
                    $row['name_key_fingerprint'], $row['first_name_index'],
                ]);
            }
            Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_object_candidates');
            $object = $this->db->prepare(
                'INSERT INTO ue_uedb5_object_candidates('
                . 'file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash'
                . ') VALUES(?,?,?,?,?,?)'
            );
            foreach ((array)$projection['object_candidates'] as $row) {
                $object->execute([
                    $row['file_id'], $row['object_kind'], $row['object_index'],
                    $row['object_name_hash'], $row['object_name_length'], $row['public_export_hash'],
                ]);
            }

            if ($started) { $this->db->commit(); }
            return [
                'provider_keys' => $providerCount,
                'search_keys' => count($searchRows),
                'name_candidates' => count((array)$projection['name_candidates']),
                'object_candidates' => count((array)$projection['object_candidates']),
                'dependency_edges' => 0,
                'dependency_packages' => 0,
            ];
            } catch (Throwable $error) {
                if ($started && $this->db->inTransaction()) { $this->db->rollBack(); }
                if (!$started || !PdoContention::retryable($error) || $attempt >= $maxAttempts) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 25000));
            }
        }
        throw new \LogicException('UEDB5 base projection contention retry loop exited unexpectedly.');
    }
}
