<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads and authorizes generated-package jobs using the caller-owned browser-session token.
 * Why: The generation status and download endpoints must share one durable-job lookup and token-hash contract while preserving their distinct error states.
 * Role: Infrastructure authorization/read adapter; session and HTTP concerns stay in Presentation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobLookupQuery;

final class CatalogGeneratedPackageJobAccess
{
    private readonly PdoBackgroundJobLookupQuery $jobs;

    public function __construct(PDO $db)
    {
        $this->jobs = new PdoBackgroundJobLookupQuery($db);
    }

    /** @return array<string,mixed>|null */
    public function find(int $jobId): ?array
    {
        if ($jobId < 1) {
            return null;
        }
        $job = $this->jobs->findByIdAndType($jobId, JobType::GENERATE_MOD_PACKAGE);
        if ($job === null) {
            return null;
        }
        $job['payload'] = $this->jsonObject((string)($job['payload_json'] ?? ''));
        return $job;
    }

    /** @param array<string,mixed> $job */
    public function isAuthorized(array $job, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $payload = is_array($job['payload'] ?? null)
            ? $job['payload']
            : $this->jsonObject((string)($job['payload_json'] ?? ''));
        $expected = is_array($payload) ? trim((string)($payload['access_token_hash'] ?? '')) : '';
        return $expected !== '' && hash_equals($expected, hash('sha256', $token));
    }

    /** @return array<string,mixed>|null */
    public function findAuthorized(int $jobId, string $token): ?array
    {
        $job = $this->find($jobId);
        return $job !== null && $this->isAuthorized($job, $token) ? $job : null;
    }

    /** @return array<string,mixed>|null */
    private function jsonObject(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
