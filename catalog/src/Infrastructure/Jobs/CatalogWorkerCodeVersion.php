<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

/** Calculates the host-local worker code fingerprint used for stale-process detection. */
final class CatalogWorkerCodeVersion
{
    private ?string $version = null;

    public function __construct(private readonly string $catalogRoot)
    {
    }

    public function current(bool $refresh = false): string
    {
        if (!$refresh && $this->version !== null) {
            return $this->version;
        }
        $paths = [
            $this->catalogRoot . '/bin/catalog-worker-detached.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogDetachedWorker.php',
            __FILE__,
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogWorkerRuntimeStateStore.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogWorkerProcessLauncher.php',
            $this->catalogRoot . '/src/Domain/Jobs/ClaimedJob.php',
            $this->catalogRoot . '/src/Domain/Jobs/JobResourcePolicy.php',
            $this->catalogRoot . '/src/Application/Jobs/JobWorker.php',
            $this->catalogRoot . '/src/Application/Jobs/JobFailureRetryPolicy.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobQueue.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobQueueSupport.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobClaimer.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobEnqueuer.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobLeaseStore.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoWorkerOwnership.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoArchiveParentLifecycleRepair.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogAffectedDependencyBatchService.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogNonBlockingAffectedDependencyJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogGameStatsRefreshCoordinator.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogPakDependencyTargetQuery.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogPakImportJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoDependencyPackageSummary.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentClassifier.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentRoutingJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogRetainedArchiveMemberSourceRestorer.php',
            $this->catalogRoot . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogIncomingFileStore.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogBucketPakContainerStore.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipMetadataConsistency.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipLocalHeaderRecoveryReader.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipBitReader.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipHuffmanTree.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipOutputWriter.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipImplodeDecoder.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogZipDeflate64Decoder.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogRar5FileCopyMap.php',
            $this->catalogRoot . '/src/Infrastructure/Archive/CatalogUmodArchiveReader.php',
            $this->catalogRoot . '/src/Infrastructure/Downloads/CatalogUmodBinaryCodec.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogNestedArchiveJobEnqueuer.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogArchiveSourceStore.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBackgroundJobSubtreePruner.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php',
        ];
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = str_replace('\\', '/', $path) . ':'
                . (is_file($path) ? (string)filemtime($path) . ':' . (string)filesize($path) : 'missing');
        }
        return $this->version = substr(hash('sha256', implode("\n", $parts)), 0, 24);
    }
}
