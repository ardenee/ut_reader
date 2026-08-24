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
        if (self::isDeterministicArchiveFailure($job, $error)
            || self::isDeterministicPackageFailure($job, $error)) {
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

    private static function isDeterministicPackageFailure(ClaimedJob $job, Throwable $error): bool
    {
        // These job types all operate on an immutable staged/chunk-completed file.
        // PROCESS_BUCKET_UPLOAD is included because the browser has already
        // finalized the upload before a worker attempts package parsing.
        if (!in_array($job->type, [
            JobType::PROCESS_BUCKET_UPLOAD,
            JobType::PROCESS_BUCKET_STAGED_PACKAGE,
            JobType::IMPORT_STAGED_PACKAGE,
        ], true)) {
            return false;
        }

        $message = strtolower(trim($error->getMessage()));
        if ($message === '') {
            return false;
        }

        // Archive-member jobs first try their own durable prepared source and then
        // reconstruct the exact member from the retained parent archive. If both
        // are gone, another attempt cannot manufacture those bytes.
        foreach ([
            'staged import file is unavailable',
            'archive member staged source is unavailable and retained-parent reconstruction failed:',
            'retained parent archive source is unavailable for member reconstruction',
            'retained parent archive no longer contains the exact recorded member',
        ] as $missingSourceMarker) {
            if (str_contains($message, $missingSourceMarker)) {
                return true;
            }
        }

        // These failures describe immutable package bytes that contradict their
        // own UE serialization metadata. Retrying cannot add the missing bytes or
        // change the recorded table/chunk boundaries. In particular, Epic UE3
        // compressed packages declare physical CompressedOffset/CompressedSize;
        // if a declared range extends past EOF the package is incomplete.
        foreach ([
            'epic ue3 compressed chunk exceeds physical package size',
            'negative epic ue3 compressed chunk field',
            'invalid first epic ue3 compressed chunk offset',
            'overlapping epic ue3 compressed chunk ranges are invalid',
            'nested archive depth limit of ',
            'invalid names table count:',
            'invalid names table offset:',
            'invalid exports table count:',
            'invalid exports table offset:',
            'invalid imports table count:',
            'invalid imports table offset:',
            'invalid legacy package generation count:',
            'legacy package seek is outside the file:',
            'legacy package read exceeds the file:',
            'legacy package read stopped before the requested bytes were available',
            'invalid compact package index length',
            'invalid legacy fstring byte length:',
            'invalid legacy wide fstring length:',
            'legacy package string has no terminator within the safe limit',
            'the unreal package header is missing the required package guid',
        ] as $structuralMarker) {
            if (str_contains($message, $structuralMarker)) {
                return true;
            }
        }

        return false;
    }
}
