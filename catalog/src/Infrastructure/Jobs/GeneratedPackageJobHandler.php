<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

final class GeneratedPackageJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::GENERATE_MOD_PACKAGE;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/ExternalMirrors.php';
        require_once __DIR__ . '/../../../lib/ModPackageBuilder.php';

        $fileId = $this->positiveInt($job->payload, 'file_id');
        $format = strtolower(trim((string)($job->payload['format'] ?? '')));
        $includeDependencies = !empty($job->payload['include_dependencies']);
        $allowIncompleteRequested = !empty($job->payload['allow_incomplete']);
        $optionInput = is_array($job->payload['options'] ?? null) ? $job->payload['options'] : [];

        $mode = \external_public_download_mode($this->db);
        if ($mode === 'disabled') {
            throw new \RuntimeException('Public downloads are disabled.');
        }
        if ($mode === 'external_mirror') {
            throw new \RuntimeException('Generated packages require local catalogue payload access and are unavailable in external-mirror-only mode.');
        }

        $settings = \modpkg_settings($this->db);
        if (!$settings['enabled']) {
            throw new \RuntimeException('Package exports are disabled.');
        }

        $context->checkpoint([
            'stage' => 'planning',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'message' => 'Resolving the package and dependency closure.',
        ]);
        $plan = \modpkg_plan(
            $this->db,
            $this->config,
            $fileId,
            $format,
            $includeDependencies,
            $settings
        );

        if (in_array($format, [\MODPKG_FORMAT_UMOD, \MODPKG_FORMAT_UT2MOD, \MODPKG_FORMAT_UT4MOD], true)
            && (int)$plan['total_bytes'] > 2000 * 1024 * 1024) {
            throw new \RuntimeException('UMOD-family archives are limited to a 2000 MB payload.');
        }

        $allowIncomplete = (bool)$settings['allow_incomplete'] && $allowIncompleteRequested;
        if (($plan['missing'] || $plan['package_only']) && !$allowIncomplete) {
            $problems = count($plan['missing']) + count($plan['package_only']);
            throw new \RuntimeException(
                'Package generation stopped because ' . $problems
                . ' dependencies are missing or only matched at package level.'
            );
        }

        $options = \modpkg_default_options($plan, $settings, $optionInput);
        $extension = \modpkg_extension($format);
        $downloadName = \modpkg_download_name($format, $options);
        $store = new GeneratedPackageStore((string)($this->config['storage_path'] ?? ''));
        $pruned = $store->prune();
        $temporaryPath = $store->temporaryPath($job->id, $extension);
        $publishedPath = null;

        try {
            $context->checkpoint([
                'stage' => 'building',
                'done' => 0,
                'total' => max(1, (int)$plan['file_count']),
                'percent' => 20,
                'message' => 'Building and validating ' . $downloadName . '.',
                'file_count' => (int)$plan['file_count'],
                'total_bytes' => (int)$plan['total_bytes'],
                'format' => $format,
            ]);

            $validation = \modpkg_build($temporaryPath, $plan, $options, $settings);
            if (empty($validation['ok'])) {
                throw new \RuntimeException('Generated package did not pass validation.');
            }

            /* This checkpoint discards output when cancellation arrived during archive writing. */
            $context->checkpoint([
                'stage' => 'publishing',
                'done' => max(1, (int)$plan['file_count']),
                'total' => max(1, (int)$plan['file_count']),
                'percent' => 95,
                'message' => 'Package validation passed; publishing the completed artifact.',
                'file_count' => (int)$plan['file_count'],
                'total_bytes' => (int)$plan['total_bytes'],
                'format' => $format,
            ]);

            $artifact = $store->publish($temporaryPath, $job->id, $extension);
            $publishedPath = (string)$artifact['path'];
            try {
                $context->checkpoint([
                    'stage' => 'complete',
                    'done' => max(1, (int)$plan['file_count']),
                    'total' => max(1, (int)$plan['file_count']),
                    'percent' => 100,
                    'message' => 'Generated package is ready to download.',
                    'file_count' => (int)$plan['file_count'],
                    'artifact_size' => (int)$artifact['size'],
                ]);
            } catch (Throwable $error) {
                $store->delete($publishedPath);
                $publishedPath = null;
                throw $error;
            }

            return [
                'operation' => 'generate_mod_package',
                'file_id' => $fileId,
                'format' => $format,
                'download_name' => $downloadName,
                'content_type' => $this->contentType($extension),
                'artifact_name' => (string)$artifact['artifact_name'],
                'artifact_size' => (int)$artifact['size'],
                'artifact_sha256' => (string)$artifact['sha256'],
                'expires_at' => (string)$artifact['expires_at'],
                'file_count' => (int)$plan['file_count'],
                'total_source_bytes' => (int)$plan['total_bytes'],
                'base_game_files_excluded' => count($plan['blocked']),
                'missing_dependencies' => count($plan['missing']),
                'package_only_dependencies' => count($plan['package_only']),
                'include_dependencies' => $includeDependencies,
                'allow_incomplete' => $allowIncomplete,
                'validation' => [
                    'ok' => true,
                    'file_count' => (int)($validation['file_count'] ?? $plan['file_count']),
                    'version' => $validation['version'] ?? null,
                    'mount_point' => $validation['mount_point'] ?? null,
                ],
                'pruned_before_build' => $pruned,
            ];
        } finally {
            if (is_file($temporaryPath)) {
                $store->delete($temporaryPath);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Package generation requires positive ' . $field . '.');
        }
        return $value;
    }

    private function contentType(string $extension): string
    {
        return $extension === 'zip' ? 'application/zip' : 'application/octet-stream';
    }
}
