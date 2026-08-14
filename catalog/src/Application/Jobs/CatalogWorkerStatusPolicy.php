<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Derives the administrator-visible worker state from detached-process state and durable queue counts.
 * Why: Worker status policy is application behaviour and must not be duplicated inside HTTP endpoints.
 * Role: Pure Application policy; no PDO, filesystem, process, HTTP, session or rendering dependencies.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

final class CatalogWorkerStatusPolicy
{
    /** @param array<string,mixed> $worker */
    public static function activeProcessed(array $worker): int
    {
        $processed = 0;
        foreach ((array)($worker['workers'] ?? []) as $slotWorker) {
            if (!is_array($slotWorker) || empty($slotWorker['active'])) {
                continue;
            }
            $state = is_array($slotWorker['state'] ?? null) ? $slotWorker['state'] : [];
            $processed += max(0, (int)($state['processed'] ?? 0));
        }
        return $processed;
    }

    /**
     * @param array<string,mixed> $worker
     * @param array{queued:int,ready:int,running:int,terminal:int,total:int} $counts
     * @return array{authoritative_status:string,authoritative_message:string,restart_recommended:bool}
     */
    public static function evaluate(array $worker, array $counts, int $fallbackDesiredCount): array
    {
        $active = !empty($worker['active']);
        $activeCount = max(0, (int)($worker['active_count'] ?? 0));
        $launchingCount = max(0, (int)($worker['launching_count'] ?? 0));
        $desiredCount = max(1, (int)($worker['desired_count'] ?? $fallbackDesiredCount));
        $workerState = is_array($worker['state'] ?? null) ? $worker['state'] : [];
        $stateStatus = strtolower(trim((string)($workerState['status'] ?? '')));
        $exitReason = strtolower(trim((string)($workerState['exit_reason'] ?? '')));
        $lastError = trim((string)($workerState['error'] ?? ''));

        /*
         * Process ownership is authoritative. A live detached worker must never be
         * presented as "stopped" merely because it happens to be between claims.
         * The exact currently-owned rows are reported separately by runningWork().
         */
        if ($active) {
            if ($counts['ready'] > 0 && $activeCount < $desiredCount && $launchingCount === 0) {
                return [
                    'authoritative_status' => 'degraded',
                    'authoritative_message' => $activeCount . ' of ' . $desiredCount
                        . ' detached worker process(es) are running while ready work remains. '
                        . 'A surviving worker will reconcile the missing slot(s) automatically.',
                    'restart_recommended' => false,
                ];
            }

            $message = $activeCount . ' of ' . $desiredCount . ' detached worker process(es) are running.';
            if ($launchingCount > 0) {
                $message .= ' ' . $launchingCount . ' additional process(es) are launching.';
            }
            if ($counts['ready'] > 0 && $counts['running'] === 0) {
                $message .= ' No queue row is owned at this instant; workers are between claims or waiting for their current workflow to become runnable.';
            }

            return [
                'authoritative_status' => 'running',
                'authoritative_message' => $message,
                'restart_recommended' => false,
            ];
        }

        if ($counts['running'] > 0) {
            return [
                'authoritative_status' => 'orphaned',
                'authoritative_message' => $counts['running']
                    . ' database job(s) still say running, but no detached worker process owns this queue.',
                'restart_recommended' => false,
            ];
        }

        if ($counts['queued'] > 0) {
            if ($stateStatus === 'failed' || in_array($exitReason, ['fatal_shutdown', 'uncaught_exception'], true)) {
                $message = 'Worker pool stopped after a failure with ' . $counts['queued'] . ' queued job(s).';
                if ($lastError !== '') {
                    $message .= ' ' . $lastError;
                }
            } elseif ($counts['ready'] === 0) {
                $message = 'Worker pool is stopped with ' . $counts['queued'] . ' queued job(s), but none is ready yet.';
            } elseif ($exitReason === 'queue_empty') {
                $message = 'Worker pool exited without claiming any of the ' . $counts['ready']
                    . ' ready queued job(s). Review the worker log and restart explicitly.';
            } elseif ($launchingCount > 0) {
                $message = 'A worker launch was requested but no process owns a worker lock.';
            } else {
                $message = 'Worker pool is stopped with ' . $counts['queued'] . ' queued job(s).';
            }

            return [
                'authoritative_status' => 'stopped_with_queue',
                'authoritative_message' => $message,
                'restart_recommended' => false,
            ];
        }

        return [
            'authoritative_status' => 'stopped',
            'authoritative_message' => 'Worker pool is stopped and the queue has no active work.',
            'restart_recommended' => false,
        ];
    }
}
