<?php
/**
 * Application use case for move, import and delete actions on one resolved unverified file.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified;

use RuntimeException;

final class CatalogUnverifiedActionService
{
    public function __construct(
        private readonly CatalogUnverifiedQueueMutation $queueMutation,
        private readonly CatalogUnverifiedImporter $importer
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
            if ($targetGameId < 1) {
                throw new RuntimeException('Moving a queued file requires one target game.');
            }
            $this->emit($emit, 'moving', 25, 'Moving queued file');
            $result = $this->queueMutation->move($source, $targetGameId);
            $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
            $this->emit($emit, 'done', 100, $message);
            return $this->response($action, $result, null, '', null, $message);
        }

        if ($action === 'import') {
            $import = $targetGameId === -1
                ? $this->importer->importExactCompatibleGames($source, $userId, $emit)
                : $this->importer->import($source, $targetGameId, $userId, $allowOverride, $emit);
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
            $result = $this->queueMutation->discard($source);
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

        if (!empty($result['multi_game'])) {
            $primaryGame = trim((string)($result['primary_game'] ?? $result['target_game'] ?? 'primary game'));
            $queued = is_array($result['queued_game_jobs'] ?? null) ? $result['queued_game_jobs'] : [];
            $message = $statusLabel . ' ' . (string)$result['original_name'] . ' for ' . $primaryGame . '. ';
            if ($queued !== []) {
                $queuedLabels = [];
                foreach ($queued as $entry) {
                    $queuedLabels[] = (string)($entry['game_name'] ?? 'game')
                        . ' (job #' . (int)($entry['job_id'] ?? 0) . ')';
                }
                $message .= 'Queued verified copies for ' . implode(', ', $queuedLabels) . '. ';
            } else {
                $message .= 'No additional game copy jobs were needed. ';
            }
            $message .= 'Only exact compatible dependency matches were included. N/I/E: '
                . (int)($details['name_count'] ?? 0) . '/'
                . (int)($details['import_count'] ?? 0) . '/'
                . (int)($details['export_count'] ?? 0)
                . ' | GUID: ' . ($guid !== '' ? $guid : 'N/A') . '.';
        } else {
            $message = $statusLabel . ' ' . (string)$result['original_name']
                . ' for ' . (string)$result['target_game'] . '. N/I/E: '
                . (int)($details['name_count'] ?? 0) . '/'
                . (int)($details['import_count'] ?? 0) . '/'
                . (int)($details['export_count'] ?? 0)
                . ' | GUID: ' . ($guid !== '' ? $guid : 'N/A') . '.';
        }

        $dependencyJobs = is_array($result['dependency_jobs'] ?? null) ? $result['dependency_jobs'] : [];
        if ((int)($dependencyJobs['search_job_id'] ?? 0) > 0) {
            $message .= ' Search projection queued as job #' . (int)$dependencyJobs['search_job_id'] . '.';
        }
        if ((int)($dependencyJobs['file_job_id'] ?? 0) > 0) {
            $message .= ' Dependency scan queued as job #' . (int)$dependencyJobs['file_job_id'] . '.';
        }
        if ((int)($dependencyJobs['affected_job_id'] ?? 0) > 0) {
            $message .= ' Affected-file refresh queued as job #' . (int)$dependencyJobs['affected_job_id'] . '.';
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
            'dependency_jobs' => is_array($result['dependency_jobs'] ?? null) ? $result['dependency_jobs'] : null,
            'queued_game_jobs' => is_array($result['queued_game_jobs'] ?? null) ? $result['queued_game_jobs'] : null,
            'target_games' => is_array($result['target_games'] ?? null) ? $result['target_games'] : null,
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
