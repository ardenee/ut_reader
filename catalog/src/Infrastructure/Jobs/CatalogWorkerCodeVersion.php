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
            $this->catalogRoot . '/src/Domain/Jobs/JobResourcePolicy.php',
            $this->catalogRoot . '/src/Application/Jobs/JobWorker.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobQueue.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogUnsupportedRedirectExclusionJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Import/CatalogBucketPakContainerStore.php',
        ];
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = str_replace('\\', '/', $path) . ':'
                . (is_file($path) ? (string)filemtime($path) . ':' . (string)filesize($path) : 'missing');
        }
        return $this->version = substr(hash('sha256', implode("\n", $parts)), 0, 24);
    }
}
