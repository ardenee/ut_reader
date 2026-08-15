<?php
/**
 * Coordinates post-import dependency publication for canonical and alias paths.
 *
 * Synchronous rebuild and durable fallback policy are kept together so import
 * orchestration cannot accidentally report success without securing recovery.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Import\Contract\VerifiedPackageDependencyPort;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogPostImportDependencyQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;

final class CatalogVerifiedPackageDependencyCoordinator implements VerifiedPackageDependencyPort
{
    private readonly PdoCatalogDependencyRebuilder $rebuilder;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $this->rebuilder = new PdoCatalogDependencyRebuilder($db, $config);
    }

    /** Return an import-result warning suffix, or an empty string. */
    public function refreshCanonical(
        int $fileId,
        int $gameId,
        string $packageName,
        ?int $userId,
        bool $defer,
        ?callable $progress
    ): string {
        if ($defer) {
            \scanner_emit_percent(
                $progress,
                'dependencies',
                99,
                'Affected dependency refresh deferred to the final Full Sync pass'
            );
            return '';
        }

        try {
            $this->rebuilder->rebuildAffected($fileId, $progress, 56, 99);
            return '';
        } catch (Throwable $refreshError) {
            error_log(
                '[UnrealDB dependency refresh] imported_file_id=' . $fileId
                . ' error=' . $refreshError->getMessage()
            );
            try {
                $queued = CatalogPostImportDependencyQueue::enqueue(
                    $this->db,
                    $this->config,
                    $fileId,
                    $gameId,
                    $packageName,
                    $userId
                );
                return '; synchronous dependency refresh failed; durable repair job #'
                    . (int)$queued['file_job_id'] . ' queued';
            } catch (Throwable $queueError) {
                throw new RuntimeException(
                    'Imported file #' . $fileId
                    . ' is stored with verified compact metadata, but post-import dependency recovery queue failed: '
                    . $queueError->getMessage(),
                    0,
                    $queueError
                );
            }
        }
    }

    public function refreshAlias(
        int $fileId,
        int $gameId,
        string $packageName,
        bool $defer,
        ?callable $progress
    ): void {
        if ($defer) {
            \scanner_emit_percent(
                $progress,
                'dependencies',
                99,
                'Alias dependency refresh deferred to the final Full Sync pass'
            );
            return;
        }

        try {
            $this->rebuilder->rebuildAffectedForPackage(
                $gameId,
                $packageName,
                $progress,
                56,
                99,
                $fileId
            );
        } catch (Throwable $refreshError) {
            error_log(
                '[UnrealDB dependency refresh] alias_package=' . $packageName
                . ' file_id=' . $fileId
                . ' error=' . $refreshError->getMessage()
            );
            throw new RuntimeException(
                'Package alias dependency refresh failed for ' . $packageName
                . ' on verified file #' . $fileId . ': '
                . $refreshError->getMessage(),
                0,
                $refreshError
            );
        }
    }
}
