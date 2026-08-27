<?php
/**
 * Expands uploaded ZIP/7z/RAR/UMOD-family containers into ordinary durable Unreal import jobs.
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
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogArchiveImportJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 3;
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
            throw new \InvalidArgumentException('Archive job source is not a supported archive container.');
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        $sourcePath = $incoming->resolve($stagedPath);
        $sequential = new CatalogSequentialArchiveReader($this->config);
        if ($sequential->shouldUse($sourcePath, $originalName)) {
            return $this->handleSequentialArchive(
                $job,
                $context,
                $profiled,
                $stagedPath,
                $originalName,
                $sourceRelativePath,
                $userId,
                $gameId,
                $strictProfile,
                $incoming,
                $sourcePath,
                $sequential
            );
        }

        $extractor = new CatalogArchiveExtractor($this->config);
        try {
            $entries = $extractor->entries($sourcePath, $originalName);
        } catch (Throwable $error) {
            if (!$this->isTerminalArchiveCapabilityFailure($error)) {
                throw $error;
            }
            return $this->terminalArchiveCapabilityResult(
                $job,
                $context,
                $profiled,
                $originalName,
                $sourceRelativePath,
                0,
                0,
                0,
                $error
            );
        }
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
        $queueName = $this->queueName($job);
        $queue = new PdoJobQueue($this->db);
        $total = count($entries);
        $maxTotalBytes = $this->maxTotalUnpackedBytes();

        for ($index = $cursor; $index < $total; $index++) {
            $context->heartbeatIfDue();
            $entry = $entries[$index];
            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));

            if (empty($entry['safe'])) {
                if ($this->isIgnorableUnsafeArchivePath($entry)) {
                    $skipped++;
                    $this->checkpoint(
                        $context,
                        $index + 1,
                        $total,
                        $queued,
                        $skipped,
                        $failed,
                        $unpackedBytes,
                        $errors,
                        'Skipped unrepresentable archive metadata path ' . $entryPath . '.'
                    );
                    continue;
                }
                $failed++;
                $reason = 'Unsafe archive path: ' . trim((string)($entry['reason'] ?? 'invalid member path'));
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors, $reason);
                continue;
            }
            if (!empty($entry['encrypted'])) {
                $failed++;
                $reason = 'Encrypted/password-protected archive member is not supported.';
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors, $reason);
                continue;
            }

            try {
                $entryName = CatalogImportPathPolicy::filename(basename($entryPath));
            } catch (\InvalidArgumentException $error) {
                // One malformed directory record must not dead-letter an entire
                // otherwise-readable archive. Retain the archive as partial and
                // continue with every other member, exactly as for other unsafe
                // member identities.
                $failed++;
                $reason = 'Archive member filename is invalid: ' . trim($error->getMessage());
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->checkpoint(
                    $context,
                    $index + 1,
                    $total,
                    $queued,
                    $skipped,
                    $failed,
                    $unpackedBytes,
                    $errors,
                    $reason
                );
                continue;
            }
            $extension = strtolower((string)pathinfo($entryName, PATHINFO_EXTENSION));
            if (CatalogArchiveExtractor::isArchiveName($entryName)) {
                $skipped++;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Skipped nested archive ' . $entryPath . '.');
                continue;
            }
            if (!isset($allowed[$extension])) {
                $skipped++;
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                    'Skipped unsupported archive member ' . $entryPath . '.');
                continue;
            }

            $entryBytes = max(0, (int)($entry['size'] ?? 0));
            // Once an archive is durably on the server, its contained package is
            // no longer a browser ingress request. Use the container/member bound,
            // not max_upload_bytes, while the separate total-unpacked cap still
            // limits decompression bombs across the whole archive.
            $entryLimit = $this->containerLimitBytes();
            if ($entryBytes === 0) {
                $skipped++;
                $reason = 'Skipped empty archive member ' . $entryPath . '.';
                $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors, $reason);
                continue;
            }
            if ($entryBytes > $entryLimit) {
                $failed++;
                $reason = 'Archive member ' . $entryPath . ' is ' . number_format($entryBytes)
                    . ' bytes; configured archive-member limit is ' . number_format($entryLimit) . ' bytes.';
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

            $dedupeKey = $this->dedupeKey($job->id, $entryPath);
            if ($this->queuedChildExists($queueName, $dedupeKey)) {
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

                $this->enqueueChild(
                    $queue,
                    $queueName,
                    $job,
                    $profiled,
                    $gameId,
                    $strictProfile,
                    $userId,
                    $originalName,
                    $sourceRelativePath,
                    $entryPath,
                    $entryName,
                    $extension,
                    $dedupeKey,
                    $staged
                );
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
                continue;
            }

            $queued++;
            $unpackedBytes += $entryBytes;
            $this->checkpoint($context, $index + 1, $total, $queued, $skipped, $failed, $unpackedBytes, $errors,
                'Queued archive member ' . $entryPath . '.');
        }

        return $this->completeArchive(
            $job,
            $context,
            $profiled,
            $stagedPath,
            $originalName,
            $sourceRelativePath,
            $incoming,
            $total,
            $queued,
            $skipped,
            $failed,
            $unpackedBytes,
            $errors
        );
    }

    /** @return array<string,mixed> */
    private function handleSequentialArchive(
        ClaimedJob $job,
        JobExecutionContext $context,
        bool $profiled,
        string $stagedPath,
        string $originalName,
        string $sourceRelativePath,
        int $userId,
        int $gameId,
        bool $strictProfile,
        CatalogIncomingFileStore $incoming,
        string $sourcePath,
        CatalogSequentialArchiveReader $reader
    ): array {
        $allowed = $profiled ? $this->profiledExtensions($gameId) : $this->bucketExtensions();
        $queueName = $this->queueName($job);
        $queue = new PdoJobQueue($this->db);
        $maxTotalBytes = $this->maxTotalUnpackedBytes();

        // A checkpoint is written only after a member has been fully classified
        // and, when applicable, its durable child job has been queued. After a
        // process/server restart, restore that committed cursor and all counters.
        // Some solid RAR/7z decoders still have to consume the compressed prefix
        // to rebuild decoder state, but those prefix members are replay-only: they
        // must not reset progress, mutate counters or enqueue duplicate children.
        $resume = $context->resumeProgress();
        $resumeStage = trim((string)($resume['stage'] ?? ''));
        if ((int)($resume['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION
            || $resumeStage !== 'expand_archive_sequential'
            || empty($resume['sequential_archive'])) {
            $resume = [];
        }
        $resumeCursor = max(0, (int)($resume['entry_cursor'] ?? 0));
        $processed = $resumeCursor;
        $queued = max(0, (int)($resume['queued'] ?? 0));
        $skipped = max(0, (int)($resume['skipped'] ?? 0));
        $failed = max(0, (int)($resume['failed'] ?? 0));
        $unpackedBytes = max(0, (int)($resume['unpacked_bytes'] ?? 0));
        $errors = is_array($resume['errors'] ?? null) ? array_values($resume['errors']) : [];
        $walkOrdinal = 0;

        if ($resumeCursor > 0) {
            $resume['message'] = 'Resuming sequential archive after ' . number_format($resumeCursor)
                . ' committed member(s); rebuilding decoder state only where required.';
            $context->checkpoint($resume);
        }

        $plan = function (array $entry) use (
            $allowed,
            $queueName,
            $job,
            $resumeCursor,
            &$walkOrdinal
        ): array {
            $ordinal = $walkOrdinal++;
            if ($ordinal < $resumeCursor) {
                return [
                    'extract' => false,
                    'state' => ['kind' => 'resume_replay'],
                ];
            }

            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            if (empty($entry['safe'])) {
                if ($this->isIgnorableUnsafeArchivePath($entry)) {
                    return [
                        'extract' => false,
                        'state' => [
                            'kind' => 'skipped',
                            'reason' => 'Skipped unrepresentable archive metadata path ' . $entryPath . '.',
                        ],
                    ];
                }
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'failed',
                        'reason' => 'Unsafe archive path: ' . trim((string)($entry['reason'] ?? 'invalid member path')),
                    ],
                ];
            }
            if (!empty($entry['encrypted'])) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'failed',
                        'reason' => 'Encrypted/password-protected archive member is not supported.',
                    ],
                ];
            }

            try {
                $entryName = CatalogImportPathPolicy::filename(basename($entryPath));
            } catch (\InvalidArgumentException $error) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'failed',
                        'reason' => 'Archive member filename is invalid: ' . trim($error->getMessage()),
                    ],
                ];
            }
            $extension = strtolower((string)pathinfo($entryName, PATHINFO_EXTENSION));
            if (CatalogArchiveExtractor::isArchiveName($entryName)) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'skipped',
                        'reason' => 'Skipped nested archive ' . $entryPath . '.',
                    ],
                ];
            }
            if (!isset($allowed[$extension])) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'skipped',
                        'reason' => 'Skipped unsupported archive member ' . $entryPath . '.',
                    ],
                ];
            }

            $entryBytes = max(0, (int)($entry['size'] ?? 0));
            $entryLimit = $this->containerLimitBytes();
            if ($entryBytes === 0) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'skipped',
                        'reason' => 'Skipped empty archive member ' . $entryPath . '.',
                    ],
                ];
            }
            if ($entryBytes > $entryLimit) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'failed',
                        'reason' => 'Archive member ' . $entryPath . ' is ' . number_format($entryBytes)
                            . ' bytes; configured archive-member limit is ' . number_format($entryLimit) . ' bytes.',
                    ],
                ];
            }

            $dedupeKey = $this->dedupeKey($job->id, $entryPath);
            if ($this->queuedChildExists($queueName, $dedupeKey)) {
                return [
                    'extract' => false,
                    'state' => [
                        'kind' => 'reused',
                        'entry_name' => $entryName,
                        'extension' => $extension,
                        'dedupe_key' => $dedupeKey,
                    ],
                ];
            }

            return [
                'extract' => true,
                'max_bytes' => $entryLimit,
                'state' => [
                    'kind' => 'extract',
                    'entry_name' => $entryName,
                    'extension' => $extension,
                    'dedupe_key' => $dedupeKey,
                ],
            ];
        };

        $complete = function (array $entry, ?string $temporary, mixed $state) use (
            &$processed,
            &$queued,
            &$skipped,
            &$failed,
            &$unpackedBytes,
            &$errors,
            $context,
            $queue,
            $queueName,
            $job,
            $profiled,
            $gameId,
            $strictProfile,
            $userId,
            $originalName,
            $sourceRelativePath,
            $incoming
        ): void {
            $state = is_array($state) ? $state : [];
            $kind = (string)($state['kind'] ?? 'failed');
            if ($kind === 'resume_replay') {
                return;
            }

            $processed++;
            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            $entryBytes = max(0, (int)($entry['size'] ?? 0));

            if ($kind === 'failed') {
                $failed++;
                $reason = trim((string)($state['reason'] ?? 'Archive member could not be processed.'));
                $errors = $this->retainError($errors, $entryPath, $reason);
                $this->sequentialCheckpoint(
                    $context,
                    $processed,
                    $queued,
                    $skipped,
                    $failed,
                    $unpackedBytes,
                    $errors,
                    $reason
                );
                return;
            }
            if ($kind === 'skipped') {
                $skipped++;
                $this->sequentialCheckpoint(
                    $context,
                    $processed,
                    $queued,
                    $skipped,
                    $failed,
                    $unpackedBytes,
                    $errors,
                    (string)($state['reason'] ?? ('Skipped ' . $entryPath . '.'))
                );
                return;
            }
            if ($kind === 'reused') {
                $queued++;
                $unpackedBytes += $entryBytes;
                $this->sequentialCheckpoint(
                    $context,
                    $processed,
                    $queued,
                    $skipped,
                    $failed,
                    $unpackedBytes,
                    $errors,
                    'Reused already queued archive member ' . $entryPath . '.'
                );
                return;
            }

            $entryName = (string)($state['entry_name'] ?? CatalogImportPathPolicy::filename(basename($entryPath)));
            $extension = (string)($state['extension'] ?? strtolower((string)pathinfo($entryName, PATHINFO_EXTENSION)));
            $dedupeKey = (string)($state['dedupe_key'] ?? $this->dedupeKey($job->id, $entryPath));
            $staged = null;
            try {
                if ($temporary === null || !is_file($temporary)) {
                    throw new \RuntimeException('Sequential archive member temporary file is unavailable.');
                }
                $staged = $incoming->stageLocalFile($temporary, $entryName);
                $this->enqueueChild(
                    $queue,
                    $queueName,
                    $job,
                    $profiled,
                    $gameId,
                    $strictProfile,
                    $userId,
                    $originalName,
                    $sourceRelativePath,
                    $entryPath,
                    $entryName,
                    $extension,
                    $dedupeKey,
                    $staged
                );
            } catch (Throwable $error) {
                if (is_array($staged)) {
                    $incoming->delete((string)($staged['relative_path'] ?? ''));
                }
                $failed++;
                $message = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);
                $errors = $this->retainError($errors, $entryPath, $message);
                $this->sequentialCheckpoint(
                    $context,
                    $processed,
                    $queued,
                    $skipped,
                    $failed,
                    $unpackedBytes,
                    $errors,
                    'Archive member failed: ' . $entryPath . ' — ' . $message
                );
                return;
            }

            $queued++;
            $unpackedBytes += $entryBytes;
            $this->sequentialCheckpoint(
                $context,
                $processed,
                $queued,
                $skipped,
                $failed,
                $unpackedBytes,
                $errors,
                'Queued archive member ' . $entryPath . ' from the sequential archive stream.'
            );
        };

        try {
            $walk = $reader->walk(
                $sourcePath,
                $originalName,
                $maxTotalBytes,
                $plan,
                $complete,
                static function () use ($context): void {
                    $context->heartbeatIfDue();
                }
            );
        } catch (Throwable $error) {
            if (!$this->isTerminalArchiveCapabilityFailure($error)) {
                throw $error;
            }
            return $this->terminalArchiveCapabilityResult(
                $job,
                $context,
                $profiled,
                $originalName,
                $sourceRelativePath,
                $queued,
                $skipped,
                $unpackedBytes,
                $error,
                $failed,
                $errors
            );
        }

        if ($resumeCursor > 0 && $walkOrdinal < $resumeCursor) {
            throw new \RuntimeException(
                'Sequential archive resume cursor exceeds the number of readable archive members; source changed or is incomplete.'
            );
        }

        return $this->completeArchive(
            $job,
            $context,
            $profiled,
            $stagedPath,
            $originalName,
            $sourceRelativePath,
            $incoming,
            (int)($walk['entries'] ?? $processed),
            $queued,
            $skipped,
            $failed,
            $unpackedBytes,
            $errors,
            true,
            (string)($walk['format'] ?? '')
        );
    }

    /** @param array<string,mixed> $staged */
    private function enqueueChild(
        PdoJobQueue $queue,
        string $queueName,
        ClaimedJob $job,
        bool $profiled,
        int $gameId,
        bool $strictProfile,
        int $userId,
        string $originalName,
        string $sourceRelativePath,
        string $entryPath,
        string $entryName,
        string $extension,
        string $dedupeKey,
        array $staged
    ): void {
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
    }

    /** @return array<string,mixed> */
    private function completeArchive(
        ClaimedJob $job,
        JobExecutionContext $context,
        bool $profiled,
        string $stagedPath,
        string $originalName,
        string $sourceRelativePath,
        CatalogIncomingFileStore $incoming,
        int $total,
        int $queued,
        int $skipped,
        int $failed,
        int $unpackedBytes,
        array $errors,
        bool $sequential = false,
        string $format = ''
    ): array {
        /*
         * Archive-member jobs are asynchronous. Successful extraction only means
         * the child work was queued; it does not mean those Unreal packages will
         * parse/import successfully. The parent must therefore keep ownership of
         * the immutable archive bytes until normal background-job history cleanup
         * deliberately removes the terminal job. Otherwise a child can fail after
         * this method returns and the projected partial_archive row has nothing
         * left to retry.
         */
        $sourceRetained = true;

        $status = $failed > 0 ? 'partial' : 'completed';
        $message = 'Archive expansion complete: ' . number_format($queued) . ' Unreal file(s) queued';
        if ($skipped > 0) {
            $message .= ', ' . number_format($skipped) . ' member(s) skipped';
        }
        if ($failed > 0) {
            $message .= ', ' . number_format($failed) . ' member(s) failed';
        }
        $message .= '; source archive retained for asynchronous member recovery';
        if ($sequential) {
            $label = $format === '7z' ? '7-Zip' : strtoupper($format);
            $message .= '; ' . ($label !== '' ? $label . ' ' : '') . 'members were consumed sequentially';
        }
        $message .= '.';

        if ($failed > 0) {
            $this->recordRetainedArchiveFailure(
                $job,
                $originalName,
                $sourceRelativePath,
                $total,
                $queued,
                $skipped,
                $failed,
                $errors,
                $message
            );
        }

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
            'sequential_archive' => $sequential,
            'archive_format' => $format,
            'source_retained' => true,
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
            'sequential_archive' => $sequential,
            'archive_format' => $format,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function terminalArchiveCapabilityResult(
        ClaimedJob $job,
        JobExecutionContext $context,
        bool $profiled,
        string $originalName,
        string $sourceRelativePath,
        int $queued,
        int $skipped,
        int $unpackedBytes,
        Throwable $error,
        int $failed = 0,
        array $errors = []
    ): array {
        $decoderError = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);
        $message = 'Archive could not be fully expanded because the installed PHP archive decoder cannot decode '
            . 'this archive/member encoding; source archive retained. Decoder: ' . $decoderError;
        $failed++;
        $errors = $this->retainError(
            $errors,
            $sourceRelativePath !== '' ? $sourceRelativePath : $originalName,
            $message
        );
        $total = max(1, $queued + $skipped + $failed);

        $this->recordRetainedArchiveFailure(
            $job,
            $originalName,
            $sourceRelativePath,
            $total,
            $queued,
            $skipped,
            $failed,
            $errors,
            $message,
            $error
        );

        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'complete',
            'entry_cursor' => $total,
            'done' => $total,
            'total' => $total,
            'percent' => 100,
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'errors' => $errors,
            'status' => 'partial',
            'sequential_archive' => true,
            'source_retained' => true,
            'message' => $message,
        ]);
        return [
            'operation' => $profiled ? 'import_staged_archive' : 'process_bucket_archive',
            'status' => 'partial',
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'archive_entries' => $total,
            'queued_files' => $queued,
            'skipped_files' => $skipped,
            'failed_files' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'source_retained' => true,
            'sequential_archive' => true,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /** @param array<string,mixed> $entry */
    private function isIgnorableUnsafeArchivePath(array $entry): bool
    {
        // Classic Mac/Finder ZIP metadata commonly uses a filename ending in a
        // carriage return. It cannot be represented on the Windows host and is
        // not an Unreal package, so it must be skipped rather than force the
        // entire otherwise-healthy archive into retained/partial state.
        return trim((string)($entry['reason'] ?? '')) === 'empty/control-character path';
    }

    /** @param list<array{file:string,error:string}> $errors */
    private function recordRetainedArchiveFailure(
        ClaimedJob $job,
        string $originalName,
        string $sourceRelativePath,
        int $total,
        int $queued,
        int $skipped,
        int $failed,
        array $errors,
        string $resultMessage,
        ?Throwable $cause = null
    ): void {
        if ($failed < 1) {
            return;
        }

        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $firstFile = trim((string)($first['file'] ?? ''));
        $firstError = trim((string)($first['error'] ?? ''));
        $message = $job->type . ' #' . $job->id . ' partial_archive: '
            . ($sourceRelativePath !== '' ? $sourceRelativePath : $originalName)
            . ' retained with ' . number_format($failed) . ' failed archive member(s).';
        if ($firstFile !== '' || $firstError !== '') {
            $message .= ' First failure: ' . ($firstFile !== '' ? $firstFile . ' — ' : '') . $firstError;
        }

        CatalogSystemErrorRecorder::record([
            'source_kind' => 'background-job',
            'severity' => 'error',
            'error_type' => 'ArchivePartialFailure',
            'message' => $message,
            'source_file' => $cause instanceof Throwable ? $cause->getFile() : __FILE__,
            'source_line' => $cause instanceof Throwable ? $cause->getLine() : __LINE__,
            'trace_text' => $cause instanceof Throwable ? $cause->getTraceAsString() : '',
            'context' => [
                'job_id' => $job->id,
                'job_type' => $job->type,
                'attempt' => $job->attempt,
                'max_attempts' => $job->maxAttempts,
                'disposition' => 'partial_archive',
                'resource_class' => $job->resourceClass,
                'concurrency_key' => $job->concurrencyKey,
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
                'archive_entries' => $total,
                'queued_files' => $queued,
                'skipped_files' => $skipped,
                'failed_files' => $failed,
                'errors' => $errors,
                'result_message' => $resultMessage,
            ],
        ]);
    }

    private function queueName(ClaimedJob $job): string
    {
        $queueName = trim($job->queue);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \RuntimeException('Archive job queue identity is invalid.');
        }
        return $queueName;
    }

    private function dedupeKey(int $jobId, string $entryPath): string
    {
        return 'archive-entry:' . $jobId . ':' . hash('sha256', strtolower($entryPath));
    }

    private function queuedChildExists(string $queueName, string $dedupeKey): bool
    {
        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        return (int)($existing->fetchColumn() ?: 0) > 0;
    }

    private function isTerminalArchiveCapabilityFailure(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        return str_contains($message, 'archive decoder capability unavailable')
            || str_contains($message, 'rar solid archive support unavailable')
            || (str_contains($message, 'solid rar') && str_contains($message, 'unavailable'))
            || str_contains($message, 'unsupported zip compression method')
            || str_contains($message, 'rarentry::extract() returned failure')
            || str_contains($message, 'rarentry::extract() also failed');
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
        // A PAK is a retained container in the neutral Upload Bucket. The child
        // PROCESS_BUCKET_STAGED_PACKAGE route diverts it to CatalogBucketPakJobHandler.
        $allowed['pak'] = true;
        unset($allowed['zip'], $allowed['7z'], $allowed['rar']);
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

    /** @param list<array{file:string,error:string}> $errors */
    private function sequentialCheckpoint(
        JobExecutionContext $context,
        int $processed,
        int $queued,
        int $skipped,
        int $failed,
        int $unpackedBytes,
        array $errors,
        string $message
    ): void {
        $totalHint = max(1, $processed + 1);
        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'expand_archive_sequential',
            'entry_cursor' => $processed,
            'done' => $processed,
            'total' => $totalHint,
            'percent' => min(95, (int)floor(($processed * 100) / $totalHint)),
            'queued' => $queued,
            'skipped' => $skipped,
            'failed' => $failed,
            'unpacked_bytes' => $unpackedBytes,
            'errors' => $errors,
            'sequential_archive' => true,
            'message' => $message . ' ' . number_format($processed) . ' member(s) consumed sequentially.',
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
        $environment = getenv('UNREALDB_ARCHIVE_MAX_UNPACKED_BYTES');
        $configured = (int)($this->config['archive']['max_unpacked_bytes'] ?? (is_string($environment) ? $environment : 0));
        if ($configured > 0) {
            return $configured;
        }
        $container = $this->containerLimitBytes();
        if ($container > intdiv(PHP_INT_MAX, 4)) {
            return PHP_INT_MAX;
        }
        return max(1024 * 1024 * 1024, $container * 4);
    }
}
