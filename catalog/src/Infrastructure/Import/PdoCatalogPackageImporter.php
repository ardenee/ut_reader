<?php
/**
 * Verified-package import adapter implementing the stable upload/scanner port.
 *
 * This class now coordinates focused Infrastructure collaborators instead of
 * owning reader selection, identity SQL, storage compensation, metadata
 * publication and dependency recovery itself.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;

final class PdoCatalogPackageImporter implements CatalogPackageImporter
{
    private readonly CatalogVerifiedPackageInspector $inspector;
    private readonly CatalogVerifiedPackageIdentityRepository $identity;
    private readonly CatalogVerifiedPackagePublisher $publisher;
    private readonly CatalogVerifiedPackageDependencyCoordinator $dependencies;
    private readonly CatalogFailedUploadRetention $failedUploads;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $this->inspector = new CatalogVerifiedPackageInspector($db, $config);
        $this->identity = new CatalogVerifiedPackageIdentityRepository($db);
        $this->publisher = new CatalogVerifiedPackagePublisher($db, $config);
        $this->dependencies = new CatalogVerifiedPackageDependencyCoordinator($db, $config);
        $this->failedUploads = new CatalogFailedUploadRetention($config);
    }

    public function import(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array {
        $result = $this->importUploadedFile(
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
            $progress
        );

        if (($result[0] ?? '') === 'alias') {
            $metadata = is_array($result[4] ?? null) ? $result[4] : [];
            $metadata['alias_already_exists'] = function_exists('catalog_package_alias_last_add_was_existing')
                && \catalog_package_alias_last_add_was_existing();
            $result[4] = $metadata;
        }

        return $result;
    }

    /**
     * Scanner-compatible verified import operation.
     *
     * @param array<string,mixed> $scannerOptions
     * @return array<int|string,mixed>
     */
    public function importUploadedFile(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile = true,
        ?callable $progress = null,
        bool $allowProfileOverride = false,
        array $scannerOptions = []
    ): array {
        // Keep the stable compatibility parameter even though override behaviour
        // remains determined by strictProfile, exactly as before this refactor.
        unset($allowProfileOverride);

        $this->identity->ensureSourcePathSchema();
        $sourceRelativePath = (string)($scannerOptions['source_relative_path'] ?? '');
        $deferDependencyRebuild = !empty($scannerOptions['defer_dependency_rebuild']);
        $maintenanceReplaceFileId = max(0, (int)($scannerOptions['maintenance_replace_file_id'] ?? 0));
        $this->identity->validateMaintenanceTarget($gameId, $maintenanceReplaceFileId);

        $inspection = $this->inspector->inspect(
            $gameId,
            $temporaryPath,
            $originalName,
            $strictProfile,
            $sourceRelativePath,
            $progress
        );
        $this->identity->ensureAliasSchema();

        $duplicate = $this->identity->findVerifiedDuplicate(
            $gameId,
            $inspection,
            $maintenanceReplaceFileId
        );
        if ($duplicate !== null) {
            if ($maintenanceReplaceFileId > 0) {
                throw new RuntimeException(
                    'Maintenance refresh would collide with existing file #' . (int)$duplicate['id']
                    . '; refusing to merge stable file identities automatically.'
                );
            }
            return $this->handleDuplicate(
                $gameId,
                $duplicate,
                $inspection,
                $deferDependencyRebuild,
                $progress
            );
        }

        $fileId = $this->publisher->persist(
            $gameId,
            $temporaryPath,
            $inspection,
            $userId,
            $progress,
            $maintenanceReplaceFileId
        );

        $resultLabel = ($inspection->classification['compatibility_status'] ?? 'native') === 'legacy_compatible'
            ? ('; ' . (string)($inspection->classification['compatibility_label'] ?? 'legacy-compatible'))
            : '';
        $verb = $maintenanceReplaceFileId > 0 ? 'Refreshed' : 'Imported';
        $result = [
            'verified',
            $fileId,
            $verb . '. Profile=' . $inspection->profileEngine . ', reader=' . $inspection->readerEngine
                . ', detection=' . $inspection->classification['confidence'] . $resultLabel
                . ', size=' . \catalog_bytes($inspection->fileSize)
                . ', names=' . $inspection->nameCount()
                . ', imports=' . $inspection->importCount()
                . ', exports=' . $inspection->exportCount(),
            $inspection->classification,
            [
                'file_id' => $fileId,
                'package_name' => $inspection->packageName,
                'package_guid' => $inspection->packageGuid,
                'file_size' => $inspection->fileSize,
                'file_size_text' => \catalog_bytes($inspection->fileSize),
                'source_relative_path' => $inspection->sourceRelativePath,
                'maintenance_replace_file_id' => $maintenanceReplaceFileId,
            ],
        ];
        $result = $this->publisher->publishMetadata($result, $inspection);

        $refreshWarning = $this->dependencies->refreshCanonical(
            $fileId,
            $gameId,
            $inspection->packageName,
            $userId,
            $deferDependencyRebuild,
            $progress
        );
        if ($refreshWarning !== '') {
            $result[2] = (string)$result[2] . $refreshWarning;
        }

        \scanner_emit_percent(
            $progress,
            'done',
            100,
            $verb . ' ' . $inspection->nameCount() . ' names, ' . $inspection->importCount()
            . ' imports, ' . $inspection->exportCount() . ' exports with compact metadata'
        );
        return $result;
    }

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason,
        ?int $uploadedBy = null
    ): void {
        $this->failedUploads->preserve(
            $temporaryPath,
            $originalName,
            $gameSlug,
            $reason,
            $uploadedBy
        );
    }

    /**
     * @param array<string,mixed> $duplicate
     * @return array<int|string,mixed>
     */
    private function handleDuplicate(
        int $gameId,
        array $duplicate,
        CatalogVerifiedPackageInspection $inspection,
        bool $deferDependencyRebuild,
        ?callable $progress
    ): array {
        $duplicateFileId = (int)$duplicate['id'];
        $this->identity->recordSourcePathIfMissing($duplicateFileId, $inspection->sourceRelativePath);
        $duplicatePackageName = (string)$duplicate['package_name'];
        $meta = [
            'file_id' => $duplicateFileId,
            'file_size' => $inspection->fileSize,
            'file_size_text' => \catalog_bytes($inspection->fileSize),
            'package_name' => $inspection->packageName,
            'package_guid' => $inspection->packageGuid,
            'md5' => $inspection->md5,
            'duplicate_file_id' => $duplicateFileId,
            'duplicate_original_name' => \catalog_clean_unreal_filename((string)$duplicate['original_name']),
            'duplicate_package_name' => $duplicatePackageName,
            'duplicate_md5' => (string)($duplicate['md5'] ?? ''),
        ];

        if (strcasecmp($duplicatePackageName, $inspection->packageName) === 0) {
            \scanner_emit_percent($progress, 'done', 100, 'Duplicate in selected game');
            return [
                'duplicate',
                $duplicateFileId,
                'Duplicate in selected game',
                $inspection->classification,
                $meta,
            ];
        }

        // Alias insertion remains idempotent. Even an existing alias must pass
        // through dependency publication so a previous refresh failure retries.
        $aliasAdded = $this->identity->addAlias($duplicateFileId, $gameId, $inspection);
        $aliasAlreadyExisted = !$aliasAdded;
        $this->dependencies->refreshAlias(
            $duplicateFileId,
            $gameId,
            $inspection->packageName,
            $deferDependencyRebuild,
            $progress
        );

        \scanner_emit_percent(
            $progress,
            'done',
            100,
            $aliasAlreadyExisted
                ? 'Existing package alias dependency refresh completed'
                : 'Alias package added for existing file identity'
        );
        $meta['alias_package_name'] = $inspection->packageName;
        $meta['alias_added'] = $aliasAdded;
        $meta['alias_already_exists'] = $aliasAlreadyExisted;
        return [
            'alias',
            $duplicateFileId,
            $aliasAlreadyExisted
                ? 'Existing package alias dependency refresh completed'
                : 'Package alias added for existing file identity',
            $inspection->classification,
            $meta,
        ];
    }
}
