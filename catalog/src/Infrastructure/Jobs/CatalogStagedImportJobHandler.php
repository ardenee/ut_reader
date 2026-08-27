<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Imports one durable staged package while preserving progress, cancellation and unverified fallback behavior.
 * Why: IMPORT_STAGED_PAK is owned by CatalogPakImportJobHandler; this handler now has one deterministic responsibility.
 * Role: Infrastructure job handler for JobType::IMPORT_STAGED_PACKAGE.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportOutcome;
use UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogInvalidUeFileReporter;

final class CatalogStagedImportJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::IMPORT_STAGED_PACKAGE;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type !== JobType::IMPORT_STAGED_PACKAGE) {
            throw new \RuntimeException('Unsupported staged package import job: ' . $job->type);
        }
        return $this->importPackage($job, $context);
    }

    private function importPackage(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $gameId = $this->positiveInt($payload, 'game_id');
        $relativePath = $this->requiredString($payload, 'staged_path');
        $originalName = $this->requiredString($payload, 'original_name');
        $sourceRelativePath = trim(str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)), '/');
        $strict = !array_key_exists('strict_profile', $payload) || (bool)$payload['strict_profile'];
        $userId = isset($payload['user_id']) && (int)$payload['user_id'] > 0 ? (int)$payload['user_id'] : null;
        $store = new CatalogIncomingFileStore($this->config);
        $preparedSourcePath = trim((string)($payload['prepared_source_path'] ?? ''));
        $redirectPrepared = !empty($payload['redirect_prepared']) && $preparedSourcePath !== '';
        $persistentPreparedSource = $redirectPrepared && !empty($payload['prepared_source_persistent']);
        $sourcePath = $redirectPrepared
            ? $this->resolvePreparedSource($preparedSourcePath, $job->id, $persistentPreparedSource)
            : $store->resolve($relativePath);
        if (!$redirectPrepared) {
            $this->verifyIdentity($sourcePath, $payload);
        }

        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogRedirectArchive.php';

        $game = \catalog_one($this->db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Target game no longer exists: ' . $gameId);
        }

        $startPercent = $redirectPrepared ? 46 : 1;
        $context->checkpoint([
            'stage' => $redirectPrepared ? 'scan_prepare' : 'prepare',
            'done' => $startPercent,
            'total' => 100,
            'percent' => $startPercent,
            'message' => $redirectPrepared
                ? 'Preparing decompressed package for scanning: ' . basename($originalName)
                : 'Preparing staged package ' . basename($originalName),
        ]);

        $workingPath = '';
        $workingTemporary = false;
        $workingName = $this->cleanOriginalFilename($originalName);
        $decompressed = $redirectPrepared;
        $scanStart = $startPercent;
        try {
            if ($redirectPrepared) {
                if ($persistentPreparedSource) {
                    // Keep the durable decompressed source untouched. The parser
                    // receives a hardlink/working copy that may be moved into
                    // verified storage without consuming the recovery artifact.
                    $workingPath = $this->workingSource($sourcePath, $workingName, $context, 46, 48);
                    $workingTemporary = true;
                    $scanStart = 48;
                } else {
                    // Compatibility for already-queued jobs produced by the old
                    // non-blocking wrapper: their prepared source is a one-shot
                    // controlled temp file and may be consumed directly.
                    $workingPath = $sourcePath;
                    $workingTemporary = true;
                }
            } elseif (\catalog_redirect_archive_is_supported_filename($originalName)) {
                if (\catalog_redirect_archive_extension($originalName) === 'uz2') {
                    $decoded = CatalogRedirectArchiveStream::decompressUz2(
                        $sourcePath,
                        $originalName,
                        0,
                        static function (array $progress) use ($context): void {
                            $percent = max(
                                1,
                                min(24, 1 + (int)floor(((int)($progress['percent'] ?? 0)) * 23 / 100))
                            );
                            $context->checkpoint([
                                'stage' => 'decompress',
                                'done' => (int)($progress['compressed_done'] ?? 0),
                                'total' => max(1, (int)($progress['compressed_total'] ?? 1)),
                                'percent' => $percent,
                                'message' => (string)($progress['message'] ?? 'Decompressing redirect archive.'),
                                'output_bytes' => (int)($progress['output_bytes'] ?? 0),
                                'chunks' => (int)($progress['chunks'] ?? 0),
                            ]);
                        }
                    );
                } else {
                    $decoded = \catalog_redirect_archive_decompress_to_temp($sourcePath, $originalName);
                }
                $workingPath = (string)$decoded['path'];
                $workingTemporary = true;
                $workingName = $this->cleanOriginalFilename((string)$decoded['filename']);
                $sourceRelativePath = $this->replaceRelativeFilename($sourceRelativePath, $workingName);
                $decompressed = true;
                $scanStart = 25;
                $context->checkpoint([
                    'stage' => 'scan_prepare',
                    'done' => $scanStart,
                    'total' => 100,
                    'percent' => $scanStart,
                    'message' => 'Redirect archive decompressed; scanning ' . basename($workingName),
                    'decompressed_bytes' => (int)($decoded['bytes'] ?? 0),
                    'decoder' => (string)($decoded['decoder'] ?? ''),
                ]);
            } else {
                // Keep the durable staged source untouched until import success.
                // On NTFS/other same-volume filesystems a hardlink creates the
                // parser/storage working path without a second full data copy.
                // Filesystems that cannot hardlink fall back to the historical
                // streamed copy, retaining compatibility and crash safety.
                $workingPath = $this->workingSource($sourcePath, $workingName, $context, 2, 20);
                $workingTemporary = true;
                $scanStart = 20;
            }

            $context->checkpoint([
                'stage' => 'scan',
                'done' => $scanStart,
                'total' => 100,
                'percent' => $scanStart,
                'message' => 'Scanning package tables for ' . basename($workingName),
            ]);

            $importer = new PdoCatalogPackageImporter($this->db, $this->config);
            $result = $importer->importUploadedFile(
                $gameId,
                $workingPath,
                $workingName,
                $userId,
                $strict,
                static function (array $progress) use ($context, $scanStart): void {
                    $mapped = $progress;
                    if (array_key_exists('percent', $progress)) {
                        $sourcePercent = max(0, min(100, (int)$progress['percent']));
                        $mapped['percent'] = min(
                            99,
                            $scanStart + (int)floor($sourcePercent * (99 - $scanStart) / 100)
                        );
                    }
                    $mapped['stage'] = (string)($progress['stage'] ?? 'scan');
                    if (trim((string)($mapped['message'] ?? '')) === '') {
                        $mapped['message'] = 'Scanning package tables.';
                    }
                    $context->heartbeatIfDue($mapped);
                },
                false,
                ['source_relative_path' => $sourceRelativePath]
            );

            // Duplicate/alias outcomes return before verified storage consumes the
            // working path. Remove that helper path explicitly; successful new
            // imports may already have moved it into canonical storage. The
            // durable incoming/prepared recovery source is intentionally separate.
            $completedWorkingPath = $workingPath;
            $workingPath = '';
            if ($workingTemporary && $completedWorkingPath !== '' && is_file($completedWorkingPath)) {
                @unlink($completedWorkingPath);
            }
            $store->remove($relativePath);

            $status = (string)($result[0] ?? 'verified');
            $meta = is_array($result[4] ?? null) ? $result[4] : [];
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'message' => (string)($result[2] ?? 'Package import complete.'),
                'status' => $status,
                'file_id' => (int)($result[1] ?? 0),
            ]);
            return [
                'operation' => 'import_staged_package',
                'status' => $status,
                'file_id' => (int)($result[1] ?? 0),
                'message' => (string)($result[2] ?? ''),
                'original_name' => $workingName,
                'source_relative_path' => $sourceRelativePath,
                'decompressed' => $decompressed,
                'meta' => $meta,
            ];
        } catch (JobCancellationRequested $error) {
            $store->remove($relativePath);
            throw $error;
        } catch (Throwable $error) {
            if ($workingPath !== '' && is_file($workingPath)) {
                $stager = new LegacyUnverifiedFileStager($this->db, $this->config);
                $staged = $stager->stageFailedUpload(
                    $gameId,
                    $workingPath,
                    $workingName,
                    $error->getMessage(),
                    $userId,
                    $sourceRelativePath
                );
                $workingPath = '';
                $store->remove($relativePath);
                $shortError = $this->shortError($error);
                $invalidPackageContent = JobFailureRetryPolicy::isInvalidPackageContentText(
                    JobType::IMPORT_STAGED_PACKAGE,
                    $shortError
                );
                $profileMismatch = $staged !== null
                    && CatalogImportOutcome::isProfileMismatchMessage($shortError)
                    && !$invalidPackageContent;
                $invalidUePackage = !$profileMismatch
                    && $error instanceof CatalogInvalidPackageException;
                $outcomeStatus = $profileMismatch
                    ? CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH
                    : ($invalidUePackage ? CatalogImportOutcome::INVALID_UE_PACKAGE : ($staged !== null ? 'unverified' : 'rejected'));
                $outcomeClass = $profileMismatch
                    ? 'profile_mismatch'
                    : ($invalidUePackage ? 'invalid_ue_package' : '');
                $retentionMessage = $profileMismatch
                    ? 'Valid Unreal package retained in the unverified queue because it does not match the selected game profile'
                    : ($invalidUePackage
                        ? ($staged !== null
                            ? 'Invalid Unreal package retained in the unverified queue'
                            : 'Invalid Unreal package was rejected')
                        : ($staged !== null
                            ? 'Package could not be verified and was retained in the unverified queue'
                            : 'Unsupported or invalid input was discarded'));
                $systemErrorRecorded = false;
                if ($invalidUePackage) {
                    $identity = $this->invalidFileIdentity((int)($staged['file_id'] ?? 0));
                    $systemErrorRecorded = CatalogInvalidUeFileReporter::record([
                        'job_id' => $job->id,
                        'parent_job_id' => $job->parentJobId ?? 0,
                        'job_type' => $job->type,
                        'user_id' => $userId ?? 0,
                        'game_id' => $gameId,
                        'file_id' => (int)($staged['file_id'] ?? 0),
                        'file_name' => $workingName,
                        'source_relative_path' => $sourceRelativePath,
                        'archive_source_name' => (string)($payload['archive_source_name'] ?? ''),
                        'archive_entry_path' => (string)($payload['archive_entry_path'] ?? $workingName),
                        'size' => (int)($identity['file_size'] ?? $staged['size'] ?? 0),
                        'md5' => (string)($identity['md5'] ?? ''),
                        'sha1' => (string)($identity['sha1'] ?? ''),
                        'reason' => $shortError,
                        'error_code' => $error instanceof CatalogInvalidPackageException ? $error->validationCode() : '',
                        'arguments' => $error instanceof CatalogInvalidPackageException ? $error->validationArguments() : [],
                    ]);
                }
                $context->checkpoint([
                    'stage' => $profileMismatch
                        ? 'unverified_profile_mismatch'
                        : ($invalidUePackage ? 'invalid_ue_package' : ($staged !== null ? 'unverified' : 'rejected')),
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => $outcomeStatus,
                    'message' => $retentionMessage . ': ' . $shortError,
                    'error' => $shortError,
                    'outcome_class' => $outcomeClass,
                    'system_error_recorded' => $systemErrorRecorded,
                ]);
                return [
                    'operation' => 'import_staged_package',
                    'status' => $outcomeStatus,
                    'file_id' => (int)($staged['file_id'] ?? 0),
                    'message' => $shortError,
                    'original_name' => $workingName,
                    'source_relative_path' => $sourceRelativePath,
                    'unverified' => $staged,
                    'outcome_class' => $outcomeClass,
                    'system_error_recorded' => $systemErrorRecorded,
                ];
            }
            throw $error;
        } finally {
            if ($workingTemporary && $workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
    }

    /** @return array{md5:string,sha1:string,file_size:int} */
    private function invalidFileIdentity(int $fileId): array
    {
        if ($fileId < 1) {
            return ['md5' => '', 'sha1' => '', 'file_size' => 0];
        }
        $statement = $this->db->prepare(
            'SELECT LOWER(COALESCE(md5,"")) md5,LOWER(COALESCE(sha1,"")) sha1,file_size '
            . 'FROM ue_files WHERE id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['md5' => '', 'sha1' => '', 'file_size' => 0];
        }
        return [
            'md5' => (string)($row['md5'] ?? ''),
            'sha1' => (string)($row['sha1'] ?? ''),
            'file_size' => max(0, (int)($row['file_size'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function verifyIdentity(string $path, array $payload): void
    {
        $expected = strtolower(trim((string)($payload['sha256'] ?? '')));
        if ($expected === '') {
            return;
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new \RuntimeException('Staged import file identity changed before execution.');
        }
    }

    private function workingSource(
        string $sourcePath,
        string $name,
        JobExecutionContext $context,
        int $startPercent,
        int $endPercent
    ): string {
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $directory = dirname($sourcePath);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR . '.unrealdb-import-'
                . bin2hex(random_bytes(8)) . '.' . $extension;
            if (@link($sourcePath, $path)) {
                $context->checkpoint([
                    'stage' => 'prepare',
                    'done' => 1,
                    'total' => 1,
                    'percent' => $endPercent,
                    'message' => 'Prepared parser working link for ' . basename($name),
                ]);
                return $path;
            }
        }

        return $this->workingCopy($sourcePath, $name, $context, $startPercent, $endPercent);
    }

    private function workingCopy(
        string $sourcePath,
        string $name,
        JobExecutionContext $context,
        int $startPercent,
        int $endPercent
    ): string {
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $base = tempnam(sys_get_temp_dir(), 'unrealdb-import-');
        if ($base === false) {
            throw new \RuntimeException('Could not allocate package import working file.');
        }
        $path = $base . '.' . $extension;
        @unlink($base);

        $size = filesize($sourcePath);
        $input = fopen($sourcePath, 'rb');
        $output = fopen($path, 'wb');
        if ($size === false || !is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($path);
            throw new \RuntimeException('Could not create package import working copy.');
        }

        $copied = 0;
        $lastCheckpoint = 0;
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read staged package while creating working copy.');
                }
                if ($buffer === '') {
                    break;
                }
                $written = 0;
                $length = strlen($buffer);
                while ($written < $length) {
                    $count = fwrite($output, substr($buffer, $written));
                    if ($count === false || $count < 1) {
                        throw new \RuntimeException('Could not create package import working copy.');
                    }
                    $written += $count;
                }
                $copied += $length;
                if ($copied - $lastCheckpoint >= 32 * 1024 * 1024 || $copied >= (int)$size) {
                    $sourcePercent = (int)floor($copied * 100 / max(1, (int)$size));
                    $percent = min(
                        $endPercent,
                        $startPercent + (int)floor($sourcePercent * ($endPercent - $startPercent) / 100)
                    );
                    $context->checkpoint([
                        'stage' => 'copy',
                        'done' => $copied,
                        'total' => max(1, (int)$size),
                        'percent' => $percent,
                        'message' => 'Creating parser working copy: '
                            . $this->bytes($copied) . ' of ' . $this->bytes((int)$size),
                    ]);
                    $lastCheckpoint = $copied;
                }
            }
            fflush($output);
        } catch (Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($path);
            throw $error;
        }
        fclose($input);
        fclose($output);

        if ($copied !== (int)$size) {
            @unlink($path);
            throw new \RuntimeException('Package import working copy is incomplete.');
        }
        return $path;
    }

    private function resolvePreparedSource(string $path, int $jobId, bool $persistent): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real) || is_link($real)) {
            throw new \RuntimeException('Prepared redirect payload is unavailable.');
        }
        $normalized = str_replace('\\', '/', $real);

        if ($persistent) {
            $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
            if ($storageRoot === false) {
                throw new \RuntimeException('Catalog storage path is unavailable for prepared redirect recovery.');
            }
            $expected = rtrim(str_replace('\\', '/', $storageRoot), '/')
                . '/jobs/prepared/job-' . $jobId . '/';
            if (!str_starts_with($normalized, $expected)) {
                throw new \RuntimeException('Prepared redirect payload escaped durable job storage.');
            }
            return $real;
        }

        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($temporaryRoot === false) {
            throw new \RuntimeException('Temporary storage is unavailable for prepared redirect payload.');
        }
        $prefix = rtrim(str_replace('\\', '/', $temporaryRoot), '/') . '/';
        if (!str_starts_with($normalized, $prefix) || !str_starts_with(basename($real), 'ue_redirect_')) {
            throw new \RuntimeException('Prepared redirect payload escaped controlled temporary storage.');
        }
        return $real;
    }

    private function replaceRelativeFilename(string $relativePath, string $name): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
        return ($directory !== '' ? $directory . '/' : '') . $name;
    }

    private function cleanOriginalFilename(string $originalName): string
    {
        $placeholder = '__UE_PACKAGE_PLUS__';
        while (str_contains($originalName, $placeholder)) {
            $placeholder .= '_';
        }
        $clean = \catalog_clean_unreal_filename(str_replace('+', $placeholder, $originalName));
        return str_replace($placeholder, '+', $clean);
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Import job payload requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException('Import job payload requires ' . $field . '.');
        }
        return $value;
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        return trim($message) !== '' ? trim($message) : 'Unknown package import error.';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? (string)$value : number_format($value, 2)) . ' ' . $units[$unit];
    }
}
