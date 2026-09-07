<?php
/**
 * Resolves durable source identity/path information for background jobs.
 *
 * Job failures often happen several workflow levels away from the file that an
 * operator originally submitted. This resolver keeps that provenance in one
 * place so diagnostics and System Error logging can show both the immediate
 * staged member and its archive parent without duplicating queue/storage logic.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

final class CatalogJobSourceContextResolver
{
    private readonly CatalogIncomingFileStore $incoming;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->incoming = new CatalogIncomingFileStore($config);
    }

    /** @return array<string,mixed> */
    public function forClaimedJob(ClaimedJob $job): array
    {
        return $this->resolveRow([
            'id' => $job->id,
            'job_type' => $job->type,
            'parent_job_id' => $job->parentJobId,
            'payload' => $job->payload,
        ]);
    }

    /** @return array<string,mixed> */
    public function forJobId(int $jobId): array
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('A positive background job id is required.');
        }
        $row = $this->jobRow($jobId);
        if ($row === null) {
            throw new \RuntimeException('Background job #' . $jobId . ' was not found.');
        }
        return $this->resolveRow($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function resolveRow(array $row): array
    {
        $payload = is_array($row['payload'] ?? null)
            ? $row['payload']
            : $this->decodePayload($row['payload_json'] ?? null);
        $jobId = max(0, (int)($row['id'] ?? 0));
        $jobType = trim((string)($row['job_type'] ?? ''));
        $parentJobId = max(
            0,
            (int)($row['parent_job_id'] ?? 0),
            (int)($payload['archive_parent_job_id'] ?? 0)
        );

        $context = [
            'job_id' => $jobId,
            'job_type' => $jobType,
        ];
        $this->copyPayloadIdentity($context, 'job', $payload);
        $this->applyVerifiedFileIdentity($context, $payload);
        if (in_array($jobType, [
            JobType::PROCESS_BUCKET_UPLOAD,
            JobType::PROCESS_BUCKET_ARCHIVE,
            JobType::PREPARE_BUCKET_REDIRECT,
        ], true)) {
            $this->applyCompletedChunkSource($context, $payload);
        }

        $archiveSourceName = trim((string)($payload['archive_source_name'] ?? ''));
        $archiveEntryPath = trim((string)($payload['archive_entry_path'] ?? ''));
        if ($archiveSourceName !== '') {
            $context['archive_source_name'] = $archiveSourceName;
        }
        if ($archiveEntryPath !== '') {
            $context['archive_entry_path'] = $archiveEntryPath;
        }

        if ($jobId > 0 && in_array($jobType, [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE], true)) {
            $this->applyPreparedArchiveSource($context, $jobId);
        }

        if ($parentJobId < 1) {
            return $this->withoutEmptyValues($context);
        }

        $context['parent_job_id'] = $parentJobId;
        $parent = $this->jobRow($parentJobId);
        if ($parent === null) {
            $context['parent_lookup_error'] = 'Parent background job is no longer retained.';
            return $this->withoutEmptyValues($context);
        }

        $parentPayload = $this->decodePayload($parent['payload_json'] ?? null);
        $context['parent_job_type'] = trim((string)($parent['job_type'] ?? ''));
        $this->copyPayloadIdentity($context, 'parent', $parentPayload);

        $parentName = trim((string)($parentPayload['original_name'] ?? ''));
        if (!isset($context['archive_source_name']) && $parentName !== '') {
            $context['archive_source_name'] = $parentName;
        }
        $parentRelative = trim((string)($parentPayload['source_relative_path'] ?? ''));
        if ($parentRelative !== '') {
            $context['archive_source_relative_path'] = $parentRelative;
        }

        // The parent workflow's prepared archive is the authoritative recovery
        // source. The original chunk/incoming reference is retained as provenance
        // but may have been deliberately released after ownership transferred.
        $this->applyPreparedArchiveSource($context, $parentJobId);

        $parentStaged = trim((string)($parentPayload['staged_path'] ?? ''));
        if ($parentStaged !== '') {
            $context['archive_staged_path'] = $parentStaged;
            $resolved = $this->resolveStaged($parentStaged);
            if ($resolved['full_path'] !== '') {
                $context['archive_ingress_full_path'] = $resolved['full_path'];
                $context['archive_ingress_full_path_exists'] = $resolved['exists'];
                if (!isset($context['archive_full_path'])) {
                    $context['archive_full_path'] = $resolved['full_path'];
                    $context['archive_full_path_exists'] = $resolved['exists'];
                    $context['archive_source_storage'] = 'ingress';
                }
            } elseif ($resolved['error'] !== '') {
                $context['archive_path_resolution_error'] = $resolved['error'];
            }
        }

        return $this->withoutEmptyValues($context);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $payload */
    private function applyVerifiedFileIdentity(array &$context, array $payload): void
    {
        $fileId = max(
            0,
            (int)($payload['file_id'] ?? 0),
            (int)($payload['affected_file_id'] ?? 0)
        );
        if ($fileId < 1) {
            return;
        }

        try {
            $statement = $this->db->prepare(
                'SELECT f.id,f.game_id,f.original_name,f.source_relative_path,f.relative_path,f.file_size,'
                . 'f.md5,f.sha1,f.package_version,f.licensee_version,f.detected_engine_key,'
                . 'f.detected_package_version,f.detected_licensee_version,g.name game_name '
                . 'FROM ue_files f LEFT JOIN ue_games g ON g.id=f.game_id WHERE f.id=? LIMIT 1'
            );
            $statement->execute([$fileId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($file)) {
                $context['file_id'] = $fileId;
                $context['file_lookup_error'] = 'Verified file row is no longer retained.';
                return;
            }

            $context['file_id'] = (int)$file['id'];
            $context['game_id'] = (int)$file['game_id'];
            $context['game_name'] = (string)($file['game_name'] ?? '');
            $context['file_name'] = (string)$file['original_name'];
            $context['original_name'] = (string)$file['original_name'];
            $context['source_relative_path'] = (string)($file['source_relative_path'] ?? '');
            $context['canonical_relative_path'] = (string)($file['relative_path'] ?? '');
            $context['file_size'] = max(0, (int)($file['file_size'] ?? 0));
            $context['md5'] = strtolower(trim((string)($file['md5'] ?? '')));
            $context['sha1'] = strtolower(trim((string)($file['sha1'] ?? '')));
            $context['package_version'] = (int)($file['package_version'] ?? 0);
            $context['licensee_version'] = (int)($file['licensee_version'] ?? 0);
            $context['detected_engine_key'] = (string)($file['detected_engine_key'] ?? '');
            $context['detected_package_version'] = (int)($file['detected_package_version'] ?? 0);
            $context['detected_licensee_version'] = (int)($file['detected_licensee_version'] ?? 0);
        } catch (Throwable $error) {
            $context['file_id'] = $fileId;
            $context['file_lookup_error'] = trim($error->getMessage()) !== ''
                ? trim($error->getMessage())
                : get_class($error);
        }
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $payload */
    private function applyCompletedChunkSource(array &$context, array $payload): void
    {
        if (isset($context['job_full_path']) && trim((string)$context['job_full_path']) !== '') {
            return;
        }
        $uploadId = strtolower(trim((string)($payload['upload_id'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            return;
        }
        $userId = max(0, (int)($payload['user_id'] ?? 0));
        try {
            $resolved = (new CatalogChunkedUploadStore($this->config))
                ->resolveCompletedFile($uploadId, $userId > 0 ? $userId : null);
            $path = trim((string)($resolved['path'] ?? ''));
            if ($path !== '') {
                $context['job_full_path'] = $path;
                $context['job_full_path_exists'] = is_file($path);
                $context['job_source_storage'] = 'chunk-upload';
            }
        } catch (Throwable $error) {
            $context['job_chunk_path_error'] = trim($error->getMessage()) !== ''
                ? trim($error->getMessage())
                : get_class($error);
        }
    }

    /** @param array<string,mixed> $context */
    private function applyPreparedArchiveSource(array &$context, int $jobId): void
    {
        if ($jobId < 1) {
            return;
        }
        try {
            $prepared = (new CatalogPreparedJobFileStore($this->config, $jobId, 'archive-source'))->load();
        } catch (Throwable $error) {
            $context['archive_prepared_path_error'] = trim($error->getMessage()) !== ''
                ? trim($error->getMessage())
                : get_class($error);
            return;
        }
        if (!is_array($prepared)) {
            return;
        }

        $path = trim((string)($prepared['path'] ?? ''));
        if ($path === '') {
            return;
        }
        $context['archive_prepared_path'] = $path;
        $context['archive_prepared_bytes'] = max(0, (int)($prepared['size'] ?? 0));
        $context['archive_full_path'] = $path;
        $context['archive_full_path_exists'] = is_file($path);
        $context['archive_source_storage'] = 'job-prepared';
        $originalStaged = trim((string)($prepared['original_staged_path'] ?? ''));
        if ($originalStaged !== '') {
            $context['archive_original_staged_path'] = $originalStaged;
        }
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $payload */
    private function copyPayloadIdentity(array &$context, string $prefix, array $payload): void
    {
        $name = trim((string)($payload['original_name'] ?? ''));
        $relative = trim((string)($payload['source_relative_path'] ?? ''));
        $staged = trim((string)($payload['staged_path'] ?? ''));
        if ($name !== '') {
            $context[$prefix . '_original_name'] = $name;
        }
        if ($relative !== '') {
            $context[$prefix . '_source_relative_path'] = $relative;
        }
        if ($staged === '') {
            return;
        }

        $context[$prefix . '_staged_path'] = $staged;
        $resolved = $this->resolveStaged($staged);
        if ($resolved['full_path'] !== '') {
            $context[$prefix . '_full_path'] = $resolved['full_path'];
            $context[$prefix . '_full_path_exists'] = $resolved['exists'];
        } elseif ($resolved['error'] !== '') {
            $context[$prefix . '_path_resolution_error'] = $resolved['error'];
        }
    }

    /** @return array{full_path:string,exists:bool,error:string} */
    private function resolveStaged(string $stagedPath): array
    {
        try {
            $path = $this->incoming->resolve($stagedPath);
            return [
                'full_path' => $path,
                'exists' => is_file($path),
                'error' => '',
            ];
        } catch (Throwable $error) {
            return [
                'full_path' => '',
                'exists' => false,
                'error' => trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error),
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function jobRow(int $jobId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,job_type,parent_job_id,payload_json FROM ue_background_jobs WHERE id=? LIMIT 1'
        );
        $statement->execute([$jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function decodePayload(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function withoutEmptyValues(array $context): array
    {
        return array_filter(
            $context,
            static fn(mixed $value): bool => $value !== '' && $value !== null
        );
    }
}
