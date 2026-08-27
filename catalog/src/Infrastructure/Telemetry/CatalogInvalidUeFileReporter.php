<?php
/**
 * Records invalid Unreal package content as a System Error.
 *
 * This reporter is intentionally separate from archive/job retry reporting:
 * archive extraction may have succeeded perfectly even when the extracted
 * member bytes are not a valid supported Unreal package.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier;

final class CatalogInvalidUeFileReporter
{
    /** @param array<string,mixed> $data */
    public static function record(array $data): bool
    {
        $jobId = max(0, (int)($data['job_id'] ?? 0));
        $parentJobId = max(0, (int)($data['parent_job_id'] ?? 0));
        $jobType = trim((string)($data['job_type'] ?? ''));
        $fileId = max(0, (int)($data['file_id'] ?? 0));
        $gameId = max(0, (int)($data['game_id'] ?? 0));
        $fileName = trim((string)($data['file_name'] ?? ''));
        if ($fileName === '') {
            $fileName = 'unknown Unreal file';
        }
        $sourceRelativePath = trim(str_replace('\\', '/', (string)($data['source_relative_path'] ?? '')), '/');
        $archiveSourceName = trim((string)($data['archive_source_name'] ?? ''));
        $archiveEntryPath = trim(str_replace('\\', '/', (string)($data['archive_entry_path'] ?? '')), '/');
        $reason = trim((string)($data['reason'] ?? 'Invalid Unreal package.'));
        $classified = CatalogInvalidUeErrorClassifier::classify(
            $reason,
            trim((string)($data['error_code'] ?? '')),
            is_array($data['arguments'] ?? null) ? $data['arguments'] : []
        );
        $reason = $classified['reason'];
        $md5 = strtolower(trim((string)($data['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($data['sha1'] ?? '')));
        $size = max(0, (int)($data['size'] ?? 0));
        $userId = max(0, (int)($data['user_id'] ?? 0));

        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            $md5 = '';
        }
        if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            $sha1 = '';
        }

        // Prefer physical content identity so repeated copies of the same corrupt
        // dependency collapse into one System Error occurrence counter.
        $identity = $md5 !== ''
            ? 'md5:' . $md5
            : ($sha1 !== '' ? 'sha1:' . $sha1 : 'job:' . max(1, $jobId));
        $route = 'invalid-ue-file:' . $identity;

        $archiveMember = $parentJobId > 0 || $archiveSourceName !== '';
        $context = [
            'job_id' => $jobId,
            'parent_job_id' => $parentJobId,
            'job_type' => $jobType,
            'disposition' => 'invalid_ue_file',
            'source_provenance' => $archiveMember ? 'archive_member' : 'direct_file',
            'validation_code' => $classified['code'],
            'validation_group' => $classified['group'],
            'validation_arguments' => $classified['arguments'],
            'file_id' => $fileId,
            'game_id' => $gameId,
            'file_name' => $fileName,
            'source_relative_path' => $sourceRelativePath,
            'size' => $size,
            'md5' => $md5,
            'sha1' => $sha1,
        ];
        if ($archiveMember) {
            if ($archiveSourceName !== '') {
                $context['archive_source_name'] = $archiveSourceName;
            }
            if ($archiveEntryPath !== '') {
                $context['archive_entry_path'] = $archiveEntryPath;
            }
        }

        return CatalogSystemErrorRecorder::record([
            'source_kind' => 'unreal-file-validation',
            'severity' => 'error',
            'error_type' => $classified['error_type'],
            'message' => $fileName . ': ' . $reason,
            'route' => $route,
            'source_file' => '',
            'source_line' => 0,
            'user_id' => $userId,
            'context' => $context,
        ]);
    }
}
