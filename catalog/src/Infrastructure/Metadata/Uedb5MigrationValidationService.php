<?php
/** Resumable Step 8 validation orchestration and durable status transitions. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5MigrationValidationService
{
    private PdoUedb5MigrationStatusRepository $statuses;
    private Uedb5MigrationValidator $validator;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, array $config)
    {
        $this->statuses = new PdoUedb5MigrationStatusRepository($db);
        $this->validator = new Uedb5MigrationValidator($db, $config);
    }

    /** @return array<string,mixed> */
    public function preflight(string $gameSlug): array
    {
        $game = $this->game($gameSlug);
        $this->requireTable('ue_uedb5_migration_status');
        $this->requireTable('ue_uedb5_files');
        $verified = $this->count('SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified"', [(int)$game['id']]);
        $staged = $this->count('SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?', [(int)$game['id']]);
        return [
            'game' => $game,
            'verified_count' => $verified,
            'staged_registration_count' => $staged,
            'status_counts' => $this->statuses->counts((int)$game['id']),
            'validator_policy' => Uedb5MigrationStatus::VALIDATOR_POLICY,
        ];
    }

    /** Validate exactly one registered V5 file and persist Step 8 evidence. */
    public function validateFile(string $gameSlug, int $fileId, ?array $previouslyVerifiedSourceSnapshot = null): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        $game = $this->game($gameSlug);
        $statement = $this->db->prepare(
            'SELECT s.file_id,s.game_id,s.status,v.payload_sha256 '
            . 'FROM ue_uedb5_migration_status s '
            . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
            . 'JOIN ue_files f ON f.id=s.file_id '
            . 'WHERE s.file_id=? AND s.game_id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId, (int)$game['id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string)$row['status'], [
            Uedb5MigrationStatus::STAGED, Uedb5MigrationStatus::FAILED,
        ], true)) {
            throw new RuntimeException('The requested file is not staged for Step 8 validation in this game.');
        }
        $payload = (string)$row['payload_sha256'];
        try {
            $result = $this->validator->validate($fileId, $previouslyVerifiedSourceSnapshot);
            if (!empty($result['ready'])) {
                $this->statuses->markValidated($fileId, $payload, $result);
                $state = Uedb5MigrationStatus::VALIDATED;
            } else {
                $this->statuses->markStaged($fileId, $payload, $result);
                $state = Uedb5MigrationStatus::STAGED;
            }
            return ['file_id'=>$fileId,'game'=>$game,'status'=>$state,'result'=>$result];
        } catch (Throwable $error) {
            $code = $error instanceof Uedb5ValidationException
                ? $error->reasonCode : 'validator_exception';
            $this->statuses->markFailed($fileId, $payload, $code, $error->getMessage());
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function validateGame(
        string $gameSlug,
        int $limit = 500,
        bool $continuous = false,
        int $progressEvery = 100,
        ?callable $emit = null
    ): array {
        $game = $this->game($gameSlug);
        $this->requireTable('ue_uedb5_migration_status');
        $this->statuses->reconcileGame((int)$game['id']);
        $initial = $this->statuses->counts((int)$game['id']);
        $limit = max(1, min(5000, $limit));
        $progressEvery = max(1, $progressEvery);
        $cursor = 0;
        $processed = $validated = $staged = $failed = 0;
        $failures = [];
        do {
            $rows = $this->statuses->batch((int)$game['id'], $cursor, $limit);
            if ($rows === []) { break; }
            foreach ($rows as $row) {
                $fileId = (int)$row['file_id'];
                $cursor = $fileId;
                $processed++;
                $payloadSha = (string)$row['payload_sha256'];
                try {
                    $result = $this->validator->validate($fileId);
                    if (!empty($result['ready'])) {
                        $this->statuses->markValidated($fileId, $payloadSha, $result);
                        $validated++;
                        $state = Uedb5MigrationStatus::VALIDATED;
                    } else {
                        $this->statuses->markStaged($fileId, $payloadSha, $result);
                        $staged++;
                        $state = Uedb5MigrationStatus::STAGED;
                    }
                    if ($emit && ($processed % $progressEvery === 0 || !$continuous)) {
                        $emit(['status'=>$state,'processed'=>$processed,'file_id'=>$fileId,'result'=>$result]);
                    }
                } catch (Throwable $error) {
                    $failed++;
                    $code = $error instanceof Uedb5ValidationException ? $error->reasonCode : 'validator_exception';
                    $this->statuses->markFailed($fileId, $payloadSha, $code, $error->getMessage());
                    if (count($failures) < 50) {
                        $failures[] = ['file_id'=>$fileId,'code'=>$code,'error'=>$error->getMessage()];
                    }
                    if ($emit) {
                        $emit(['status'=>'failed','processed'=>$processed,'file_id'=>$fileId,'code'=>$code,'error'=>$error->getMessage()]);
                    }
                }
            }
        } while ($continuous && count($rows) === $limit);
        return [
            'game' => $game,
            'initial_status_counts' => $initial,
            'final_status_counts' => $this->statuses->counts((int)$game['id']),
            'processed' => $processed,
            'validated' => $validated,
            'staged_not_ready' => $staged,
            'failed' => $failed,
            'last_file_id' => $cursor,
            'failures' => $failures,
        ];
    }

    /** @return array<string,mixed> */
    public function syncOnly(string $gameSlug): array
    {
        $game = $this->game($gameSlug);
        $this->requireTable('ue_uedb5_migration_status');
        $this->statuses->reconcileGame((int)$game['id']);
        return ['game'=>$game,'status_counts'=>$this->statuses->counts((int)$game['id'])];
    }

    /** @return array<string,mixed> */
    private function game(string $slug): array
    {
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1');
        $statement->execute([trim($slug)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new RuntimeException('Unknown game slug: ' . $slug); }
        return $row;
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return (int)$statement->fetchColumn();
    }

    private function requireTable(string $table): void
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        if ((int)$statement->fetchColumn() < 1) {
            throw new RuntimeException('Required Step 8 table is missing: ' . $table);
        }
    }
}
