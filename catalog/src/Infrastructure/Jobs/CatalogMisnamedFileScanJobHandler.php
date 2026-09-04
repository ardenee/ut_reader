<?php
/**
 * Durable catalogue-wide scan for likely historical filename/package-name corruption.
 *
 * Each turn examines a small number of community files that currently own true
 * missing imports, persists the ranked candidate state in job progress, then yields
 * the worker. Common/base-game dependency noise is intentionally excluded.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogMisnamedFileDetector;

final class CatalogMisnamedFileScanJobHandler implements JobHandler
{
    private const POLICY_VERSION = 'community-path-name-copy-suffix-v4';
    private const OWNER_BATCH_SIZE = 8;
    private const MAX_PROGRESS_CANDIDATES = 1000;
    private const MAX_RESULT_CANDIDATES = 500;
    private const MAX_EVIDENCE_FILES = 5;
    private const MAX_TRACKED_OBJECT_TERMS = 128;

    public function __construct(private readonly PDO $db)
    {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::SCAN_POSSIBLE_MISNAMED_FILES;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = max(0, (int)($job->payload['game_id'] ?? 0));
        $resume = $context->resumeProgress();
        if ((string)($resume['policy_version'] ?? '') !== self::POLICY_VERSION) {
            // A worker may resume a job created by an older detector policy. Do
            // not retain candidates gathered before strict path/name matching.
            $resume = [];
        }

        $snapshotMaxId = max(0, (int)($resume['snapshot_max_file_id'] ?? 0));
        if ($snapshotMaxId < 1) {
            $snapshotMaxId = $this->snapshotMaxFileId($gameId);
        }

        $cursor = max(0, (int)($resume['cursor_file_id'] ?? 0));
        $scannedOwners = max(0, (int)($resume['scanned_owner_files'] ?? 0));
        $importsExamined = max(0, (int)($resume['imports_examined'] ?? 0));
        $ambiguousTerms = max(0, (int)($resume['ambiguous_terms_skipped'] ?? 0));
        $truncatedOwners = max(0, (int)($resume['truncated_owner_files'] ?? 0));
        $candidateState = is_array($resume['candidate_state'] ?? null)
            ? $resume['candidate_state']
            : [];

        if ($snapshotMaxId < 1) {
            return $this->completeResult($gameId, 0, 0, 0, 0, []);
        }

        $ownerIds = $this->nextOwnerIds($gameId, $cursor, $snapshotMaxId);
        if ($ownerIds === []) {
            $result = $this->completeResult(
                $gameId,
                $scannedOwners,
                $importsExamined,
                $ambiguousTerms,
                $truncatedOwners,
                $candidateState
            );
            $context->checkpoint([
                'stage' => 'complete',
                'percent' => 100,
                'done' => 100,
                'total' => 100,
                'message' => (string)$result['message'],
                'policy_version' => self::POLICY_VERSION,
                'scanned_owner_files' => $scannedOwners,
                'candidate_count' => count((array)$result['candidates']),
            ]);
            return $result;
        }

        $detector = new CatalogMisnamedFileDetector($this->db);
        foreach ($ownerIds as $ownerId) {
            $scan = $detector->scanOwner($ownerId);
            $scannedOwners++;
            $importsExamined += max(0, (int)($scan['imports_examined'] ?? 0));
            $ambiguousTerms += max(0, (int)($scan['ambiguous_terms'] ?? 0));
            if (!empty($scan['truncated'])) {
                $truncatedOwners++;
            }
            foreach ((array)($scan['candidates'] ?? []) as $candidate) {
                if (is_array($candidate)) {
                    $this->mergeCandidate($candidateState, $candidate);
                }
            }
            $cursor = max($cursor, $ownerId);
            $candidateState = $this->trimState($candidateState, self::MAX_PROGRESS_CANDIDATES);
            $context->heartbeatIfDue($this->progress(
                $snapshotMaxId,
                $cursor,
                $scannedOwners,
                $importsExamined,
                $ambiguousTerms,
                $truncatedOwners,
                $candidateState
            ));
        }

        $context->defer(
            1,
            $this->progress(
                $snapshotMaxId,
                $cursor,
                $scannedOwners,
                $importsExamined,
                $ambiguousTerms,
                $truncatedOwners,
                $candidateState
            ),
            false
        );
    }

    /** @return list<int> */
    private function nextOwnerIds(int $gameId, int $cursor, int $snapshotMaxId): array
    {
        $sql = 'SELECT f.id FROM ue_files f '
            . 'WHERE f.id>? AND f.id<=? AND f.scan_status="verified" ';
        $arguments = [$cursor, $snapshotMaxId];
        if ($gameId > 0) {
            $sql .= 'AND f.game_id=? ';
            $arguments[] = $gameId;
        }
        $sql .= 'AND NOT EXISTS ('
            . 'SELECT 1 FROM ue_base_game_files bg '
            . 'WHERE bg.game_id=f.game_id AND bg.source_file_id=f.id'
            . ') '
            . 'AND EXISTS ('
            . 'SELECT 1 FROM ue_dependency_links d WHERE d.file_id=f.id '
            . 'AND d.status=0 AND d.resolved_file_id IS NULL '
            . 'AND d.required_package_term_id IS NOT NULL AND d.import_object_term_id IS NOT NULL '
            . 'AND d.required_path_hash IS NOT NULL'
            . ') ORDER BY f.id LIMIT ' . self::OWNER_BATCH_SIZE;

        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        return array_values(array_filter(
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn(int $id): bool => $id > 0
        ));
    }

    private function snapshotMaxFileId(int $gameId): int
    {
        if ($gameId > 0) {
            $statement = $this->db->prepare(
                'SELECT COALESCE(MAX(id),0) FROM ue_files WHERE game_id=? AND scan_status="verified"'
            );
            $statement->execute([$gameId]);
        } else {
            $statement = $this->db->query(
                'SELECT COALESCE(MAX(id),0) FROM ue_files WHERE scan_status="verified"'
            );
        }
        return max(0, (int)($statement->fetchColumn() ?: 0));
    }

    /** @param array<string,array<string,mixed>> $state @param array<string,mixed> $candidate */
    private function mergeCandidate(array &$state, array $candidate): void
    {
        $candidateFileId = (int)($candidate['candidate_file_id'] ?? 0);
        $suggestedPackage = trim((string)($candidate['suggested_package_name'] ?? ''));
        if ($candidateFileId < 1 || $suggestedPackage === '') {
            return;
        }
        $packageKey = function_exists('mb_strtolower')
            ? mb_strtolower($suggestedPackage, 'UTF-8')
            : strtolower($suggestedPackage);
        $key = $candidateFileId . ':' . hash('sha256', $packageKey);
        $candidate['candidate_key'] = $key;

        if (!isset($state[$key]) || !is_array($state[$key])) {
            $candidate['matched_object_term_ids'] = array_slice(array_values(array_unique(array_filter(
                array_map('intval', (array)($candidate['matched_object_term_ids'] ?? [])),
                static fn(int $id): bool => $id > 0
            ))), 0, self::MAX_TRACKED_OBJECT_TERMS);
            $candidate['matching_objects'] = count($candidate['matched_object_term_ids']);
            $candidate['matching_files'] = max(1, (int)($candidate['matching_files'] ?? 1));
            $candidate['evidence'] = array_slice((array)($candidate['evidence'] ?? []), 0, self::MAX_EVIDENCE_FILES);
            $state[$key] = CatalogMisnamedFileDetector::rankCandidate($candidate);
            return;
        }

        $existing = $state[$key];
        $terms = [];
        foreach (array_merge(
            (array)($existing['matched_object_term_ids'] ?? []),
            (array)($candidate['matched_object_term_ids'] ?? [])
        ) as $termId) {
            $termId = (int)$termId;
            if ($termId > 0) {
                $terms[$termId] = true;
            }
            if (count($terms) >= self::MAX_TRACKED_OBJECT_TERMS) {
                break;
            }
        }
        $existing['matched_object_term_ids'] = array_map('intval', array_keys($terms));
        $existing['matching_objects'] = count($existing['matched_object_term_ids']);
        $existing['matching_files'] = max(1, (int)($existing['matching_files'] ?? 1)) + 1;
        $existing['best_same_file_matches'] = max(
            (int)($existing['best_same_file_matches'] ?? 0),
            (int)($candidate['best_same_file_matches'] ?? 0)
        );
        $existing['current_dependants'] = max(
            (int)($existing['current_dependants'] ?? 0),
            (int)($candidate['current_dependants'] ?? 0)
        );

        $evidence = (array)($existing['evidence'] ?? []);
        if (count($evidence) < self::MAX_EVIDENCE_FILES) {
            foreach ((array)($candidate['evidence'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $evidence[] = $item;
                if (count($evidence) >= self::MAX_EVIDENCE_FILES) {
                    break;
                }
            }
        }
        $existing['evidence'] = $evidence;
        $state[$key] = CatalogMisnamedFileDetector::rankCandidate($existing);
    }

    /** @param array<string,array<string,mixed>> $state @return array<string,array<string,mixed>> */
    private function trimState(array $state, int $limit): array
    {
        if (count($state) <= $limit) {
            return $state;
        }
        $rows = array_values($state);
        usort($rows, [CatalogMisnamedFileDetector::class, 'compareCandidates']);
        $rows = array_slice($rows, 0, $limit);
        $trimmed = [];
        foreach ($rows as $row) {
            $key = (string)($row['candidate_key'] ?? '');
            if ($key !== '') {
                $trimmed[$key] = $row;
            }
        }
        return $trimmed;
    }

    /** @param array<string,array<string,mixed>> $candidateState @return array<string,mixed> */
    private function progress(
        int $snapshotMaxId,
        int $cursor,
        int $scannedOwners,
        int $importsExamined,
        int $ambiguousTerms,
        int $truncatedOwners,
        array $candidateState
    ): array {
        $percent = min(99, max(1, (int)floor(($cursor * 100) / max(1, $snapshotMaxId))));
        return [
            'stage' => 'misnamed_file_scan',
            'percent' => $percent,
            'done' => $cursor,
            'total' => $snapshotMaxId,
            'message' => 'Checked ' . $scannedOwners . ' community file(s) with true missing imports; '
                . count($candidateState) . ' possible rename candidate(s) retained.',
            'policy_version' => self::POLICY_VERSION,
            'snapshot_max_file_id' => $snapshotMaxId,
            'cursor_file_id' => $cursor,
            'scanned_owner_files' => $scannedOwners,
            'imports_examined' => $importsExamined,
            'ambiguous_terms_skipped' => $ambiguousTerms,
            'truncated_owner_files' => $truncatedOwners,
            'candidate_state' => $candidateState,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $candidateState
     * @return array<string,mixed>
     */
    private function completeResult(
        int $gameId,
        int $scannedOwners,
        int $importsExamined,
        int $ambiguousTerms,
        int $truncatedOwners,
        array $candidateState
    ): array {
        $rows = array_values($candidateState);
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                $row = [];
                continue;
            }
            $row = CatalogMisnamedFileDetector::rankCandidate($row);
            unset($row['candidate_key'], $row['matched_object_term_ids']);
        }
        unset($row);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => $row !== []));
        usort($rows, [CatalogMisnamedFileDetector::class, 'compareCandidates']);
        $rows = array_slice($rows, 0, self::MAX_RESULT_CANDIDATES);

        $confidence = ['very_high' => 0, 'high' => 0, 'possible' => 0];
        foreach ($rows as $row) {
            $key = (string)($row['confidence'] ?? 'possible');
            if (isset($confidence[$key])) {
                $confidence[$key]++;
            }
        }

        return [
            'operation' => 'scan_possible_misnamed_files',
            'policy_version' => self::POLICY_VERSION,
            'game_id' => $gameId,
            'scanned_owner_files' => $scannedOwners,
            'imports_examined' => $importsExamined,
            'ambiguous_terms_skipped' => $ambiguousTerms,
            'truncated_owner_files' => $truncatedOwners,
            'candidate_count' => count($rows),
            'confidence_counts' => $confidence,
            'candidates' => $rows,
            'message' => 'Possible misnamed-file scan complete: ' . count($rows)
                . ' ranked community candidate(s) retained from ' . $scannedOwners
                . ' file(s) with true missing imports.',
        ];
    }
}
