<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates move, import and delete actions for one resolved unverified file.
 * Why: The HTTP action endpoint should own session/CSRF/progress transport, not catalog mutation branching or result semantics.
 * Role: Infrastructure/application-facing unverified action service over dedicated queue-mutation and import services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;

final class CatalogUnverifiedActionService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param array<string,mixed> $source
     * @param null|callable(string,int,string):void $emit
     * @return array<string,mixed>
     */
    public function execute(
        string $action,
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowOverride,
        ?callable $emit = null
    ): array {
        if ($action === 'move') {
            $this->emit($emit, 'moving', 25, 'Moving queued file');
            $result = (new CatalogUnverifiedQueueMutationService($this->db, $this->config))
                ->move($source, $targetGameId);
            $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
            $this->emit($emit, 'done', 100, $message);
            return $this->response($action, $result, null, '', null, $message);
        }

        if ($action === 'import') {
            $import = (new CatalogUnverifiedImportService($this->db, $this->config))->import(
                $source,
                $targetGameId,
                $userId,
                $allowOverride,
                $emit
            );
            $result = $import['result'];
            $details = is_array($import['details'] ?? null) ? $import['details'] : [];
            $warning = (string)($import['warning'] ?? '');
            $recovery = is_array($import['recovery'] ?? null) ? $import['recovery'] : null;
            $message = $this->importMessage($result, $details, $warning, $recovery);
            $this->emit($emit, 'done', 100, $message);
            return $this->response($action, $result, $details, $warning, $recovery, $message);
        }

        if ($action === 'delete') {
            $this->emit($emit, 'deleting', 25, 'Deleting queued file');
            $result = (new CatalogUnverifiedQueueMutationService($this->db, $this->config))
                ->discard($source);
            $message = 'Deleted ' . $result['original_name']
                . ' from unverified storage and the staging database.';
            $this->emit($emit, 'done', 100, $message);
            return $this->response($action, $result, null, '', null, $message);
        }

        throw new RuntimeException('Unknown unverified queue action.');
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $details @param array<string,mixed>|null $recovery */
    private function importMessage(array $result, array $details, string $warning, ?array $recovery): string
    {
        $guid = trim((string)($details['package_guid'] ?? ''));
        $statusLabel = match (strtolower((string)($result['status'] ?? ''))) {
            'verified' => 'Imported',
            'duplicate' => 'Duplicate',
            'alias' => 'Alias added',
            default => ucfirst((string)($result['status'] ?? 'completed')),
        };
        $message = $statusLabel . ' ' . (string)$result['original_name']
            . ' for ' . (string)$result['target_game'] . '. N/I/E: '
            . (int)($details['name_count'] ?? 0) . '/'
            . (int)($details['import_count'] ?? 0) . '/'
            . (int)($details['export_count'] ?? 0)
            . ' | GUID: ' . ($guid !== '' ? $guid : 'N/A') . '.';

        $dependencyJobs = is_array($result['dependency_jobs'] ?? null)
            ? $result['dependency_jobs']
            : [];
        if ((int)($dependencyJobs['search_job_id'] ?? 0) > 0) {
            $message .= ' Search projection queued as job #'
                . (int)$dependencyJobs['search_job_id'] . '.';
        }
        if ((int)($dependencyJobs['file_job_id'] ?? 0) > 0) {
            $message .= ' Dependency scan queued as job #'
                . (int)$dependencyJobs['file_job_id'] . '.';
        }
        if ((int)($dependencyJobs['affected_job_id'] ?? 0) > 0) {
            $message .= ' Affected-file refresh queued as job #'
                . (int)$dependencyJobs['affected_job_id'] . '.';
        }
        if (is_array($recovery) && !empty($recovery['recovered'])) {
            $message .= ' Dependency repair: ' . (string)($recovery['message'] ?? 'recovered.');
        }
        if ($warning !== '') {
            $message .= ' Warning: ' . $warning;
        }
        return $message;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $details
     * @param array<string,mixed>|null $recovery
     * @return array<string,mixed>
     */
    private function response(
        string $action,
        array $result,
        ?array $details,
        string $warning,
        ?array $recovery,
        string $message
    ): array {
        return [
            'ok' => true,
            'action' => $action,
            'original_name' => (string)$result['original_name'],
            'file_id' => isset($result['file_id']) ? (int)$result['file_id'] : null,
            'details' => $details,
            'warning' => $warning !== '' ? $warning : null,
            'recovery' => $recovery,
            'dependency_jobs' => is_array($result['dependency_jobs'] ?? null)
                ? $result['dependency_jobs']
                : null,
            'identity_reused' => !empty($result['identity_reused']),
            'message' => $message,
        ];
    }

    private function emit(?callable $emit, string $stage, int $percent, string $message): void
    {
        if ($emit !== null) {
            $emit($stage, $percent, $message);
        }
    }
}
