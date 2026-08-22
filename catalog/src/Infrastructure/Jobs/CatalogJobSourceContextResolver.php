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
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

final class CatalogJobSourceContextResolver
{
    private readonly CatalogIncomingFileStore $incoming;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
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
        $parentJobId = max(
            0,
            (int)($row['parent_job_id'] ?? 0),
            (int)($payload['archive_parent_job_id'] ?? 0)
        );

        $context = [
            'job_id' => $jobId,
            'job_type' => trim((string)($row['job_type'] ?? '')),
        ];
        $this->copyPayloadIdentity($context, 'job', $payload);

        $archiveSourceName = trim((string)($payload['archive_source_name'] ?? ''));
        $archiveEntryPath = trim((string)($payload['archive_entry_path'] ?? ''));
        if ($archiveSourceName !== '') {
            $context['archive_source_name'] = $archiveSourceName;
        }
        if ($archiveEntryPath !== '') {
            $context['archive_entry_path'] = $archiveEntryPath;
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
        $parentStaged = trim((string)($parentPayload['staged_path'] ?? ''));
        if ($parentStaged !== '') {
            $context['archive_staged_path'] = $parentStaged;
            $resolved = $this->resolveStaged($parentStaged);
            if ($resolved['full_path'] !== '') {
                $context['archive_full_path'] = $resolved['full_path'];
                $context['archive_full_path_exists'] = $resolved['exists'];
            } elseif ($resolved['error'] !== '') {
                $context['archive_path_resolution_error'] = $resolved['error'];
            }
        }

        return $this->withoutEmptyValues($context);
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
