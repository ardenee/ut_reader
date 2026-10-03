<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

final class PdoUedb5NameCandidateSynchronizer
{
    private Uedb5MetadataReader $reader;

    public function __construct(
        private readonly PDO $db,
        string $storageRoot
    ) {
        $storageRoot = rtrim($storageRoot, "\\/");
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage path is required for UEDB5 name sync.');
        }
        $this->reader = new Uedb5MetadataReader($storageRoot);
    }

    /** @return array<string,mixed> */
    public function reconcile(int $fileId, bool $apply): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required for UEDB5 name sync.');
        }
        $registration = $this->registration($fileId);
        $projection = $this->expectedProjection($registration);
        $expected = $this->normalizeExpected((array)$projection['name_candidates']);
        $actual = $this->actualCandidates($fileId);
        $diff = $this->diff($expected, $actual, (array)$projection['search_keys']);

        if (!$diff['matches'] && $apply) {
            $this->publishSearchDictionary((array)$projection['search_keys']);
            $this->replaceCandidates($fileId, (array)$projection['name_candidates']);
            $actual = $this->actualCandidates($fileId);
            $post = $this->diff($expected, $actual, (array)$projection['search_keys']);
            if (!$post['matches']) {
                throw new RuntimeException('UEDB5 name candidate projection still differs after repair for file #' . $fileId . '.');
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
        $sections = (array)($manifest['sections'] ?? []);
        $section = array_key_exists('names', $sections)
            ? 'names'
            : (array_key_exists('name_map', $sections) ? 'name_map' : null);
        $names = [];
        if ($section !== null) {
            foreach ($this->reader->scan($gameId, $fileId, $section) as $row) {
                $names[] = (array)$row;
            }
        }
        $this->reader->clearCache($gameId, $fileId);

        $snapshot = [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => (string)($manifest['file']['package_name'] ?? $registration['package_name'] ?? ''),
                'original_name' => (string)($manifest['file']['original_name'] ?? ''),
            ],
            'sections' => [($section ?? 'names') => $names],
        ];
        return Uedb5SqlProjectionBuilder::build($snapshot, $registration);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function normalizeExpected(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $fingerprint = (string)($row['name_key_fingerprint'] ?? '');
            if (strlen($fingerprint) !== 32) {
                throw new RuntimeException('UEDB5 name candidate has an invalid fingerprint.');
            }
            $out[bin2hex($fingerprint)] = [
                'hash' => bin2hex((string)$row['name_key_hash']),
                'length' => (int)$row['name_key_length'],
                'index' => (int)$row['first_name_index'],
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function actualCandidates(int $fileId): array
    {
        $statement = $this->db->prepare(
            'SELECT name_key_hash,name_key_length,name_key_fingerprint,first_name_index '
            . 'FROM ue_uedb5_name_candidates WHERE file_id=? ORDER BY name_key_fingerprint'
        );
        $statement->execute([$fileId]);
        $out = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fingerprint = (string)$row['name_key_fingerprint'];
            $out[bin2hex($fingerprint)] = [
                'hash' => bin2hex((string)$row['name_key_hash']),
                'length' => (int)$row['name_key_length'],
                'index' => (int)$row['first_name_index'],
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,array<string,mixed>> $expected @param array<string,array<string,mixed>> $actual */
    private function diff(array $expected, array $actual, array $searchRows): array
    {
        $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $stale = array_values(array_diff(array_keys($actual), array_keys($expected)));
        $changed = [];
        foreach (array_intersect(array_keys($expected), array_keys($actual)) as $key) {
            if ($expected[$key] !== $actual[$key]) { $changed[] = $key; }
        }

        $textByFingerprint = [];
        foreach ($searchRows as $row) {
            $fingerprint = (string)($row['fingerprint'] ?? '');
            if (strlen($fingerprint) === 32) {
                $textByFingerprint[bin2hex($fingerprint)] = (string)($row['normalized_text'] ?? '');
            }
        }
        $examples = static function(array $keys) use ($textByFingerprint): array {
            $out = [];
            foreach (array_slice($keys, 0, 20) as $key) {
                $out[] = ['fingerprint' => $key, 'text' => $textByFingerprint[$key] ?? null];
            }
            return $out;
        };
        return [
            'matches' => $missing === [] && $stale === [] && $changed === [],
            'missing_count' => count($missing),
            'stale_count' => count($stale),
            'changed_count' => count($changed),
            'missing_examples' => $examples($missing),
            'stale_examples' => $examples($stale),
            'changed_examples' => $examples($changed),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function publishSearchDictionary(array $rows): void
    {
        if ($rows === []) { return; }
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_search_keys');
        usort($rows, static fn(array $a, array $b): int =>
            strcmp(bin2hex((string)$a['fingerprint']), bin2hex((string)$b['fingerprint']))
        );
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT OR IGNORE INTO '
            : 'INSERT IGNORE INTO ';
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
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_name_candidates');
        usort($rows, static fn(array $a, array $b): int =>
            strcmp(bin2hex((string)$a['name_key_fingerprint']), bin2hex((string)$b['name_key_fingerprint']))
        );

        $started = !$this->db->inTransaction();
        $maxAttempts = $started ? 5 : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($started) { $this->db->beginTransaction(); }
            try {
                $this->db->prepare('DELETE FROM ue_uedb5_name_candidates WHERE file_id=?')->execute([$fileId]);
                foreach (array_chunk($rows, 250) as $batch) {
                    $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
                    $params = [];
                    foreach ($batch as $row) {
                        array_push($params,
                            $row['file_id'], $row['name_key_hash'], $row['name_key_length'],
                            $row['name_key_fingerprint'], $row['first_name_index']
                        );
                    }
                    $this->db->prepare(
                        'INSERT INTO ue_uedb5_name_candidates('
                        . 'file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index) VALUES '
                        . $placeholders
                    )->execute($params);
                }
                if ($started) { $this->db->commit(); }
                return;
            } catch (Throwable $error) {
                if ($started && $this->db->inTransaction()) { $this->db->rollBack(); }
                if (!$started || !PdoContention::retryable($error) || $attempt >= $maxAttempts) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 25000));
            }
        }
        throw new \LogicException('UEDB5 name candidate contention retry loop exited unexpectedly.');
    }
}
