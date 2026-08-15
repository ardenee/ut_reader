<?php
/**
 * Expands uploaded ZIP/7z/RAR containers into ordinary durable Unreal import jobs.
 *
 * The archive job only owns unpacking. Every supported member is copied into
 * controlled incoming storage and queued through the existing package/PAK or
 * Upload Bucket processing path. A bad member is recorded and skipped without
 * preventing unrelated members from being queued.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogArchiveImportJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const ERROR_RETENTION = 50;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::IMPORT_STAGED_ARCHIVE, JobType::PROCESS_BUCKET_ARCHIVE], true);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $profiled = $job->type === JobType::IMPORT_STAGED_ARCHIVE;
        $payload = $job->payload;
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? 'archive.bin'));
        $sourceRelativePath = CatalogImportPathPolicy::relative(
            (string)($payload['source_relative_path'] ?? $originalName)
        );
        $userId = (int)($payload['user_id'] ?? 0);
        $gameId = $profiled ? (int)($payload['game_id'] ?? 0) : 0;
        $strictProfile = (bool)($payload['strict_profile'] ?? true);
        if ($stagedPath === '' || $userId < 1 || ($profiled && $gameId < 1)) {
            throw new \InvalidArgumentException('Archive import job payload is incomplete.');
        }
        if (!CatalogArchiveExtractor::isArchiveName($originalName)) {
            throw new \InvalidArgumentException('Archive job source is not a supported ZIP/7z/RAR file.');
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        $sourcePath = $incoming->resolve($stagedPath);
        $extractor = new CatalogArchiveExtractor($this->config);
        $entries = $extractor->entries($sourcePath, $originalName);
        $allowed = $profiled ? $this->profiledExtensions($gameId) : $this->bucketExtensions();

        $resume = $context->resumeProgress();
        if ((int)($resume['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION) {
            $resume = [];
        }
        $cursor = max(0, (int)($resume['entry_cursor'] ?? 0));
        $queued = max(0, (int)($resume['queued'] ?? 0));
        $skipped = max(0, (int)($resume['skipped'] ?? 0));
        $failed = max(0, (int)($resume['failed'] ?? 0));
        $unpackedBytes = max(0, (int)($resume['unpacked_bytes'] ?? 0));
        $errors = is_array($resume['errors'] ?? null) ? array_values($resume['errors']) : [];
        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queue = new PdoJobQueue($this->db);
        $total = count($entries);
        $maxTotalBytes = $this->maxTotalUnpackedBytes();

        for ($index = $cursor; $index < $total; $index++) {
            $context->throwIfCancellationRequested();
            $entry = $entries[$index];
            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            $entryName = CatalogImportPathPolicy::filename(basename($entryPath));
            $extension = strtolower((string)pathinfo($entryName, PATHINFO_EXTENSION));
            $reason = '';

            if (empty($entry['safe'])) {
                $reason = 'Unsafe archive path: ' . trim((string)($entry['reason'] ?? 'invalid member path'));
            } elseif (!empty($entry['encrypted'])) {
                $reason = 'Encrypted/password-protected archive member is not supported.';
            } elseif (!isset($allowed[$extension])) {
                $skipped++;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Skipped unsupported archive member ' . $entryPath . '.');
                continue;
            } elseif (CatalogArchiveExtractor::isArchiveName($entryName)) {
                $skipped++;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Skipped nested archive ' . $entryPath . '.');
                continue;
            }

            if ($reason !== '') {
                $failed++;
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors, $reason);
                continue;
            }

            $entryBytes = max(0, (int)($entry['size'] ?? 0));
            $entryLimit = $extension === 'pak' ? $this->containerLimitBytes() : $this->normalLimitBytes();
            if ($entryBytes < 1 || $entryBytes > $entryLimit) {
                $failed++;
                $reason = 'Archive member ' . $entryPath . ' exceeds its configured import limit.';
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors, $reason);
                continue;
            }
            if ($entryBytes > $maxTotalBytes - $unpackedBytes) {
                throw new \RuntimeException(
                    'Archive expansion exceeds the configured total unpacked-data limit of '
                    . number_format($maxTotalBytes) . ' bytes.'
                );
            }

            $dedupeKey = 'archive-entry:' . $job->id . ':' . hash('sha256', strtolower($entryPath));
            $existing = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
            );
            $existing->execute([$queueName, $dedupeKey]);
            if ((int)($existing->fetchColumn() ?: 0) > 0) {
                $queued++;
                $unpackedBytes += $entryBytes;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Reused already queued archive member ' . $entryPath . '.');
                continue;
            }

            $temporary = '';
            $staged = null;
            try {
                $temporary = $extractor->extractToTemp($sourcePath, $originalName, $entry, $entryLimit);
                $staged = $incoming->stageLocalFile($temporary, $entryName);
                @unlink($temporary);
                $temporary = '';

                $memberRelativePath = CatalogImportPathPolicy::relative($sourceRelativePath . '/' . $entryPath);
                $childType = $profiled
                    ? ($extension === 'pak' ? JobType::IMPORT_STAGED_PAK : JobType::IMPORT_STAGED_PACKAGE)
                    : JobType::PROCESS_BUCKET_STAGED_PACKAGE;
                $childPayload = [
                    'staged_path' => (string)$staged['relative_path'],
                    'original_name' => $entryName,
                    'source_relative_path' => $memberRelativePath,
                    'user_id' => $userId,
                    'size' => (int)$staged['size'],
                    'sha256' => (string)$staged['sha256'],
                    'archive_parent_job_id' => $job->id,
                    'archive_source_name' => $originalName,
                    'archive_entry_path' => $entryPath,
                ];
                if ($profiled) {
                    $childPayload['game_id'] = $gameId;
                    $childPayload['strict_profile'] = $strictProfile;
                } else {
                    $childPayload['source_kind'] = 'archive-entry';
                }

                $queue->enqueue(
                    $queueName,
                    $childType,
                    $childPayload,
                    5,
                    null,
                    $dedupeKey,
                    $userId,
                    3,
                    $job->id,
                    'archive:' . hash('sha256', strtolower($entryPath))
                );
                $queued++;
                $unpackedBytes += $entryBytes;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Queued archive member ' . $entryPath . '.');
            } catch (JobCancellationRequested $error) {
                if ($temporary !== '') {
                    @unlink($temporary);
                }
                if (is_array($staged)) {
                    $incoming->delete((string)($staged['relative_path'] ?? ''));
                }
                throw $error;
            } catch (Throwable $error) {
                if ($temporary !== '') {
                    @unlink($temporary);
                }
                if (is_array($staged)) {
                    $incoming->delete((string)($staged['relative_path'] ?? ''));
                }
                $failed++;
                $message = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);
                $errors = $this->retainError($errors, $entryPath, $message);
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Archive member failed: ' . $entryPath . ' — ' . $message);
            }
        }

        $sourceRetained = $failed > 0;
        if (!$sourceRetained) {
            $this->deleteSource($stagedPath, $incoming);
        }

        $status = $failed > 0 ? 'partial' : 'completed';
        $message = 'Archive expansion complete: ' . number_format($queued) . ' Unreal file(s) queued';
        if ($skipped > 0) {
            $message .= ', ' . number_format($skipped) . ' unsupported member(s) skipped';
        }
        if ($failed > 0) {
            $message .= ', ' . number_format($failed) . ' member(s) failed; source archive retained';
        }
        $message .= '.';

        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'complete',
            'entry_cursor' => $total,
            'done' => max(1, $total),
            'total' => max(1, $total),
            'percent' => 100,
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'errors' => $errors,
            'status' => $status,
            'message' => $message,
        ]);

        return [
            'operation' => $profiled ? 'import_staged_archive' : 'process_bucket_archive',
            'status' => $status,
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'archive_entries' => $total,
            'queued_files' => $queued,
            'skipped_files' => $skipped,
            'failed_files' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'source_retained' => $sourceRetained,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /** @return array<string,bool> */
    private function profiledExtensions(int $gameId): array
    {
        $statement = $this->db->prepare(
            'SELECT p.engine_key,p.allowed_extensions_json FROM ue_games g '
            . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1'
        );
        $statement->execute([$gameId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) {
            throw new \RuntimeException('Target game no longer has an active profile.');
        }

        $allowed = [];
        $decoded = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $extension) {
                $extension = strtolower(ltrim(trim((string)$extension), '.'));
                if ($extension !== '') {
                    $allowed[$extension] = true;
                }
            }
        }
        foreach (['uz', 'uz2', 'uz3'] as $extension) {
            $allowed[$extension] = true;
        }
        if (in_array(strtoupper(trim((string)($profile['engine_key'] ?? ''))), ['UE4', 'UE5'], true)) {
            $allowed['pak'] = true;
        }
        return $allowed;
    }

    /** @return array<string,bool> */
    private function bucketExtensions(): array
    {
        $allowed = array_fill_keys(
            (new CatalogUploadBucketFilePolicy($this->db, $this->config))->allowedExtensions(),
            true
        );
        foreach (['uz', 'uz2', 'uz3'] as $extension) {
            $allowed[$extension] = true;
        }
        // PAK requires a selected UE4/UE5 game/profile and therefore is not
        // expanded into the unsorted Upload Bucket path.
        unset($allowed['zip'], $allowed['7z'], $allowed['rar'], $allowed['pak']);
        return $allowed;
    }

    /** @param list<array{file:string,error:string}> $errors @return list<array{file:string,error:string}> */
    private function retainError(array $errors, string $file, string $error): array
    {
        $errors[] = [
            'file' => $file,
            'error' => function_exists('mb_substr') ? mb_substr($error, 0, 800, 'UTF-8') : substr($error, 0, 800),
        ];
        if (count($errors) > self::ERROR_RETENTION) {
            $errors = array_slice($errors, -self::ERROR_RETENTION);
        }
        return $errors;
    }

    /** @param list<array{file:string,error:string}> $errors */
    private function checkpoint(
        JobExecutionContext $context,
        int $cursor,
        int $total,
        int $queued,
        int $skipped,
        int $failed,
        int $unpackedBytes,
        array $errors,
        string $message
    ): void {
        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'expand_archive',
            'entry_cursor' => $cursor,
            'done' => $cursor,
            'total' => max(1, $total),
            'percent' => $total > 0 ? min(99, (int)floor(($cursor * 100) / $total)) : 99,
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'errors' => $errors,
            'message' => $message,
        ]);
    }

    private function normalLimitBytes(): int
    {
        $limit = (int)($this->config['ingress_max_upload_bytes'] ?? $this->config['max_upload_bytes'] ?? 0);
        return $limit > 0 ? $limit : 256 * 1024 * 1024;
    }

    private function containerLimitBytes(): int
    {
        $limit = max(
            $this->normalLimitBytes(),
            (int)($this->config['max_container_upload_bytes'] ?? 0)
        );
        return $limit > 0 ? $limit : 64 * 1024 * 1024 * 1024;
    }

    private function maxTotalUnpackedBytes(): int
    {
        $configured = (int)($this->config['archive']['max_unpacked_bytes'] ?? getenv('UNREALDB_ARCHIVE_MAX_UNPACKED_BYTES') ?: 0);
        if ($configured > 0) {
            return $configured;
        }
        $container = $this->containerLimitBytes();
        if ($container > intdiv(PHP_INT_MAX, 4)) {
            return PHP_INT_MAX;
        }
        return max(1024 * 1024 * 1024, $container * 4);
    }

    private function deleteSource(string $stagedPath, CatalogIncomingFileStore $incoming): void
    {
        if (preg_match('/^chunk-upload:([a-f0-9]{64})$/', $stagedPath, $match) === 1) {
            (new CatalogChunkedUploadCleanup($this->config))->delete($match[1]);
            return;
        }
        $incoming->delete($stagedPath);
    }
}
