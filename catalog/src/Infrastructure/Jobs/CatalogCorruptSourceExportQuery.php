<?php
/**
 * Builds the operator export of files whose immutable content is known to be
 * corrupt/invalid and therefore unsuitable for automatic retry.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogCorruptSourceExportQuery
{
    private const MAX_ROWS = 50000;

    private readonly CatalogJobSourceContextResolver $sources;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
    ) {
        $this->sources = new CatalogJobSourceContextResolver($db, $config);
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   scanned:int,
     *   exported:int,
     *   limited:bool
     * }
     */
    public function fetch(string $queue = '', string $jobType = '', string $search = ''): array
    {
        $where = [
            '(j.status IN ("failed","dead_letter") '
                . 'OR j.display_status IN ("failed","rejected","invalid_ue_package","error"))'
        ];
        $params = [];
        if ($queue !== '') {
            $where[] = 'j.queue_name=?';
            $params[] = $queue;
        }
        if ($jobType !== '') {
            $where[] = 'j.job_type=?';
            $params[] = $jobType;
        }
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where[] = '(CAST(j.id AS CHAR)=? OR j.payload_json LIKE ? ESCAPE "\\\\" '
                . 'OR COALESCE(j.last_error,"") LIKE ? ESCAPE "\\\\" '
                . 'OR COALESCE(j.result_json,"") LIKE ? ESCAPE "\\\\" '
                . 'OR COALESCE(j.progress_json,"") LIKE ? ESCAPE "\\\\")';
            array_push($params, ctype_digit($search) ? $search : '-1', $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare('SELECT COUNT(*) FROM ue_background_jobs j WHERE ' . $whereSql);
        $count->execute($params);
        $scanned = max(0, (int)$count->fetchColumn());

        $statement = $this->db->prepare(
            'SELECT j.id,j.queue_name,j.job_type,j.parent_job_id,j.status,j.display_status,'
            . 'j.payload_json,j.progress_json,j.result_json,j.last_error '
            . 'FROM ue_background_jobs j WHERE ' . $whereSql
            . ' ORDER BY j.id ASC LIMIT ' . self::MAX_ROWS
        );
        $statement->execute($params);

        $rows = [];
        $seenJobIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $candidate = $this->project($row);
            if ($candidate === null) {
                continue;
            }
            $jobId = max(0, (int)($candidate['job_id'] ?? 0));
            if ($jobId > 0) {
                $seenJobIds[$jobId] = true;
            }
            $rows[] = $candidate;
        }

        // Invalid UE content is also persisted independently in System Errors.
        // Some successful/current-code queue transitions no longer look like an
        // Issue row even though the corrupt retained source is still deliberately
        // open for operator removal. Merge those records so this export reflects
        // both operator surfaces instead of only ue_background_jobs.
        foreach ($this->openInvalidUeSystemErrors() as $systemError) {
            $context = $this->decode((string)($systemError['context_json'] ?? ''));
            $jobId = max(0, (int)($context['job_id'] ?? 0));
            if ($jobId > 0 && isset($seenJobIds[$jobId])) {
                continue;
            }

            $jobRow = $jobId > 0 ? $this->jobRow($jobId) : null;
            if (is_array($jobRow)) {
                if (!$this->matchesJobFilters($jobRow, $queue, $jobType, $search, $systemError, $context)) {
                    continue;
                }
                $candidate = $this->project(
                    $jobRow,
                    $this->systemErrorReason($systemError, $context),
                    true
                );
            } else {
                if ($queue !== '' || $jobType !== '' || ($search !== '' && !$this->matchesSystemErrorSearch($systemError, $context, $search))) {
                    continue;
                }
                $candidate = $this->projectSystemErrorOnly($systemError, $context);
            }

            if ($candidate === null) {
                continue;
            }
            if ($jobId > 0) {
                $seenJobIds[$jobId] = true;
            }
            $rows[] = $candidate;
        }

        usort($rows, static function (array $a, array $b): int {
            $left = strtolower((string)($a['source_relative_path'] ?? $a['file_name'] ?? ''));
            $right = strtolower((string)($b['source_relative_path'] ?? $b['file_name'] ?? ''));
            return $left <=> $right ?: ((int)$a['job_id'] <=> (int)$b['job_id']);
        });

        return [
            'rows' => $rows,
            'scanned' => min($scanned, self::MAX_ROWS),
            'exported' => count($rows),
            'limited' => $scanned > self::MAX_ROWS,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function project(
        array $row,
        string $forcedReason = '',
        bool $forceInvalidPackage = false
    ): ?array {
        $jobId = max(0, (int)($row['id'] ?? 0));
        $jobType = trim((string)($row['job_type'] ?? ''));
        $payload = $this->decode((string)($row['payload_json'] ?? ''));
        $progress = $this->decode((string)($row['progress_json'] ?? ''));
        $result = $this->decode((string)($row['result_json'] ?? ''));
        $displayStatus = strtolower(trim((string)($row['display_status'] ?? '')));
        $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
        $reason = trim($forcedReason) !== ''
            ? trim($forcedReason)
            : $this->failureText($row, $result, $progress);

        $invalidPackage = $forceInvalidPackage
            || $displayStatus === 'invalid_ue_package'
            || $resultStatus === 'invalid_ue_package';
        if (!$invalidPackage && !JobFailureRetryPolicy::isCorruptContentText($jobType, $reason)) {
            return null;
        }

        $context = [];
        try {
            if ($jobId > 0) {
                $context = $this->sources->forJobId($jobId);
            }
        } catch (Throwable $error) {
            $context['source_resolution_error'] = trim($error->getMessage()) !== ''
                ? trim($error->getMessage())
                : get_class($error);
        }

        $fileName = $this->firstText([
            $payload['original_name'] ?? '',
            $result['original_name'] ?? '',
            $context['job_original_name'] ?? '',
        ]);
        $sourceRelative = $this->firstText([
            $payload['source_relative_path'] ?? '',
            $result['source_relative_path'] ?? '',
            $context['job_source_relative_path'] ?? '',
        ]);
        if ($fileName === '' && $sourceRelative !== '') {
            $fileName = basename(str_replace('\\', '/', $sourceRelative));
        }

        $archiveEntry = $this->firstText([
            $payload['archive_entry_path'] ?? '',
            $context['archive_entry_path'] ?? '',
        ]);
        $archiveName = $this->firstText([
            $payload['archive_source_name'] ?? '',
            $context['archive_source_name'] ?? '',
        ]);

        $jobFullPath = $this->existingPath($context, 'job_full_path', 'job_full_path_exists');
        $archiveFullPath = $this->existingPath($context, 'archive_full_path', 'archive_full_path_exists');
        if ($archiveFullPath === '') {
            $archiveFullPath = $this->existingPath($context, 'parent_full_path', 'parent_full_path_exists');
        }

        $copyPath = $jobFullPath;
        $pathKind = $copyPath !== '' ? 'retained_file' : 'relative_only';
        if ($copyPath === '' && $archiveEntry === '' && $archiveFullPath !== '') {
            $copyPath = $archiveFullPath;
            $pathKind = 'retained_archive';
        } elseif ($copyPath === '' && $archiveEntry !== '' && $archiveFullPath !== '') {
            $pathKind = 'archive_member_only';
        }

        $classification = $invalidPackage
            ? 'invalid_unreal_package'
            : (in_array($jobType, [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE], true)
                ? 'corrupt_archive'
                : (preg_match('/\\b(?:uz|uz2|uz3)\\b/i', $reason) === 1
                    ? 'corrupt_redirect'
                    : 'corrupt_package'));

        $destinationRelative = str_replace('\\', '/', trim($sourceRelative, " /\\"));
        if ($destinationRelative === '') {
            $destinationRelative = $fileName;
        }

        return [
            'copy_path' => $copyPath,
            'copy_path_exists' => $copyPath !== '' && is_file($copyPath),
            'destination_relative_path' => $destinationRelative,
            'source_relative_path' => str_replace('\\', '/', $sourceRelative),
            'file_name' => $fileName,
            'archive_container_path' => $archiveFullPath,
            'archive_source_name' => $archiveName,
            'archive_entry_path' => str_replace('\\', '/', $archiveEntry),
            'path_kind' => $pathKind,
            'classification' => $classification,
            'job_id' => $jobId,
            'queue' => (string)($row['queue_name'] ?? ''),
            'job_type' => $jobType,
            'queue_status' => (string)($row['status'] ?? ''),
            'display_status' => $displayStatus,
            'reason' => $reason,
            'source_resolution_error' => (string)($context['source_resolution_error']
                ?? $context['job_path_resolution_error']
                ?? $context['job_chunk_path_error']
                ?? ''),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result @param array<string,mixed> $progress */
    private function failureText(array $row, array $result, array $progress): string
    {
        $lastError = trim((string)($row['last_error'] ?? ''));
        if ($lastError !== '') {
            return $lastError;
        }
        foreach ([$result, $progress] as $data) {
            $message = trim((string)($data['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
            $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $error = trim((string)($first['error'] ?? $first['message'] ?? ''));
            if ($error !== '') {
                return $error;
            }
        }
        return 'Invalid/corrupt immutable source content.';
    }

    /** @return list<array<string,mixed>> */
    private function openInvalidUeSystemErrors(): array
    {
        try {
            $statement = $this->db->query(
                'SELECT id,source_kind,error_type,message,context_json FROM ue_system_errors '
                . 'WHERE status="open" '
                . 'AND source_kind IN ("unreal-file-validation","background-job") '
                . 'AND JSON_VALID(context_json) '
                . 'ORDER BY id ASC LIMIT ' . self::MAX_ROWS
            );
            $rows = $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $corrupt = [];
            foreach ($rows as $row) {
                $context = $this->decode((string)($row['context_json'] ?? ''));
                $jobId = max(0, (int)($context['job_id'] ?? 0));
                $jobType = trim((string)($context['job_type'] ?? ''));
                if ($jobType === '' && $jobId > 0) {
                    $job = $this->jobRow($jobId);
                    $jobType = is_array($job) ? trim((string)($job['job_type'] ?? '')) : '';
                }

                $reason = $this->systemErrorReason($row, $context);
                $disposition = strtolower(trim((string)($context['disposition'] ?? '')));
                if ($disposition === 'invalid_ue_file'
                    || ($jobType !== '' && JobFailureRetryPolicy::isCorruptContentText($jobType, $reason))) {
                    $corrupt[] = $row;
                }
            }
            return $corrupt;
        } catch (Throwable) {
            // Older installs without System Error storage still retain the
            // Background Jobs half of the export.
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    private function jobRow(int $jobId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,queue_name,job_type,parent_job_id,status,display_status,'
            . 'payload_json,progress_json,result_json,last_error '
            . 'FROM ue_background_jobs WHERE id=? LIMIT 1'
        );
        $statement->execute([$jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $systemError @param array<string,mixed> $context */
    private function systemErrorReason(array $systemError, array $context): string
    {
        $message = trim((string)($systemError['message'] ?? ''));
        $fileName = trim((string)($context['file_name'] ?? ''));
        if ($fileName !== '' && str_starts_with($message, $fileName . ':')) {
            $message = trim(substr($message, strlen($fileName) + 1));
        }
        return $message !== '' ? $message : 'Invalid/corrupt Unreal package content.';
    }

    /**
     * @param array<string,mixed> $jobRow
     * @param array<string,mixed> $systemError
     * @param array<string,mixed> $context
     */
    private function matchesJobFilters(
        array $jobRow,
        string $queue,
        string $jobType,
        string $search,
        array $systemError,
        array $context
    ): bool {
        if ($queue !== '' && (string)($jobRow['queue_name'] ?? '') !== $queue) {
            return false;
        }
        if ($jobType !== '' && (string)($jobRow['job_type'] ?? '') !== $jobType) {
            return false;
        }
        return $search === '' || $this->matchesSystemErrorSearch($systemError, $context, $search)
            || stripos((string)($jobRow['payload_json'] ?? ''), $search) !== false
            || stripos((string)($jobRow['last_error'] ?? ''), $search) !== false;
    }

    /** @param array<string,mixed> $systemError @param array<string,mixed> $context */
    private function matchesSystemErrorSearch(array $systemError, array $context, string $search): bool
    {
        $needle = strtolower(trim($search));
        if ($needle === '') {
            return true;
        }
        $haystack = strtolower(
            (string)($systemError['message'] ?? '') . "\n"
            . (string)($context['file_name'] ?? '') . "\n"
            . (string)($context['source_relative_path'] ?? '') . "\n"
            . (string)($context['archive_source_name'] ?? '') . "\n"
            . (string)($context['archive_entry_path'] ?? '') . "\n"
            . (string)($context['md5'] ?? '') . "\n"
            . (string)($context['sha1'] ?? '')
        );
        return str_contains($haystack, $needle);
    }

    /** @param array<string,mixed> $systemError @param array<string,mixed> $context @return array<string,mixed> */
    private function projectSystemErrorOnly(array $systemError, array $context): array
    {
        $fileName = trim((string)($context['file_name'] ?? ''));
        $sourceRelative = trim(str_replace('\\', '/', (string)($context['source_relative_path'] ?? '')), '/');
        if ($fileName === '' && $sourceRelative !== '') {
            $fileName = basename($sourceRelative);
        }
        $archiveName = trim((string)($context['archive_source_name'] ?? ''));
        $archiveEntry = trim(str_replace('\\', '/', (string)($context['archive_entry_path'] ?? '')), '/');
        $destinationRelative = $sourceRelative !== '' ? $sourceRelative : $fileName;

        return [
            'copy_path' => '',
            'copy_path_exists' => false,
            'destination_relative_path' => $destinationRelative,
            'source_relative_path' => $sourceRelative,
            'file_name' => $fileName,
            'archive_container_path' => '',
            'archive_source_name' => $archiveName,
            'archive_entry_path' => $archiveEntry,
            'path_kind' => $archiveEntry !== '' ? 'archive_member_only' : 'relative_only',
            'classification' => 'invalid_unreal_package',
            'job_id' => max(0, (int)($context['job_id'] ?? 0)),
            'queue' => '',
            'job_type' => trim((string)($context['job_type'] ?? '')),
            'queue_status' => '',
            'display_status' => 'invalid_ue_package',
            'reason' => $this->systemErrorReason($systemError, $context),
            'source_resolution_error' => 'Background job is no longer retained; only System Error provenance remains.',
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $context */
    private function existingPath(array $context, string $pathKey, string $existsKey): string
    {
        $path = trim((string)($context[$pathKey] ?? ''));
        if ($path === '') {
            return '';
        }
        if (array_key_exists($existsKey, $context) && empty($context[$existsKey])) {
            return '';
        }
        return is_file($path) ? $path : '';
    }

    /** @param list<mixed> $values */
    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }
}
