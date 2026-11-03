<?php
/**
 * Opt-in compact V5 candidate publisher for the v2 relational table contract.
 * NOT wired into the active V5 publisher or search paths.
 *
 * Production mode requires tables to be provisioned separately; test mode
 * targets only session TEMPORARY tables with the exact v2 column layout.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class PdoUedb5CompactCandidatePublisher
{
    private string $dictionary;
    private string $names;
    private string $objects;

    public function __construct(private readonly PDO $db, bool $temporaryTestMode = true)
    {
        if ($temporaryTestMode) {
            $this->dictionary = 'u5_v2_test_dictionary';
            $this->names = 'u5_v2_test_names';
            $this->objects = 'u5_v2_test_objects';
        } else {
            $this->dictionary = 'ue_uedb5_compact_name_dictionary';
            $this->names = 'ue_uedb5_compact_name_candidates';
            $this->objects = 'ue_uedb5_compact_object_candidates';
            foreach ([$this->dictionary, $this->names, $this->objects] as $table) {
                Uedb5StagingIsolationContract::assertWriteTable($table);
            }
        }
    }

    /**
     * Caller can supply an outer transaction. Only source-backed names are
     * accepted. Dictionary IDs come from an exact SHA-256 unique index.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $registration
     * @return array{file_id:int,names:int,objects:int,dictionary_keys:int}
     */
    public function publish(array $snapshot, array $registration): array
    {
        $fileId = (int)($snapshot['file']['id'] ?? 0);
        if ($fileId <= 0) throw new RuntimeException('Missing V5 file identity.');
        $projection = Uedb5SqlProjectionBuilder::build($snapshot, $registration);

        // An explicit caller-managed transaction is supported; never commit it.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            // Preserve the canonical fingerprint lock order, but issue one
            // bounded INSERT IGNORE + SELECT per batch instead of two MySQL
            // round-trips for every single source-backed name.
            $keys = array_values((array)($projection['search_keys'] ?? []));
            usort($keys, static fn(array $a, array $b): int =>
                strcmp((string)($a['fingerprint'] ?? ''), (string)($b['fingerprint'] ?? '')));
            $dictionary = [];
            foreach (array_chunk($keys, 200) as $batch) {
                $values = [];
                $params = [];
                $fingerprints = [];
                $identities = [];
                foreach ($batch as $key) {
                    $hash = (string)($key['hash'] ?? '');
                    $len = (int)($key['length'] ?? -1);
                    $fp = (string)($key['fingerprint'] ?? '');
                    $text = (string)($key['normalized_text'] ?? '');
                    if (strlen($hash) !== 16 || strlen($fp) !== 32
                        || strlen($text) !== $len
                        || !hash_equals(md5($text, true), $hash)
                        || !hash_equals(hash('sha256', $text, true), $fp)) {
                        throw new RuntimeException('Invalid source search identity.');
                    }
                    $values[] = '(?,?,?)';
                    array_push($params, $hash, $len, $fp);
                    $fingerprints[] = $fp;
                    $identities[bin2hex($fp)] = [$hash, $len, $text];
                }
                // Ignore only the exact unique fingerprint already in the
                // dictionary. The following SELECT checks every returned
                // identity, so a hash collision cannot be accepted silently.
                $this->db->prepare(
                    'INSERT IGNORE INTO '.$this->dictionary.
                    ' (name_key_hash,name_key_length,name_key_fingerprint) VALUES '.implode(',', $values)
                )->execute($params);
                $stmt = $this->db->prepare(
                    'SELECT name_id,name_key_hash,name_key_length,name_key_fingerprint FROM '.
                    $this->dictionary.' WHERE name_key_fingerprint IN ('.
                    implode(',', array_fill(0, count($fingerprints), '?')).')'
                );
                $stmt->execute($fingerprints);
                $found = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
                    $hex = bin2hex((string)$entry['name_key_fingerprint']);
                    $expected = $identities[$hex] ?? null;
                    if ($expected === null || (int)$entry['name_id'] <= 0
                        || !hash_equals((string)$entry['name_key_hash'], $expected[0])
                        || (int)$entry['name_key_length'] !== $expected[1]) {
                        throw new RuntimeException('Compact dictionary identity conflict.');
                    }
                    $found[$hex] = (int)$entry['name_id'];
                }
                if (count($found) !== count($identities)) {
                    throw new RuntimeException('Compact dictionary identity lookup incomplete.');
                }
                foreach ($identities as $hex => $identity) {
                    $dictionary[$hex] = [
                        'name_id' => $found[$hex],
                        'key_hash' => $identity[0],
                        'key_length' => $identity[1],
                        'key_fingerprint' => hex2bin($hex),
                        'normalized_text' => $identity[2],
                    ];
                }
            }
            $compact = Uedb5CompactCandidateProjectionBuilder::build(
                $snapshot, $projection, array_values($dictionary)
            );
            $deleteNames = $this->db->prepare('DELETE FROM '.$this->names.' WHERE file_id=?');
            $deleteObjects = $this->db->prepare('DELETE FROM '.$this->objects.' WHERE file_id=?');
            $deleteNames->execute([$fileId]);
            $deleteObjects->execute([$fileId]);
            // Keep the same file-scoped delete/insert semantics and primary-key
            // constraints while reducing large candidate lists to bounded SQL
            // batches. The transaction remains caller-owned as before.
            foreach (array_chunk($compact['name_candidates'], 200) as $batch) {
                $params = [];
                foreach ($batch as $row) {
                    array_push($params, $fileId, $row['name_id'], $row['first_name_index']);
                }
                $sql = 'INSERT INTO '.$this->names.
                    ' (file_id,name_id,first_name_index) VALUES '.
                    implode(',', array_fill(0, count($batch), '(?,?,?)'));
                $this->db->prepare($sql)->execute($params);
            }
            foreach (array_chunk($compact['object_candidates'], 200) as $batch) {
                $params = [];
                foreach ($batch as $row) {
                    array_push($params, $fileId, $row['object_kind'], $row['object_index'],
                        $row['name_id'], $row['public_export_hash']);
                }
                $sql = 'INSERT INTO '.$this->objects.
                    ' (file_id,object_kind,object_index,name_id,public_export_hash) VALUES '.
                    implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));
                $this->db->prepare($sql)->execute($params);
            }
            if ($ownsTransaction) $this->db->commit();
            return ['file_id'=>$fileId,'names'=>count($compact['name_candidates']),
                'objects'=>count($compact['object_candidates']),
                'dictionary_keys'=>count($dictionary)];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteFile(int $fileId): void
    {
        if ($fileId < 1) throw new RuntimeException('Invalid file ID.');
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            foreach ([$this->names, $this->objects] as $table) {
                $stmt = $this->db->prepare('DELETE FROM '.$table.' WHERE file_id=?');
                $stmt->execute([$fileId]);
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{names:int,objects:int} */
    public function counts(int $fileId): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM '.$this->names.' WHERE file_id=?');
        $stmt->execute([$fileId]); $names = (int)$stmt->fetchColumn();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM '.$this->objects.' WHERE file_id=?');
        $stmt->execute([$fileId]);
        return ['names'=>$names,'objects'=>(int)$stmt->fetchColumn()];
    }
}
