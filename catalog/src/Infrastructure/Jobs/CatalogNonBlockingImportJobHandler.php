<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/**
 * Treats a bad package/archive as a completed import attempt with a failed
 * outcome. The queue can then continue immediately to the next uploaded file.
 * Queue/storage/database/programming failures still escape and use the normal
 * retry and diagnostics policy.
 */
final class CatalogNonBlockingImportJobHandler implements JobHandler
{
    public function __construct(private readonly CatalogStagedImportJobHandler $inner)
    {
    }

    public function supports(string $jobType): bool
    {
        return $this->inner->supports($jobType);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        try {
            return $this->inner->handle($job, $context);
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (PDOException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw $error;
        } catch (\Error $error) {
            throw $error;
        } catch (Throwable $error) {
            if ($this->isInfrastructureFailure($error)) {
                throw $error;
            }

            $message = $this->shortError($error);
            $payload = $job->payload;
            $originalName = trim((string)($payload['original_name'] ?? 'package'));
            $sourceRelativePath = trim(str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)), '/');

            $context->checkpoint([
                'stage' => 'failed',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'failed',
                'message' => $message,
            ]);

            return [
                'operation' => $job->type === JobType::IMPORT_STAGED_PAK
                    ? 'import_staged_pak'
                    : 'import_staged_package',
                'status' => 'failed',
                'file_id' => 0,
                'message' => $message,
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
            ];
        }
    }

    private function isInfrastructureFailure(Throwable $error): bool
    {
        if ($error instanceof \LogicException) {
            return true;
        }

        $message = strtolower(trim($error->getMessage()));
        foreach ([
            'staged import file is unavailable',
            'staged import file identity changed',
            'target game no longer exists',
            'could not allocate package import working file',
            'could not create package import working copy',
            'job payload requires',
            'job lease no longer belongs',
            'sqlstate[',
        ] as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        return trim($message) !== '' ? trim($message) : 'Package import failed.';
    }
}
