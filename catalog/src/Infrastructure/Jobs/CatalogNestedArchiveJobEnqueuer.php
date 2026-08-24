<?php
/**
 * Discovers ZIP/RAR/7z members inside an archive and queues them as child archive jobs.
 *
 * Nested containers are deliberately not recursively expanded inside the current
 * worker call. Each embedded archive becomes its own durable archive workflow so
 * source ownership, retries, cancellation, failure reporting and child waiting use
 * the same queue semantics as a top-level archive.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogNestedArchiveJobEnqueuer
{
    private const RECURSIVE_EXTENSIONS = ['zip', 'rar', '7z'];
    private const DEFAULT_MAX_NESTING_DEPTH = 4;
    private const MAX_CONFIGURED_NESTING_DEPTH = 16;
    private const ERROR_RETENTION = 50;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{
     *   handled:int,queued:int,reused:int,failed:int,unpacked_bytes:int,
     *   max_depth:int,depth:int,errors:list<array{file:string,error:string}>,preflight_error:string
     * }
     */
    public function enqueue(ClaimedJob $job, JobExecutionContext $context): array
    {
        $result = $this->emptyResult($job);
        if (!in_array($job->type, [JobType::IMPORT_STAGED_ARCHIVE, JobType::PROCESS_BUCKET_ARCHIVE], true)) {
            return $result;
        }

        $payload = $job->payload;
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? 'archive.bin'));
        $sourceRelativePath = CatalogImportPathPolicy::relative(
            (string)($payload['source_relative_path'] ?? $originalName)
        );
        $userId = (int)($payload['user_id'] ?? 0);
        $profiled = $job->type === JobType::IMPORT_STAGED_ARCHIVE;
        $gameId = $profiled ? (int)($payload['game_id'] ?? 0) : 0;
        $strictProfile = (bool)($payload['strict_profile'] ?? true);
        if ($stagedPath === '' || $userId < 1 || ($profiled && $gameId < 1)) {
            return $result;
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        try {
            $sourcePath = $incoming->resolve($stagedPath);
            $extractor = new CatalogArchiveExtractor($this->config);
            $entries = $extractor->entries($sourcePath, $originalName);

            // Listing the archive directory is cheap compared with decoding a
            // solid/sequential RAR or 7z stream. If there is no embedded ZIP/RAR/7z
            // member, stop here and let the established handler perform its normal
            // single processing pass. This keeps the common case fast.
            if (!$this->containsRecursiveArchive($entries)) {
                return $result;
            }

            $sequential = new CatalogSequentialArchiveReader($this->config);
            if ($sequential->shouldUse($sourcePath, $originalName)) {
                return $this->enqueueSequential(
                    $job,
                    $context,
                    $incoming,
                    $sequential,
                    $sourcePath,
                    $originalName,
                    $sourceRelativePath,
                    $userId,
                    $profiled,
                    $gameId,
                    $strictProfile,
                    $result
                );
            }

            return $this->enqueueRandomAccess(
                $job,
                $context,
                $incoming,
                $extractor,
                $entries,
                $sourcePath,
                $originalName,
                $sourceRelativePath,
                $userId,
                $profiled,
                $gameId,
                $strictProfile,
                $result
            );
        } catch (Throwable $error) {
            // Nested discovery is an augmentation of the established archive
            // handler, not a replacement decoder. If preflight cannot inspect this
            // container, leave the source untouched and let the normal handler
            // produce its existing authoritative failure/recovery result.
            $result['preflight_error'] = $this->errorText($error);
            return $result;
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function enqueueRandomAccess(
        ClaimedJob $job,
        JobExecutionContext $context,
        CatalogIncomingFileStore $incoming,
        CatalogArchiveExtractor $extractor,
        array $entries,
        string $sourcePath,
        string $originalName,
        string $sourceRelativePath,
        int $userId,
        bool $profiled,
        int $gameId,
        bool $strictProfile,
        array $result
    ): array {
        $queueName = $this->queueName($job);
        $queue = new PdoJobQueue($this->db);
        $entryLimit = $this->containerLimitBytes();
        $maxTotalBytes = $this->maxTotalUnpackedBytes();
        $nestedBytes = 0;

        foreach ($entries as $entry) {
            $context->heartbeatIfDue();
            if (empty($entry['safe']) || !empty($entry['encrypted'])) {
                continue;
            }

            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            $entryName = CatalogImportPathPolicy::filename(basename($entryPath));
            if (!$this->isRecursiveArchiveName($entryName)) {
                continue;
            }

            $entryBytes = max(0, (int)($entry['size'] ?? 0));
            $decision = $this->classifyNestedEntry($job, $queueName, $entryPath, $entryBytes, $nestedBytes, $maxTotalBytes);
            if ($decision['kind'] === 'failed') {
                $this->recordFailure($result, $entryPath, $decision['reason']);
                continue;
            }
            if ($decision['kind'] === 'reused') {
                $result['handled']++;
                $result['reused']++;
                $result['unpacked_bytes'] += $entryBytes;
                $nestedBytes += $entryBytes;
                continue;
            }

            $temporary = '';
            $staged = null;
            try {
                $temporary = $extractor->extractToTemp($sourcePath, $originalName, $entry, $entryLimit);
                $staged = $incoming->stageLocalFile($temporary, $entryName);
                @unlink($temporary);
                $temporary = '';
                $this->enqueueNestedChild(
                    $queue,
                    $queueName,
                    $job,
                    $userId,
                    $profiled,
                    $gameId,
                    $strictProfile,
                    $originalName,
                    $sourceRelativePath,
                    $entryPath,
                    $entryName,
                    $decision['dedupe_key'],
                    $staged
                );
            } catch (Throwable $error) {
                if ($temporary !== '') {
                    @unlink($temporary);
                }
                if (is_array($staged)) {
                    $incoming->delete((string)($staged['relative_path'] ?? ''));
                }
                $this->recordFailure($result, $entryPath, $this->errorText($error));
                continue;
            }

            $result['handled']++;
            $result['queued']++;
            $result['unpacked_bytes'] += $entryBytes;
            $nestedBytes += $entryBytes;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function enqueueSequential(
        ClaimedJob $job,
        JobExecutionContext $context,
        CatalogIncomingFileStore $incoming,
        CatalogSequentialArchiveReader $reader,
        string $sourcePath,
        string $originalName,
        string $sourceRelativePath,
        int $userId,
        bool $profiled,
        int $gameId,
        bool $strictProfile,
        array $result
    ): array {
        $queueName = $this->queueName($job);
        $queue = new PdoJobQueue($this->db);
        $entryLimit = $this->containerLimitBytes();
        $maxTotalBytes = $this->maxTotalUnpackedBytes();
        $nestedBytes = 0;

        $plan = function (array $entry) use ($job, $queueName, $entryLimit, $maxTotalBytes, &$nestedBytes): array {
            if (empty($entry['safe']) || !empty($entry['encrypted'])) {
                return ['extract' => false, 'state' => ['kind' => 'ignore']];
            }
            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            $entryName = CatalogImportPathPolicy::filename(basename($entryPath));
            if (!$this->isRecursiveArchiveName($entryName)) {
                return ['extract' => false, 'state' => ['kind' => 'ignore']];
            }

            $entryBytes = max(0, (int)($entry['size'] ?? 0));
            $decision = $this->classifyNestedEntry(
                $job,
                $queueName,
                $entryPath,
                $entryBytes,
                $nestedBytes,
                $maxTotalBytes,
                true
            );
            if ($decision['kind'] === 'failed') {
                return ['extract' => false, 'state' => [
                    'kind' => 'failed',
                    'entry_path' => $entryPath,
                    'reason' => $decision['reason'],
                ]];
            }
            if ($decision['kind'] === 'reused') {
                return ['extract' => false, 'state' => [
                    'kind' => 'reused',
                    'entry_path' => $entryPath,
                    'entry_bytes' => $entryBytes,
                ]];
            }

            return [
                'extract' => true,
                'max_bytes' => $entryLimit,
                'state' => [
                    'kind' => 'nested',
                    'entry_path' => $entryPath,
                    'entry_name' => $entryName,
                    'dedupe_key' => $decision['dedupe_key'],
                ],
            ];
        };

        $complete = function (array $entry, ?string $temporary, mixed $state) use (
            &$result,
            &$nestedBytes,
            $queue,
            $queueName,
            $job,
            $userId,
            $profiled,
            $gameId,
            $strictProfile,
            $originalName,
            $sourceRelativePath,
            $incoming
        ): void {
            $state = is_array($state) ? $state : [];
            $kind = (string)($state['kind'] ?? 'ignore');
            if ($kind === 'ignore') {
                return;
            }

            $entryPath = (string)($state['entry_path'] ?? str_replace('\\', '/', (string)($entry['path'] ?? '')));
            $entryBytes = max(0, (int)($entry['size'] ?? $state['entry_bytes'] ?? 0));
            if ($kind === 'failed') {
                $this->recordFailure($result, $entryPath, (string)($state['reason'] ?? 'Nested archive could not be processed.'));
                return;
            }
            if ($kind === 'reused') {
                $result['handled']++;
                $result['reused']++;
                $result['unpacked_bytes'] += $entryBytes;
                $nestedBytes += $entryBytes;
                return;
            }

            $entryName = (string)($state['entry_name'] ?? CatalogImportPathPolicy::filename(basename($entryPath)));
            $staged = null;
            try {
                if ($temporary === null || !is_file($temporary)) {
                    throw new \RuntimeException('Nested sequential archive member temporary file is unavailable.');
                }
                $staged = $incoming->stageLocalFile($temporary, $entryName);
                $this->enqueueNestedChild(
                    $queue,
                    $queueName,
                    $job,
                    $userId,
                    $profiled,
                    $gameId,
                    $strictProfile,
                    $originalName,
                    $sourceRelativePath,
                    $entryPath,
                    $entryName,
                    (string)($state['dedupe_key'] ?? $this->dedupeKey($job->id, $entryPath)),
                    $staged
                );
            } catch (Throwable $error) {
                if (is_array($staged)) {
                    $incoming->delete((string)($staged['relative_path'] ?? ''));
                }
                $this->recordFailure($result, $entryPath, $this->errorText($error));
                return;
            }

            $result['handled']++;
            $result['queued']++;
            $result['unpacked_bytes'] += $entryBytes;
            $nestedBytes += $entryBytes;
        };

        try {
            $reader->walk(
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
            // The established archive handler will perform the authoritative
            // sequential walk immediately afterwards and preserve its existing
            // capability/error semantics. Nested children already queued before a
            // decoder failure are durable and deduplicated on retry.
            $result['preflight_error'] = $this->errorText($error);
        }

        return $result;
    }

    /**
     * @return array{kind:string,reason:string,dedupe_key:string}
     */
    private function classifyNestedEntry(
        ClaimedJob $job,
        string $queueName,
        string $entryPath,
        int $entryBytes,
        int $nestedBytes,
        int $maxTotalBytes,
        bool $allowUnknownSize = false
    ): array {
        $depth = max(0, (int)($job->payload['archive_depth'] ?? 0));
        $maxDepth = $this->maxNestingDepth();
        if ($depth >= $maxDepth) {
            return [
                'kind' => 'failed',
                'reason' => 'Nested archive depth limit of ' . $maxDepth . ' reached at ' . $entryPath . '.',
                'dedupe_key' => '',
            ];
        }

        $entryLimit = $this->containerLimitBytes();
        if ((!$allowUnknownSize && $entryBytes < 1) || $entryBytes > $entryLimit) {
            return [
                'kind' => 'failed',
                'reason' => 'Nested archive ' . $entryPath . ' is ' . number_format($entryBytes)
                    . ' bytes; configured archive-member limit is ' . number_format($entryLimit) . ' bytes.',
                'dedupe_key' => '',
            ];
        }
        if ($entryBytes > 0 && $entryBytes > $maxTotalBytes - $nestedBytes) {
            return [
                'kind' => 'failed',
                'reason' => 'Nested archive extraction exceeds the configured per-archive unpacked-data limit of '
                    . number_format($maxTotalBytes) . ' bytes.',
                'dedupe_key' => '',
            ];
        }

        $dedupeKey = $this->dedupeKey($job->id, $entryPath);
        if ($this->queuedChildExists($queueName, $dedupeKey)) {
            return ['kind' => 'reused', 'reason' => '', 'dedupe_key' => $dedupeKey];
        }
        return ['kind' => 'extract', 'reason' => '', 'dedupe_key' => $dedupeKey];
    }

    /** @param array<string,mixed> $staged */
    private function enqueueNestedChild(
        PdoJobQueue $queue,
        string $queueName,
        ClaimedJob $job,
        int $userId,
        bool $profiled,
        int $gameId,
        bool $strictProfile,
        string $originalName,
        string $sourceRelativePath,
        string $entryPath,
        string $entryName,
        string $dedupeKey,
        array $staged
    ): void {
        $depth = max(0, (int)($job->payload['archive_depth'] ?? 0));
        $rootJobId = max(0, (int)($job->payload['archive_root_job_id'] ?? 0));
        if ($rootJobId < 1) {
            $rootJobId = $job->id;
        }
        $memberRelativePath = CatalogImportPathPolicy::relative($sourceRelativePath . '/' . $entryPath);
        $childPayload = [
            'staged_path' => (string)$staged['relative_path'],
            'original_name' => $entryName,
            'source_relative_path' => $memberRelativePath,
            'user_id' => $userId,
            'size' => (int)$staged['size'],
            'sha256' => (string)$staged['sha256'],
            'archive_parent_job_id' => $job->id,
            'archive_root_job_id' => $rootJobId,
            'archive_depth' => $depth + 1,
            'archive_source_name' => $originalName,
            'archive_entry_path' => $entryPath,
            'nested_archive' => true,
        ];
        if ($profiled) {
            $childPayload['game_id'] = $gameId;
            $childPayload['strict_profile'] = $strictProfile;
        } else {
            $childPayload['source_kind'] = 'archive-entry';
        }

        $queue->enqueue(
            $queueName,
            $job->type,
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

    /** @param array<string,mixed> $result */
    private function recordFailure(array &$result, string $file, string $error): void
    {
        $result['handled']++;
        $result['failed']++;
        $result['errors'][] = [
            'file' => $file,
            'error' => function_exists('mb_substr') ? mb_substr($error, 0, 800, 'UTF-8') : substr($error, 0, 800),
        ];
        if (count($result['errors']) > self::ERROR_RETENTION) {
            $result['errors'] = array_slice($result['errors'], -self::ERROR_RETENTION);
        }
    }

    /** @return array<string,mixed> */
    private function emptyResult(ClaimedJob $job): array
    {
        return [
            'handled' => 0,
            'queued' => 0,
            'reused' => 0,
            'failed' => 0,
            'unpacked_bytes' => 0,
            'max_depth' => $this->maxNestingDepth(),
            'depth' => max(0, (int)($job->payload['archive_depth'] ?? 0)),
            'errors' => [],
            'preflight_error' => '',
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function containsRecursiveArchive(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (empty($entry['safe']) || !empty($entry['encrypted'])) {
                continue;
            }
            $entryPath = str_replace('\\', '/', (string)($entry['path'] ?? ''));
            if ($entryPath !== '' && $this->isRecursiveArchiveName(basename($entryPath))) {
                return true;
            }
        }
        return false;
    }

    private function isRecursiveArchiveName(string $name): bool
    {
        return in_array(strtolower((string)pathinfo($name, PATHINFO_EXTENSION)), self::RECURSIVE_EXTENSIONS, true);
    }

    private function dedupeKey(int $jobId, string $entryPath): string
    {
        return 'archive-entry:' . $jobId . ':' . hash('sha256', strtolower($entryPath));
    }

    private function queuedChildExists(string $queueName, string $dedupeKey): bool
    {
        $statement = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $statement->execute([$queueName, $dedupeKey]);
        return (int)($statement->fetchColumn() ?: 0) > 0;
    }

    private function queueName(ClaimedJob $job): string
    {
        $queueName = trim($job->queue);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \RuntimeException('Archive job queue identity is invalid.');
        }
        return $queueName;
    }

    private function maxNestingDepth(): int
    {
        $environment = getenv('UNREALDB_ARCHIVE_MAX_NESTING_DEPTH');
        $configured = (int)($this->config['archive']['max_nesting_depth'] ?? (is_string($environment) ? $environment : 0));
        if ($configured < 1) {
            $configured = self::DEFAULT_MAX_NESTING_DEPTH;
        }
        return min(self::MAX_CONFIGURED_NESTING_DEPTH, max(1, $configured));
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

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : get_class($error);
    }
}
