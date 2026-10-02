<?php
/** Atomically publishes V5 dependency edges and package summaries from authoritative UEDB5 data. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

final class PdoUedb5DependencyProjectionPublisher
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $snapshot @return array<string,int> */
    public function publish(array $snapshot): array
    {
        $fileId = (int)($snapshot['file']['id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('UEDB5 dependency projection requires a positive file ID.');
        }
        $projection = Uedb5DependencyProjectionBuilder::build($snapshot);
        $edges = array_values((array)$projection['dependency_edges']);
        $packages = array_values((array)$projection['dependency_packages']);

        $this->sortRows($edges, $packages);
        $started = !$this->db->inTransaction();
        $maxAttempts = $started ? 5 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($started) {
                $this->db->beginTransaction();
            }
            try {
                foreach (['ue_uedb5_dependency_packages', 'ue_uedb5_dependency_edges'] as $table) {
                    Uedb5StagingIsolationContract::assertWriteTable($table);
                    $this->db->prepare('DELETE FROM ' . $table . ' WHERE file_id=?')->execute([$fileId]);
                }

                $this->insertEdges($edges);
                $this->insertPackages($packages);
                if ($started) {
                    $this->db->commit();
                }
                return [
                    'dependency_edges' => count($edges),
                    'dependency_packages' => count($packages),
                ];
            } catch (Throwable $error) {
                if ($started && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if (!$started || !PdoContention::retryable($error) || $attempt >= $maxAttempts) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 25000));
            }
        }
        throw new \LogicException('UEDB5 dependency projection contention retry loop exited unexpectedly.');
    }

    /** @param list<array<string,mixed>> $edges @param list<array<string,mixed>> $packages */
    private function sortRows(array &$edges, array &$packages): void
    {
        usort($edges, static function (array $left, array $right): int {
            $cmp = ((int)$left['source_kind']) <=> ((int)$right['source_kind']);
            return $cmp !== 0 ? $cmp : ((int)$left['source_index'] <=> (int)$right['source_index']);
        });
        usort($packages, static function (array $left, array $right): int {
            $cmp = ((int)$left['package_key_kind']) <=> ((int)$right['package_key_kind']);
            if ($cmp !== 0) { return $cmp; }
            return strcmp(bin2hex((string)$left['package_key']), bin2hex((string)$right['package_key']));
        });
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertEdges(array $rows): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_dependency_edges');
        $columns = [
            'file_id','source_kind','source_index','classification','outcome',
            'required_package_key_kind','required_package_key','required_object_key_kind','required_object_key',
            'resolved_file_id','resolved_object_kind','resolved_object_index',
        ];
        $this->insertBatches(
            'ue_uedb5_dependency_edges',
            $columns,
            $rows,
            static fn(array $row): array => [
                $row['file_id'],$row['source_kind'],$row['source_index'],$row['classification'],$row['outcome'],
                $row['required_package_key_kind'],$row['required_package_key'],$row['required_object_key_kind'],
                $row['required_object_key'],$row['resolved_file_id'],$row['resolved_object_kind'],$row['resolved_object_index'],
            ]
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertPackages(array $rows): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_dependency_packages');
        $columns = [
            'game_id','file_id','package_key_kind','package_key','required_package_name','dependency_count',
            'resolved_count','missing_count','package_only_count','common_count','unresolved_count',
            'hard_missing_count','nonhard_missing_count','summary_outcome','provider_file_id','updated_at',
        ];
        $now = gmdate('Y-m-d H:i:s');
        $this->insertBatches(
            'ue_uedb5_dependency_packages',
            $columns,
            $rows,
            static fn(array $row): array => [
                $row['game_id'],$row['file_id'],$row['package_key_kind'],$row['package_key'],
                $row['required_package_name'],$row['dependency_count'],$row['resolved_count'],$row['missing_count'],
                $row['package_only_count'],$row['common_count'],$row['unresolved_count'],$row['hard_missing_count'],
                $row['nonhard_missing_count'],$row['summary_outcome'],$row['provider_file_id'],$now,
            ]
        );
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function insertBatches(string $table, array $columns, array $rows, callable $values): void
    {
        if ($rows === []) { return; }
        $width = count($columns);
        if ($width < 1) {
            throw new RuntimeException('UEDB5 dependency batch insert requires at least one column.');
        }
        foreach (array_chunk($rows, 250) as $batch) {
            $placeholders = implode(',', array_fill(
                0,
                count($batch),
                '(' . implode(',', array_fill(0, $width, '?')) . ')'
            ));
            $params = [];
            foreach ($batch as $row) {
                $rowValues = $values($row);
                if (count($rowValues) !== $width) {
                    throw new RuntimeException('UEDB5 dependency insert width does not match its columns.');
                }
                array_push($params, ...$rowValues);
            }
            $sql = 'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ' . $placeholders;
            $this->db->prepare($sql)->execute($params);
        }
    }
}
