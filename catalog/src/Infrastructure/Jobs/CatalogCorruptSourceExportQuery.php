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
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $candidate = $this->project($row);
            if ($candidate === null) {
                continue;
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
    private function project(array $row): ?array
    {
        $jobId = max(0, (int)($row['id'] ?? 0));
        $jobType = trim((string)($row['job_type'] ?? ''));
        $payload = $this->decode((string)($row['payload_json'] ?? ''));
        $progress = $this->decode((string)($row['progress_json'] ?? ''));
        $result = $this->decode((string)($row['result_json'] ?? ''));
        $displayStatus = strtolower(trim((string)($row['display_status'] ?? '')));
        $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
        $reason = $this->failureText($row, $result, $progress);

        $invalidPackage = $displayStatus === 'invalid_ue_package'
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

        return [
            'copy_path' => $copyPath,
            'copy_path_exists' => $copyPath !== '' && is_file($copyPath),
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
