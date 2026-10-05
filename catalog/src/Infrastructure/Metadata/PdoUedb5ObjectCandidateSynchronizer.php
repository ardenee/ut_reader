<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

final class PdoUedb5ObjectCandidateSynchronizer
{
    private Uedb5MetadataReader $reader;

    public function __construct(private readonly PDO $db, string $storageRoot)
    {
        $storageRoot = rtrim($storageRoot, "\\/");
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage path is required for UEDB5 object sync.');
        }
        $this->reader = new Uedb5MetadataReader($storageRoot);
    }

    /** @return array<string,mixed> */
    public function reconcile(int $fileId, bool $apply): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required for UEDB5 object sync.');
        }
        $registration = $this->registration($fileId);
        $projection = $this->expectedProjection($registration);
        $expected = $this->normalizeRows((array)$projection['object_candidates']);
        $actual = $this->actualRows($fileId);
        $diff = $this->diff($expected, $actual);

        if (!$diff['matches'] && $apply) {
            $this->publishSearchDictionary((array)$projection['search_keys']);
            $this->replaceCandidates($fileId, (array)$projection['object_candidates']);
            $actual = $this->actualRows($fileId);
            $post = $this->diff($expected, $actual);
            if (!$post['matches']) {
                throw new RuntimeException('UEDB5 object candidate projection still differs after repair for file #' . $fileId . '.');
            }
            $diff['repaired'] = true;
        } else {
            $diff['repaired'] = false;
        }

        return $diff + [
            'file_id' => $fileId,
            'game_id' => (int)$registration['game_id'],
            'expected_count' => count($expected),
            'actual_count' => count($actual),
        ];
    }

    /** @return array<string,mixed> */
    private function registration(int $fileId): array
    {
        $statement = $this->db->prepare('SELECT * FROM ue_uedb5_files WHERE file_id=? LIMIT 1');
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' has no staged UEDB5 registration.');
        }
        return $row;
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private function expectedProjection(array $registration): array
    {
        $fileId = (int)$registration['file_id'];
        $gameId = (int)$registration['game_id'];
        $manifest = $this->reader->manifest($gameId, $fileId);
        $available = (array)($manifest['sections'] ?? []);
        $sections = [];
        foreach (['exports', 'cell_exports'] as $section) {
            if (!array_key_exists($section, $available)) { continue; }
            $rows = [];
            foreach ($this->reader->scan($gameId, $fileId, $section) as $row) {
                $rows[] = (array)$row;
            }
            $sections[$section] = $rows;
        }
        $this->reader->clearCache($gameId, $fileId);

        $snapshot = [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => (string)($manifest['file']['package_name'] ?? $registration['package_name'] ?? ''),
                'original_name' => (string)($manifest['file']['original_name'] ?? ''),
            ],
            'sections' => $sections,
        ];
        return Uedb5SqlProjectionBuilder::build($snapshot, $registration);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $kind = (int)($row['object_kind'] ?? 0);
            $index = (int)($row['object_index'] ?? -1);
            $key = $kind . ':' . $index;
            $public = $row['public_export_hash'] ?? null;
            $out[$key] = [
                'hash' => bin2hex((string)($row['object_name_hash'] ?? '')),
                'length' => (int)($row['object_name_length'] ?? 0),
                'public_export_hash' => $public === null ? null : bin2hex((string)$public),
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function actualRows(int $fileId): array
    {
        $statement = $this->db->prepare(
            'SELECT object_kind,object_index,object_name_hash,object_name_length,public_export_hash '
            . 'FROM ue_uedb5_object_candidates WHERE file_id=? ORDER BY object_kind,object_index'
        );
        $statement->execute([$fileId]);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $public = $row['public_export_hash'] ?? null;
            $rows[(int)$row['object_kind'] . ':' . (int)$row['object_index']] = [
                'hash' => bin2hex((string)$row['object_name_hash']),
                'length' => (int)$row['object_name_length'],
                'public_export_hash' => $public === null ? null : bin2hex((string)$public),
            ];
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    /** @param array<string,array<string,mixed>> $expected @param array<string,array<string,mixed>> $actual */
    private function diff(array $expected, array $actual): array
    {
        $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $stale = array_values(array_diff(array_keys($actual), array_keys($expected)));
        $changed = [];
        foreach (array_intersect(array_keys($expected), array_keys($actual)) as $key) {
            if ($expected[$key] !== $actual[$key]) { $changed[] = $key; }
        }
        return [
            'matches' => $missing === [] && $stale === [] && $changed === [],
            'missing_count' => count($missing),
            'stale_count' => count($stale),
            'changed_count' => count($changed),
            'missing_examples' => array_slice($missing, 0, 20),
            'stale_examples' => array_slice($stale, 0, 20),
            'changed_examples' => array_slice($changed, 0, 20),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function publishSearchDictionary(array $rows): void
    {
        if ($rows === []) { return; }
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_search_keys');
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT OR IGNORE INTO ' : 'INSERT IGNORE INTO ';
        foreach (array_chunk($rows, 250) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?)'));
            $params = [];
            foreach ($batch as $row) {
                array_push($params, $row['hash'], $row['length'], $row['fingerprint'], $row['normalized_text']);
            }
            $this->db->prepare(
                $verb . 'ue_uedb5_search_keys(key_hash,key_length,key_fingerprint,normalized_text) VALUES ' . $placeholders
            )->execute($params);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function replaceCandidates(int $fileId, array $rows): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_object_candidates');
        usort($rows, static fn(array $a, array $b): int =>
            [(int)$a['object_kind'], (int)$a['object_index']] <=> [(int)$b['object_kind'], (int)$b['object_index']]
        );
        $started = !$this->db->inTransaction();
        $maxAttempts = $started ? 5 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($started) { $this->db->beginTransaction(); }
            try {
                $this->db->prepare('DELETE FROM ue_uedb5_object_candidates WHERE file_id=?')->execute([$fileId]);
                foreach (array_chunk($rows, 250) as $batch) {
                    $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?)'));
                    $params = [];
                    foreach ($batch as $row) {
                        array_push($params, $row['file_id'], $row['object_kind'], $row['object_index'],
                            $row['object_name_hash'], $row['object_name_length'], $row['public_export_hash']);
                    }
                    $this->db->prepare(
                        'INSERT INTO ue_uedb5_object_candidates('
                        . 'file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash) VALUES '
                        . $placeholders
                    )->execute($params);
                }
                if ($started) { $this->db->commit(); }
                return;
            } catch (Throwable $error) {
                if ($started && $this->db->inTransaction()) { $this->db->rollBack(); }
                if (!$started || !PdoContention::retryable($error) || $attempt >= $maxAttempts) { throw $error; }
                usleep(PdoContention::backoffMicros($attempt, 25000));
            }
        }
        throw new \LogicException('UEDB5 object candidate contention retry loop exited unexpectedly.');
    }
}