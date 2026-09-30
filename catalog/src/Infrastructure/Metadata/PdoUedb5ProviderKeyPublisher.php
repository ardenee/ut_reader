<?php
/** Publishes only UEDB5 package provider keys, including catalogue aliases. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class PdoUedb5ProviderKeyPublisher
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function expectedRows(int $fileId): array
    {
        $statement = $this->db->prepare(
            'SELECT file_id,game_id,package_key_kind,package_key FROM ue_uedb5_files WHERE file_id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $registration = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($registration)) {
            throw new RuntimeException('Staged UEDB5 registration is missing for provider-key publication.');
        }
        $aliases = [];
        if ((int)$registration['package_key_kind'] === Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME) {
            $alias = $this->db->prepare(
                'SELECT id,file_id,game_id,package_name FROM ue_file_package_aliases '
                . 'WHERE file_id=? AND game_id=? ORDER BY id'
            );
            $alias->execute([$fileId, (int)$registration['game_id']]);
            $aliases = $alias->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return Uedb5ProviderKeyBuilder::build($registration, $aliases);
    }

    public function publish(int $fileId): int
    {
        if ($fileId < 1) { throw new RuntimeException('Positive file ID required.'); }
        $rows = $this->expectedRows($fileId);
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_provider_keys');
        $this->db->prepare('DELETE FROM ue_uedb5_provider_keys WHERE file_id=?')->execute([$fileId]);
        $insert = $this->db->prepare(
            'INSERT INTO ue_uedb5_provider_keys(source_kind,source_id,game_id,package_key_kind,package_key,file_id) '
            . 'VALUES(?,?,?,?,?,?)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                $row['source_kind'],$row['source_id'],$row['game_id'],
                $row['package_key_kind'],$row['package_key'],$row['file_id'],
            ]);
        }
        return count($rows);
    }
}
