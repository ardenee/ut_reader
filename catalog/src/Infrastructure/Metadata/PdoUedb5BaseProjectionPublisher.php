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
        $nameRows = array_values((array)$projection['name_candidates']);
        usort($nameRows, static function (array $left, array $right): int {
            $cmp = strcmp(bin2hex((string)$left['name_key_hash']), bin2hex((string)$right['name_key_hash']));
            if ($cmp !== 0) { return $cmp; }
            $cmp = ((int)$left['name_key_length']) <=> ((int)$right['name_key_length']);
            if ($cmp !== 0) { return $cmp; }
            $cmp = strcmp(bin2hex((string)$left['name_key_fingerprint']), bin2hex((string)$right['name_key_fingerprint']));
            return $cmp !== 0 ? $cmp : ((int)$left['first_name_index'] <=> (int)$right['first_name_index']);
        });
        $objectRows = array_values((array)$projection['object_candidates']);
        usort($objectRows, static function (array $left, array $right): int {
            $cmp = strcmp(bin2hex((string)$left['object_name_hash']), bin2hex((string)$right['object_name_hash']));
            if ($cmp !== 0) { return $cmp; }
            $cmp = ((int)$left['object_name_length']) <=> ((int)$right['object_name_length']);
            if ($cmp !== 0) { return $cmp; }
            $cmp = ((int)$left['object_kind']) <=> ((int)$right['object_kind']);
            return $cmp !== 0 ? $cmp : ((int)$left['object_index'] <=> (int)$right['object_index']);
        });

        // Search keys are immutable global dictionary rows keyed by the SHA-256
        // fingerprint. Publish them in short insert-only batches before taking
        // file-owned candidate locks so parallel workers cannot form a lock cycle.
        $searchPublishedOutsideFileTransaction = !$this->db->inTransaction();
        if ($searchPublishedOutsideFileTransaction) {
            $this->publishSearchDictionary($searchRows);
        }
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
            if (!$searchPublishedOutsideFileTransaction) {
                $this->insertSearchDictionaryBatches($searchRows);
            }

            Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_name_candidates');
            $this->insertBatches(
                'ue_uedb5_name_candidates',
                ['file_id','name_key_hash','name_key_length','name_key_fingerprint','first_name_index'],
                $nameRows,
                static fn(array $row): array => [
                    $row['file_id'],$row['name_key_hash'],$row['name_key_length'],
                    $row['name_key_fingerprint'],$row['first_name_index'],
                ]
            );
            Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_object_candidates');
            $this->insertBatches(
                'ue_uedb5_object_candidates',
                ['file_id','object_kind','object_index','object_name_hash','object_name_length','public_export_hash'],
                $objectRows,
                static fn(array $row): array => [
                    $row['file_id'],$row['object_kind'],$row['object_index'],
                    $row['object_name_hash'],$row['object_name_length'],$row['public_export_hash'],
                ]
            );

            if ($started) { $this->db->commit(); }
            return [
                'provider_keys' => $providerCount,
                'search_keys' => count($searchRows),
                'name_candidates' => count($nameRows),
                'object_candidates' => count($objectRows),
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

    /** @param list<array<string,mixed>> $rows */
    private function publishSearchDictionary(array $rows): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_search_keys');
        foreach (array_chunk($rows, 250) as $batch) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                try {
                    $this->insertSearchDictionaryBatches($batch);
                    break;
                } catch (Throwable $error) {
                    if (!PdoContention::retryable($error) || $attempt >= 5) { throw $error; }
                    usleep(PdoContention::backoffMicros($attempt, 25000));
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertSearchDictionaryBatches(array $rows): void
    {
        if ($rows === []) { return; }
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_search_keys');
        $columns = ['key_hash','key_length','key_fingerprint','normalized_text'];
        $width = count($columns);
        foreach (array_chunk($rows, 250) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, $width, '?')) . ')'));
            $params = [];
            foreach ($batch as $row) {
                array_push($params, $row['hash'], $row['length'], $row['fingerprint'], $row['normalized_text']);
            }
            $sql = 'INSERT IGNORE INTO ue_uedb5_search_keys(' . implode(',', $columns) . ') VALUES ' . $placeholders;
            $this->db->prepare($sql)->execute($params);
        }
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function insertBatches(
        string $table,
        array $columns,
        array $rows,
        callable $values,
        string $suffix = ''
    ): void {
        if ($rows === []) { return; }
        $width = count($columns);
        if ($width < 1) { throw new RuntimeException('UEDB5 batch insert requires at least one column.'); }
        foreach (array_chunk($rows, 250) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, $width, '?')) . ')'));
            $params = [];
            foreach ($batch as $row) {
                $rowValues = $values($row);
                if (count($rowValues) !== $width) {
                    throw new RuntimeException('UEDB5 batch insert value width does not match its columns.');
                }
                array_push($params, ...$rowValues);
            }
            $sql = 'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ' . $placeholders . $suffix;
            $this->db->prepare($sql)->execute($params);
        }
    }
}
