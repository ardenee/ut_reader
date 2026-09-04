<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues a verified package from one sibling game into another together with its resolved dependency closure.
 * Why: Cross-game dependency repair must create real game-scoped verified rows without forcing an import/re-scan loop for every newly revealed dependency.
 * Role: Mutation service behind the dependency cross-examine administration workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogCrossGamePackageCopyService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogPackageAliases.php';
    }

    /** @return array<string,mixed> */
    public function queue(int $sourceFileId, int $targetGameId, ?int $userId): array
    {
        $candidate = (new PdoGameDependencyCrossExamineQuery($this->db, $this->config))
            ->one($sourceFileId, $targetGameId);
        if ($candidate === null) {
            throw new \RuntimeException(
                'This source file no longer exports an object required by a missing dependency in the target game.'
            );
        }
        if (!empty($candidate['already_in_target'])) {
            throw new \RuntimeException(
                'The same package bytes are already verified in the target game as file #'
                . (int)($candidate['target_existing_file_id'] ?? 0)
                . '. Rebuild the target dependencies instead of copying the file again.'
            );
        }

        // Resolve the source game's dependency graph before queueing the selected
        // root. This mirrors generated-package traversal: resolved/package_only
        // links are followed transitively, while common and unresolved links are
        // not invented. Shared dependencies dedupe through the normal import key.
        $closure = (new CatalogCrossGameDependencyClosurePlanner($this->db))
            ->plan($sourceFileId, $targetGameId);
        $dependencyJobIds = [];
        $dependencyQueued = 0;
        $dependencyDeduplicated = 0;
        $dependencyAlreadyPresent = 0;

        foreach ($closure['file_ids'] as $dependencyFileId) {
            $dependency = $this->sourceForTarget((int)$dependencyFileId, $targetGameId);
            if ($dependency === null) {
                throw new \RuntimeException(
                    'A resolved source dependency is no longer a verified same-engine package: file #'
                    . (int)$dependencyFileId . '.'
                );
            }
            if (!empty($dependency['already_in_target'])) {
                $dependencyAlreadyPresent++;
                continue;
            }

            $queued = $this->queueCandidate(
                $dependency,
                $targetGameId,
                $userId,
                5,
                $sourceFileId,
                true
            );
            $dependencyJobIds[(int)$queued['job_id']] = (int)$queued['job_id'];
            if (!empty($queued['deduplicated'])) {
                $dependencyDeduplicated++;
            } else {
                $dependencyQueued++;
            }
        }

        // Queue the selected package after its dependency jobs. A slightly lower
        // scheduling precedence than dependency imports prevents the root from
        // needlessly exposing a fresh wave of missing rows while the closure is
        // still waiting in the same queue.
        $rootQueued = $this->queueCandidate(
            $candidate,
            $targetGameId,
            $userId,
            6,
            $sourceFileId,
            false
        );

        ksort($dependencyJobIds, SORT_NUMERIC);
        return [
            'source_file_id' => $sourceFileId,
            'source_game' => (string)$candidate['source_game_name'],
            'target_game_id' => $targetGameId,
            'target_game' => (string)$candidate['target_game_name'],
            'package_name' => (string)$candidate['package_name'],
            'original_name' => trim((string)($candidate['original_name'] ?? '')),
            'exact_object_matches' => (int)$candidate['exact_object_matches'],
            'target_missing_count' => (int)$candidate['target_missing_count'],
            'job_id' => (int)$rootQueued['job_id'],
            'deduplicated' => !empty($rootQueued['deduplicated']),
            'dependency_file_count' => count($closure['file_ids']),
            'dependency_jobs_queued' => $dependencyQueued,
            'dependency_jobs_deduplicated' => $dependencyDeduplicated,
            'dependency_files_already_in_target' => $dependencyAlreadyPresent,
            'dependency_job_ids' => array_values(array_filter(
                $dependencyJobIds,
                static fn(int $id): bool => $id > 0
            )),
            'unresolved_dependency_count' => (int)$closure['missing_count'],
            'common_dependency_count' => (int)$closure['common_count'],
            'package_only_dependency_count' => (int)$closure['package_only_count'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function sourceForTarget(int $sourceFileId, int $targetGameId): ?array
    {
        $source = \catalog_one(
            $this->db,
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.relative_path,f.extension,f.file_size,'
            . 'f.md5,f.sha1,f.package_guid,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
            . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine,m.format_version metadata_format_version '
            . 'FROM ue_files f '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        if (!$source || (int)$source['game_id'] === $targetGameId) {
            return null;
        }
        if ((int)($source['metadata_format_version'] ?? 0) !== 2) {
            return null;
        }

        $target = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=? LIMIT 1',
            [$targetGameId]
        );
        if (!$target
            || strcasecmp(trim((string)$source['source_engine']), trim((string)$target['engine_key'])) !== 0) {
            return null;
        }

        $targetExistingFileId = 0;
        $targetProvidesPackageIdentity = false;
        $md5 = strtolower(trim((string)($source['md5'] ?? '')));
        if ($md5 !== '') {
            $statement = $this->db->prepare(
                'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1'
            );
            $statement->execute([$targetGameId, $md5]);
            $targetExisting = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($targetExisting)) {
                $targetExistingFileId = (int)($targetExisting['id'] ?? 0);
                $sourcePackage = trim((string)($source['package_name'] ?? ''));
                $targetProvidesPackageIdentity = strcasecmp(
                    trim((string)($targetExisting['package_name'] ?? '')),
                    $sourcePackage
                ) === 0;
                if (!$targetProvidesPackageIdentity && $sourcePackage !== '' && $targetExistingFileId > 0) {
                    $targetProvidesPackageIdentity = \catalog_package_alias_row_exists(
                        $this->db,
                        $targetExistingFileId,
                        $targetGameId,
                        $sourcePackage
                    );
                }
            }
        }

        return $source + [
            'target_game_id' => $targetGameId,
            'target_game_name' => (string)$target['name'],
            // Same bytes under a different logical package name still require a
            // canonical import pass so the target can publish that alias.
            'already_in_target' => $targetProvidesPackageIdentity,
            'target_existing_file_id' => $targetExistingFileId ?: null,
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array{job_id:int,deduplicated:bool}
     */
    private function queueCandidate(
        array $candidate,
        int $targetGameId,
        ?int $userId,
        int $priority,
        int $rootSourceFileId,
        bool $dependencySupport
    ): array {
        $sourceFileId = (int)($candidate['id'] ?? 0);
        if ($sourceFileId < 1) {
            throw new \RuntimeException('Cross-game source package ID is unavailable.');
        }

        $sourcePath = $this->physicalPath((string)($candidate['relative_path'] ?? ''));
        $originalName = trim((string)($candidate['original_name'] ?? ''));
        if ($originalName === '') {
            throw new \RuntimeException('The source package filename is unavailable.');
        }

        $sourceRow = \catalog_one(
            $this->db,
            'SELECT source_relative_path,file_size,md5 FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$sourceFileId]
        ) ?: [];
        $sourceRelativePath = trim((string)($sourceRow['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $originalName;
        }
        $fileSize = (int)($sourceRow['file_size'] ?? 0);
        if ($fileSize < 1) {
            $physicalSize = filesize($sourcePath);
            $fileSize = $physicalSize === false ? 0 : (int)$physicalSize;
        }
        if ($fileSize < 1) {
            throw new \RuntimeException('Verified source package size is unavailable.');
        }

        // Do not copy/hash the already-verified package just to create a queue
        // record. The import worker resolves this read-only catalog-local source
        // and creates its normal parser working hardlink/copy there. The original
        // verified source path is never moved or deleted.
        $payload = [
            'game_id' => $targetGameId,
            'staged_path' => 'local-catalog:' . $this->encodeLocalPath($sourcePath),
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'strict_profile' => false,
            'user_id' => $userId,
            'size' => $fileSize,
            'cross_game_source_file_id' => $sourceFileId,
            'cross_game_root_source_file_id' => $rootSourceFileId,
            'cross_game_dependency_support' => $dependencySupport,
        ];
        $md5 = strtolower(trim((string)($sourceRow['md5'] ?? $candidate['md5'] ?? '')));
        $identity = $md5 !== '' ? $md5 : (string)$sourceFileId;
        $dedupeKey = 'cross-game-copy:' . hash(
            'sha256',
            $targetGameId . "\0" . $sourceFileId . "\0" . $identity
        );
        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queue = new PdoJobQueue($this->db);

        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingJobId = (int)($existing->fetchColumn() ?: 0);

        $jobId = $queue->enqueue(
            $queueName,
            JobType::IMPORT_STAGED_PACKAGE,
            $payload,
            max(1, min(100, $priority)),
            null,
            $dedupeKey,
            $userId,
            3
        );

        return [
            'job_id' => $jobId,
            'deduplicated' => $existingJobId > 0 && $existingJobId === $jobId,
        ];
    }

    private function physicalPath(string $rawPath): string
    {
        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            throw new \RuntimeException('Catalog storage root is unavailable.');
        }
        $rawPath = trim($rawPath);
        if ($rawPath === '' || str_contains($rawPath, "\0")) {
            throw new \RuntimeException('Verified source package path is unavailable.');
        }

        $normalized = str_replace('\\', '/', $rawPath);
        $candidates = [];
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }
        $relative = ltrim($normalized, '/');
        if (str_starts_with(strtolower($relative), 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        if ($relative !== '' && preg_match('#(^|/)\.\.(/|$)#', $relative) !== 1) {
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || is_link($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }
            $realNormalized = str_replace('\\', '/', $real);
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($realNormalized), strtolower($rootPrefix))
                : str_starts_with($realNormalized, $rootPrefix);
            if ($inside) {
                return $real;
            }
        }
        throw new \RuntimeException('Verified source package is missing from controlled storage.');
    }

    private function encodeLocalPath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }
}
