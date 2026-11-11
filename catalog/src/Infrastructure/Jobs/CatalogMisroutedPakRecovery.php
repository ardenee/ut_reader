<?php
/**
 * Recover a completed UnrealPak-format failure that was actually a UE3 package.
 *
 * Never replay the original type/game: preserve its failure, resolve exact
 * verified duplicates in the correct game, or enqueue a separate UE3 package
 * import against the retained immutable source. No extension-based guessing.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogLegacyPakPackagePolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadDuplicateDetector;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogMisroutedPakRecovery
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
    }

    /** @return array<string,mixed> */
    public function preview(int $jobId, string $queueName = 'catalog'): array
    {
        if ($jobId < 1 || !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $queueName)) {
            throw new RuntimeException('Invalid background job or queue.');
        }
        $statement = $this->db->prepare(
            'SELECT id,job_type,queue_name,status,display_status,payload_json,result_json,progress_json '
            . 'FROM ue_background_jobs WHERE id=? AND queue_name=? LIMIT 1'
        );
        $statement->execute([$jobId, $queueName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Background job is no longer available.');
        }
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        if (!is_array($payload) || !is_array($result)
            || (string)$row['job_type'] !== JobType::IMPORT_STAGED_PAK
            || (string)$row['status'] !== 'completed'
            || (string)$row['display_status'] !== 'failed') {
            throw new RuntimeException('Only failed, completed UnrealPak import jobs can be recovered.');
        }
        $reason = strtolower((string)($result['message'] ?? ''));
        if (!str_contains($reason, 'no unreal pak footer was found')
            && !str_contains($reason, 'no unreal pak magic footer was found')) {
            throw new RuntimeException('The failure is not a recognisable wrong-engine UnrealPak routing error.');
        }
        $originalName = trim((string)($payload['original_name'] ?? ''));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new RuntimeException('The original source is not a .pak-named file.');
        }
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        if ($stagedPath === '') {
            throw new RuntimeException('No retained staged source is associated with this job.');
        }
        $sourcePath = (new CatalogIncomingFileStore($this->config))->resolve($stagedPath);
        CatalogLegacyPakPackagePolicy::assertPackageHeader($sourcePath);
        $bytes = (int)(filesize($sourcePath) ?: 0);
        if ($bytes !== (int)($payload['size'] ?? 0) || $bytes < 8) {
            throw new RuntimeException('Retained source size differs from the original job.');
        }
        $header = @file_get_contents($sourcePath, false, null, 0, 8);
        if (!is_string($header) || strlen($header) !== 8) {
            throw new RuntimeException('Could not inspect retained UE3 package header.');
        }
        $version = unpack('v', substr($header, 4, 2))[1];
        // Header version alone is not game proof: only use the sole compatible,
        // active profile and additionally exercise its real package reader.
        $games = \catalog_all(
            $this->db,
            'SELECT g.id,g.name FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE p.is_active=1 AND p.engine_key="UE3" '
            . 'AND (p.package_version_min IS NULL OR p.package_version_min<=?) '
            . 'AND (p.package_version_max IS NULL OR p.package_version_max>=?) ORDER BY g.id LIMIT 3',
            [$version, $version]
        );
        if (count($games) !== 1) {
            throw new RuntimeException('UE3 package target is ambiguous. Choose a game explicitly rather than inferring it from the filename.');
        }
        $targetId = (int)$games[0]['id'];
        if ($targetId === (int)($payload['game_id'] ?? 0)) {
            throw new RuntimeException('The original job already targeted the same UE3 game.');
        }
        $logicalPath = trim((string)($payload['source_relative_path'] ?? $originalName));
        $inspection = (new CatalogVerifiedPackageInspector($this->db, $this->config))
            ->inspect($targetId, $sourcePath, $originalName, true, $logicalPath);
        if ($inspection->readerEngine !== 'UE3'
            || !$inspection->classification['ok_for_selected_game']) {
            throw new RuntimeException('The selected UE3 reader did not accept this source under the game profile.');
        }
        $sha1 = strtolower((string)$inspection->sha1);
        $md5 = strtolower((string)$inspection->md5);
        $match = (new CatalogUploadDuplicateDetector($this->db, $this->config))
            ->inspect($bytes, $md5, $sha1);
        $duplicate = is_array($match['duplicate'] ?? null) ? $match['duplicate'] : null;
        $existingFileId = $duplicate !== null
            && (int)($duplicate['game_id'] ?? 0) === $targetId
            && (string)($duplicate['scan_status'] ?? '') === 'verified'
            ? (int)$duplicate['file_id'] : 0;

        return [
            'source_job_id' => $jobId,
            'original_game_id' => (int)($payload['game_id'] ?? 0),
            'target_game_id' => $targetId,
            'target_game_name' => (string)$games[0]['name'],
            'reader_engine' => 'UE3',
            'package_version' => $version,
            'original_name' => $originalName,
            'source_relative_path' => $logicalPath,
            'staged_path' => $stagedPath,
            'bytes' => $bytes,
            'md5' => $md5,
            'sha1' => $sha1,
            'existing_verified_file_id' => $existingFileId,
            'action' => $existingFileId > 0 ? 'already_verified' : 'enqueue_correct_reader',
            'original_result' => $result,
        ];
    }

    /** @return array<string,mixed> */
    public function recover(int $jobId, string $queueName, int $adminId): array
    {
        if ($adminId < 1) {
            throw new RuntimeException('Administrator identity is required.');
        }
        $preview = $this->preview($jobId, $queueName);
        $fileId = (int)$preview['existing_verified_file_id'];
        $targetId = (int)$preview['target_game_id'];
        $newJobId = 0;
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare(
                'SELECT status,display_status,job_type,payload_json,result_json,progress_json FROM ue_background_jobs '
                . 'WHERE id=? AND queue_name=? FOR UPDATE'
            );
            $lock->execute([$jobId, $queueName]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || (string)$row['status'] !== 'completed'
                || (string)$row['display_status'] !== 'failed'
                || (string)$row['job_type'] !== JobType::IMPORT_STAGED_PAK) {
                throw new RuntimeException('The source job has changed. Refresh Background Jobs before recovering it.');
            }
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)
                || (string)($payload['staged_path'] ?? '') !== $preview['staged_path']
                || (int)($payload['game_id'] ?? 0) !== $preview['original_game_id']) {
                throw new RuntimeException('The original job source or target changed; recovery is not safe.');
            }
            if ($fileId === 0) {
                $corrected = [
                    'game_id' => $targetId,
                    'staged_path' => $preview['staged_path'],
                    'original_name' => $preview['original_name'],
                    'source_relative_path' => $preview['source_relative_path'],
                    'strict_profile' => true,
                    'user_id' => $adminId,
                    'size' => $preview['bytes'],
                    'recovered_from_job_id' => $jobId,
                    'sha1' => $preview['sha1'],
                ];
                $dedupeKey = 'recover-pak-to-ue3:' . hash('sha256', $queueName . ':' . $jobId);
                $newJobId = (new PdoJobQueue($this->db))->enqueue(
                    $queueName,
                    JobType::IMPORT_STAGED_PACKAGE,
                    $corrected,
                    5,
                    null,
                    $dedupeKey,
                    $adminId,
                    3
                );
            }
            $message = $fileId > 0
                ? 'Resolved: these bytes are already verified as ' . $preview['target_game_name']
                    . ' catalogue file #' . $fileId . '.'
                : 'Wrong-engine PAK job superseded by UE3 package import job #' . $newJobId . '.';
            $resolution = [
                'operation' => 'recover_misrouted_pak',
                'status' => $fileId > 0 ? 'duplicate' : 'redirected',
                'message' => $message,
                'original_failure_result' => json_decode((string)$row['result_json'], true),
                'original_failure_progress' => json_decode((string)$row['progress_json'], true),
                'original_game_id' => $preview['original_game_id'],
                'target_game_id' => $targetId,
                'file_id' => $fileId,
                'recovery_job_id' => $newJobId,
                'original_source_name' => $preview['original_name'],
                'source_sha1' => $preview['sha1'],
                'resolved_by' => $adminId,
                'resolved_at' => gmdate('c'),
            ];
            $progress = [
                'stage' => 'recovered',
                'status' => $resolution['status'],
                'percent' => 100,
                'message' => $message,
            ];
            $update = $this->db->prepare(
                'UPDATE ue_background_jobs SET result_json=?,progress_json=?,updated_at=UTC_TIMESTAMP() '
                . 'WHERE id=? AND queue_name=? AND status="completed" AND display_status="failed"'
            );
            $update->execute([
                json_encode($resolution, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                json_encode($progress, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                $jobId, $queueName
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not atomically mark the previous failure as recovered.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return [
            'source_job_id' => $jobId,
            'status' => $fileId > 0 ? 'already_verified' : 'requeued_with_correct_reader',
            'target_game_id' => $targetId,
            'target_game_name' => $preview['target_game_name'],
            'file_id' => $fileId,
            'new_job_id' => $newJobId,
            'old_failure_preserved' => true,
        ];
    }
}
