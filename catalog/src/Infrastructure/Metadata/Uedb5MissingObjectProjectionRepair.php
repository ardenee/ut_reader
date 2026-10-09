<?php
/** Repair a completely absent V5 object-candidate projection from authoritative V5 bytes. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class Uedb5MissingObjectProjectionRepair
{
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /** @return array<string,mixed> */
    public function repairFile(int $fileId): array
    {
        if ($fileId < 1) { throw new RuntimeException('Positive file ID required.'); }
        $q = $this->db->prepare(
            'SELECT v.file_id,v.game_id,v.package_key_kind,v.package_key,g.slug,s.status '
            . 'FROM ue_uedb5_files v '
            . 'JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=v.game_id '
            . 'JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id '
            . 'WHERE v.file_id=? LIMIT 1'
        );
        $q->execute([$fileId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string)$row['status'], ['staged','failed'], true)) {
            throw new RuntimeException('File must have a verified V5 registration and staged/failed validation status.');
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_uedb5_object_candidates WHERE file_id=?'
        );
        $count->execute([$fileId]);
        $present = (int)$count->fetchColumn();
        if ($present !== 0) {
            throw new RuntimeException('Object rows already exist: nonempty projection mismatches require separate diagnosis.');
        }
        $gameId = (int)$row['game_id'];
        $reader = new Uedb5MetadataReader((string)$this->config['storage_path']);
        $snapshot = $reader->snapshot($gameId, $fileId);
        $expected = Uedb5SqlProjectionBuilder::build($snapshot, $row);
        $expectedCount = count((array)$expected['object_candidates']);
        if ($expectedCount === 0) {
            throw new RuntimeException('Source V5 snapshot has no object candidates; refusing empty repair.');
        }
        $published = (new PdoUedb5BaseProjectionPublisher($this->db))->publish($snapshot, $row);
        if ((int)$published['object_candidates'] !== $expectedCount) {
            throw new RuntimeException('Published object count differs from authoritative V5 projection.');
        }
        $pass2 = (new Uedb5GameDependencyPassService($this->db, $this->config))
            ->runFile($gameId, $fileId, true, true);
        $validated = (new Uedb5MigrationValidationService($this->db, $this->config))
            ->validateFile((string)$row['slug'], $fileId);
        if (($validated['status'] ?? null) !== 'validated') {
            throw new RuntimeException('V5 Step 8 did not validate the repaired projection.');
        }
        return [
            'file_id'=>$fileId,'game'=>(string)$row['slug'],'object_candidates'=>$expectedCount,
            'dependency_result'=>$pass2['result'],'status'=>'validated',
        ];
    }
}
