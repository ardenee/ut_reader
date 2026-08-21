<?php
/**
 * Classifies durable worker failures into retryable/transient versus deterministic.
 *
 * Most jobs retry with exponential backoff. Some failures are properties of the
 * immutable staged bytes themselves, so replaying the same source only wastes
 * worker capacity and repeats identical System Errors.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use Throwable;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class JobFailureRetryPolicy
{
    public static function retryDelaySeconds(ClaimedJob $job, Throwable $error): int
    {
        if (self::isDeterministicArchiveFailure($job, $error)) {
            return 0;
        }

        return min(300, max(1, 2 ** min(8, $job->attempt)));
    }

    private static function isDeterministicArchiveFailure(ClaimedJob $job, Throwable $error): bool
    {
        if (!in_array($job->type, [
            JobType::PROCESS_BUCKET_ARCHIVE,
            JobType::IMPORT_STAGED_ARCHIVE,
        ], true)) {
            return false;
        }

        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        // A missing durable source cannot become available by replaying the same
        // job. This normally identifies legacy archive jobs whose chunk-upload
        // bytes were removed before asynchronous member outcomes were known.
        // Stop immediately instead of producing attempts 1/3, 2/3 and 3/3 for a
        // source that must be re-uploaded by an operator.
        foreach ([
            'chunked upload was not found',
            'chunked upload manifest is missing',
            'completed chunked pak data is unavailable',
            'staged import file is unavailable',
        ] as $missingSourceMarker) {
            if (str_contains($message, $missingSourceMarker)) {
                return true;
            }
        }

        $structuralMarkers = [
            'extra data overflow',
            'central directory',
            'unexpected end of archive',
            'unexpected end of file',
            'truncated archive',
            'invalid zip',
            'damaged zip',
            'ziparchive code 19',
            'ziparchive code 21',
        ];

        foreach ($structuralMarkers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        // The combined ZIP diagnostic is emitted only after both ZipArchive and
        // the libarchive fallback reject the same immutable staged bytes. Keep
        // this conservative: parser-open failure alone is not enough unless the
        // message also carries a structural/consistency diagnosis.
        return str_contains($message, 'could not be opened as zip')
            && (str_contains($message, 'inconsistent')
                || str_contains($message, 'corrupt')
                || str_contains($message, 'malformed'));
    }
}
