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
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

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
        $sourcePath = $redirectPrepared
            ? $this->resolvePreparedSource($preparedSourcePath)
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
                // The non-blocking wrapper created this controlled temporary file
                // in the current worker process. Scan it directly instead of making
                // another large copy before the parser can begin.
                $workingPath = $sourcePath;
                $workingTemporary = true;
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
                // Durable upload staging is already a controlled, identity-checked
                // source. Parse that file directly instead of reading and writing a
                // second full copy before the parser can begin.
                $workingPath = $sourcePath;
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
            $workingPath = '';
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
                $context->checkpoint([
                    'stage' => 'unverified',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'message' => $staged !== null
                        ? 'Package could not be verified and was retained in the unverified queue.'
                        : 'Unsupported or invalid input was discarded.',
                    'error' => $this->shortError($error),
                ]);
                return [
                    'operation' => 'import_staged_package',
                    'status' => $staged !== null ? 'unverified' : 'rejected',
                    'file_id' => (int)($staged['file_id'] ?? 0),
                    'message' => $this->shortError($error),
                    'original_name' => $workingName,
                    'source_relative_path' => $sourceRelativePath,
                    'unverified' => $staged,
                ];
            }
            throw $error;
        } finally {
            // Only helper-created temporary files are disposable here. A durable
            // staged source must survive an infrastructure/staging exception so an
            // administrator can retry or inspect it.
            if ($workingTemporary && $workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
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

    private function resolvePreparedSource(string $path): string
    {
        $real = realpath($path);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($real === false || $temporaryRoot === false || !is_file($real) || !is_readable($real) || is_link($real)) {
            throw new \RuntimeException('Prepared redirect payload is unavailable.');
        }
        $prefix = rtrim(str_replace('\\', '/', $temporaryRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $real);
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
}
