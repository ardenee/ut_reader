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

    public function __construct(private readonly PDO $db)
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

    /**
     * Find recent active/completed jobs for one normalized build identity.
     *
     * @return list<array<string,mixed>>
     */
    public function reusableCandidates(string $queueName, string $buildKey, int $limit = 50): array
    {
        $queueName = trim($queueName);
        $buildKey = strtolower(trim($buildKey));
        if ($queueName === '' || $buildKey === '' || preg_match('/^[a-f0-9]{64}$/', $buildKey) !== 1) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $statement = $this->db->prepare(
            'SELECT id,queue_name,job_type,priority,max_attempts,payload_json,status,progress_json,result_json,last_error,'
            . 'cancel_requested_at,created_at,updated_at,completed_at '
            . 'FROM ue_background_jobs '
            . 'WHERE queue_name=? AND job_type=? AND status IN ("queued","running","completed") '
            . 'ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute([$queueName, JobType::GENERATE_MOD_PACKAGE]);

        $matches = [];
        while (($job = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = $this->jsonObject((string)($job['payload_json'] ?? ''));
            if (!is_array($payload)
                || !hash_equals($buildKey, strtolower(trim((string)($payload['build_key'] ?? ''))))) {
                continue;
            }
            $job['payload'] = $payload;
            $matches[] = $job;
        }
        return $matches;
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

    /** @param array<string,mixed> $job */
    public function isAuthorizedGrant(array $job, string $grant): bool
    {
        $grant = trim($grant);
        if (str_starts_with($grant, 'build:')) {
            $payload = is_array($job['payload'] ?? null)
                ? $job['payload']
                : $this->jsonObject((string)($job['payload_json'] ?? ''));
            $expected = is_array($payload) ? strtolower(trim((string)($payload['build_key'] ?? ''))) : '';
            $actual = strtolower(substr($grant, 6));
            return $expected !== '' && preg_match('/^[a-f0-9]{64}$/', $actual) === 1
                && hash_equals($expected, $actual);
        }
        return $this->isAuthorized($job, $grant);
    }

    /** @return array<string,mixed>|null */
    public function findAuthorized(int $jobId, string $grant): ?array
    {
        $job = $this->find($jobId);
        return $job !== null && $this->isAuthorizedGrant($job, $grant) ? $job : null;
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
