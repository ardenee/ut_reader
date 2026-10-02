<?php
/** Persists and reconciles Step 8 per-file UEDB5 migration states. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;

final class PdoUedb5MigrationStatusRepository
{
    public function __construct(private readonly PDO $db) {}

    public function reconcileGame(int $gameId): void
    {
        if ($gameId < 1) {
            throw new RuntimeException('A positive game ID is required for UEDB5 status reconciliation.');
        }
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->db->prepare(
            'INSERT INTO ue_uedb5_migration_status(file_id,game_id,status,staged_at,created_at,updated_at) '
            . 'SELECT f.id,f.game_id,CASE WHEN v.file_id IS NULL THEN "pending" ELSE "staged" END,'
            . 'CASE WHEN v.file_id IS NULL THEN NULL ELSE ? END,?,? '
            . 'FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND s.file_id IS NULL'
        );
        $insert->execute([$now, $now, $now, $gameId]);

        $stage = $this->db->prepare(
            'UPDATE ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id '
            . 'SET s.status="staged",s.staged_at=COALESCE(s.staged_at,?),s.updated_at=? '
            . 'WHERE s.game_id=? AND s.status="pending"'
        );
        $stage->execute([$now, $now, $gameId]);

        $pending = $this->db->prepare(
            'UPDATE ue_uedb5_migration_status s LEFT JOIN ue_uedb5_files v ON v.file_id=s.file_id '
            . 'SET s.status="pending",s.validator_policy=NULL,s.last_checked_payload_sha256=NULL,'
            . 's.validated_payload_sha256=NULL,s.validated_at=NULL,s.failed_at=NULL,s.updated_at=? '
            . 'WHERE s.game_id=? AND v.file_id IS NULL AND s.status IN ("staged","validated")'
        );
        $pending->execute([$now, $gameId]);

        $stale = $this->db->prepare(
            'UPDATE ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id '
            . 'SET s.status="staged",s.validated_payload_sha256=NULL,s.validated_at=NULL,s.updated_at=? '
            . 'WHERE s.game_id=? AND s.status="validated" '
            . 'AND (s.validator_policy<>? OR s.validated_payload_sha256 IS NULL '
            . 'OR s.validated_payload_sha256<>v.payload_sha256)'
        );
        $stale->execute([$now, $gameId, Uedb5MigrationStatus::VALIDATOR_POLICY]);
    }

    /** @return array<string,int> */
    public function counts(int $gameId): array
    {
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) file_count FROM ue_uedb5_migration_status '
            . 'WHERE game_id=? GROUP BY status'
        );
        $statement->execute([$gameId]);
        $counts = array_fill_keys(Uedb5MigrationStatus::values(), 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['file_count'];
            }
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function batch(int $gameId, int $afterFileId, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $statement = $this->db->prepare(
            'SELECT s.file_id,s.game_id,s.status,s.attempt_count,v.payload_sha256 '
            . 'FROM ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id '
            . 'WHERE s.game_id=? AND s.file_id>? AND s.status IN ("staged","failed") '
            . 'ORDER BY s.file_id LIMIT ' . $limit
        );
        $statement->execute([$gameId, $afterFileId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markStageSucceeded(int $fileId, int $gameId): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_uedb5_migration_status(' .
            'file_id,game_id,status,attempt_count,staged_at,created_at,updated_at' .
            ') VALUES(?,?,"staged",0,?,?,?) ON DUPLICATE KEY UPDATE ' .
            'game_id=VALUES(game_id),status="staged",validator_policy=NULL,' .
            'last_checked_payload_sha256=NULL,validated_payload_sha256=NULL,dependency_policy=NULL,' .
            'dependency_payload_sha256=NULL,dependency_completed_at=NULL,' .
            'last_error_code=NULL,last_error_text=NULL,last_result_json=NULL,' .
            'staged_at=COALESCE(staged_at,VALUES(staged_at)),validated_at=NULL,failed_at=NULL,updated_at=VALUES(updated_at)'
        );
        $statement->execute([$fileId,$gameId,$now,$now,$now]);
    }

    public function markStageFailed(int $fileId, int $gameId, string $errorCode, string $errorText): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_uedb5_migration_status(' .
            'file_id,game_id,status,attempt_count,last_error_code,last_error_text,failed_at,created_at,updated_at' .
            ') VALUES(?, ?, "failed", 1, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ' .
            'game_id=VALUES(game_id),status="failed",validator_policy=NULL,last_checked_payload_sha256=NULL,' .
            'validated_payload_sha256=NULL,dependency_policy=NULL,dependency_payload_sha256=NULL,dependency_completed_at=NULL,' .
            'attempt_count=attempt_count+1,last_error_code=VALUES(last_error_code),' .
            'last_error_text=VALUES(last_error_text),last_result_json=NULL,validated_at=NULL,' .
            'failed_at=VALUES(failed_at),updated_at=VALUES(updated_at)'
        );
        $statement->execute([$fileId,$gameId,$errorCode,$errorText,$now,$now,$now]);
    }

    public function markDependencySucceeded(
        int $fileId,
        int $gameId,
        string $payloadSha256,
        string $policy
    ): void {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        if ($fileId < 1 || $gameId < 1 || strlen($payloadSha256) !== 32 || trim($policy) === '') {
            throw new RuntimeException('Invalid UEDB5 dependency-pass completion identity.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_uedb5_migration_status('
            . 'file_id,game_id,status,dependency_policy,dependency_payload_sha256,dependency_completed_at,staged_at,created_at,updated_at'
            . ') VALUES(?, ?, "staged", ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),status="staged",validator_policy=NULL,last_checked_payload_sha256=NULL,'
            . 'validated_payload_sha256=NULL,dependency_policy=VALUES(dependency_policy),'
            . 'dependency_payload_sha256=VALUES(dependency_payload_sha256),dependency_completed_at=VALUES(dependency_completed_at),'
            . 'last_error_code=NULL,last_error_text=NULL,last_result_json=NULL,validated_at=NULL,failed_at=NULL,updated_at=VALUES(updated_at)'
        );
        $statement->execute([$fileId,$gameId,$policy,$payloadSha256,$now,$now,$now,$now]);
    }

    public function markDependencyFailed(int $fileId, int $gameId, string $errorCode, string $errorText): void
    {
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_uedb5_migration_status(file_id,game_id,status,attempt_count,last_error_code,last_error_text,failed_at,created_at,updated_at) '
            . 'VALUES(?, ?, "failed", 1, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),status="failed",validator_policy=NULL,last_checked_payload_sha256=NULL,'
            . 'validated_payload_sha256=NULL,dependency_policy=NULL,dependency_payload_sha256=NULL,dependency_completed_at=NULL,'
            . 'attempt_count=attempt_count+1,last_error_code=VALUES(last_error_code),last_error_text=VALUES(last_error_text),'
            . 'last_result_json=NULL,validated_at=NULL,failed_at=VALUES(failed_at),updated_at=VALUES(updated_at)'
        );
        $statement->execute([$fileId,$gameId,$errorCode,$errorText,$now,$now,$now]);
    }
    /** @param array<string,mixed> $result */
    public function markValidated(int $fileId, string $payloadSha256, array $result): void
    {
        $this->writeResult($fileId, Uedb5MigrationStatus::VALIDATED, $payloadSha256, $result, null, null);
    }

    /** @param array<string,mixed> $result */
    public function markStaged(int $fileId, string $payloadSha256, array $result): void
    {
        $this->writeResult($fileId, Uedb5MigrationStatus::STAGED, $payloadSha256, $result, null, null);
    }

    /** @param array<string,mixed> $result */
    public function markFailed(
        int $fileId,
        string $payloadSha256,
        string $errorCode,
        string $errorText,
        array $result = []
    ): void {
        $this->writeResult(
            $fileId,
            Uedb5MigrationStatus::FAILED,
            $payloadSha256,
            $result,
            $errorCode,
            $errorText
        );
    }

    /** @param array<string,mixed> $result */
    private function writeResult(
        int $fileId,
        string $status,
        string $payloadSha256,
        array $result,
        ?string $errorCode,
        ?string $errorText
    ): void {
        Uedb5MigrationStatus::assert($status);
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
        try {
            $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode UEDB5 validation result.', 0, $error);
        }
        $now = gmdate('Y-m-d H:i:s');
        $validated = $status === Uedb5MigrationStatus::VALIDATED ? $now : null;
        $failed = $status === Uedb5MigrationStatus::FAILED ? $now : null;
        $statement = $this->db->prepare(
            'UPDATE ue_uedb5_migration_status SET status=?,validator_policy=?,'
            . 'last_checked_payload_sha256=?,validated_payload_sha256=?,attempt_count=attempt_count+1,'
            . 'last_error_code=?,last_error_text=?,last_result_json=?,validated_at=?,failed_at=?,updated_at=? '
            . 'WHERE file_id=?'
        );
        $statement->execute([
            $status,
            Uedb5MigrationStatus::VALIDATOR_POLICY,
            $payloadSha256,
            $status === Uedb5MigrationStatus::VALIDATED ? $payloadSha256 : null,
            $errorCode,
            $errorText,
            $json,
            $validated,
            $failed,
            $now,
            $fileId,
        ]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('UEDB5 migration status row is missing for file #' . $fileId . '.');
        }
    }
}
