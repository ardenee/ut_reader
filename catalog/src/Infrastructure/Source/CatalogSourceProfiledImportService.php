<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes profiled imports and failed-import staging for local source scans.
 * Why: Import execution, location accounting, verified identity refresh, fingerprint persistence and failed-copy retention are one repeated source-scan use case.
 * Role: Infrastructure source-scan collaborator; the runner still owns the MD5/GUID/import decision order and user-facing sample classification.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourceIdentityQuery;

final class CatalogSourceProfiledImportService
{
    private readonly LegacyUnverifiedFileStager $stager;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly PdoCatalogSourceIdentityQuery $identities,
        private readonly CatalogSourceLocationRecorder $locations,
        private readonly CatalogSourceFingerprintSession $fingerprints
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSourceScan.php';
        $this->stager = new LegacyUnverifiedFileStager($db, $config);
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
     * @param array{file_size:int,modified_at:int,quick_fingerprint:string}|null $probe
     * @return array{ok:bool,result:?array<int,mixed>,accounting:array{imported:int,duplicates:int,locations:int},staged:bool,error:?Throwable}
     */
    public function attempt(
        array $source,
        array $work,
        string $relativePath,
        bool $strictProfile,
        ?int $userId,
        int $sourceId,
        ?array $probe,
        string $md5,
        string $guidFallback,
        bool $rememberFailureFingerprint
    ): array {
        try {
            $result = $this->importWorkFile($source, $work, $relativePath, $strictProfile, $userId);
            $accounting = $this->locations->recordImportResult($sourceId, $relativePath, $result);
            $importedFile = $this->identities->findVerifiedById((int)($result[1] ?? 0));
            $this->fingerprints->remember(
                $sourceId,
                $relativePath,
                $probe,
                $work,
                $md5,
                (string)($importedFile['sha1'] ?? ''),
                (string)($importedFile['package_guid'] ?? $guidFallback),
                $importedFile,
                ($result[0] ?? '') === 'duplicate' ? 'duplicate' : 'import'
            );

            return [
                'ok' => true,
                'result' => $result,
                'accounting' => $accounting,
                'staged' => false,
                'error' => null,
            ];
        } catch (Throwable $error) {
            if ($rememberFailureFingerprint) {
                $this->fingerprints->remember(
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    $guidFallback,
                    null,
                    null
                );
            }

            $staged = false;
            try {
                $staged = $this->stageFailure($source, $work, $relativePath, $error, $userId);
            } catch (Throwable $stageError) {
                $error = $stageError;
            }

            return [
                'ok' => false,
                'result' => null,
                'accounting' => ['imported' => 0, 'duplicates' => 0, 'locations' => 0],
                'staged' => $staged,
                'error' => $error,
            ];
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
     */
    public function stageFailure(
        array $source,
        array $work,
        string $relativePath,
        Throwable $error,
        ?int $userId
    ): bool {
        if (!is_file($work['path'])) {
            return false;
        }

        $sourceRelativePath = \catalog_source_scan_normalized_relative_path($relativePath, $work);
        $reason = 'Local Source Scan import failed for ' . $sourceRelativePath . ': ' . $error->getMessage();
        return $this->stager->stageFailedCopy(
            (int)$source['game_id'],
            $work['path'],
            $work['name'],
            $reason,
            $userId,
            $sourceRelativePath
        ) !== null;
    }

    /**
     * @param array<string,mixed> $source
     * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
     * @return array<int,mixed>
     */
    private function importWorkFile(
        array $source,
        array $work,
        string $relativePath,
        bool $strictProfile,
        ?int $userId
    ): array {
        return \scanner_scan_uploaded_file(
            $this->db,
            $this->config,
            (int)$source['game_id'],
            $this->temporaryCopy($work['path']),
            $work['name'],
            $userId,
            $strictProfile,
            null,
            false,
            ['source_relative_path' => \catalog_source_scan_normalized_relative_path($relativePath, $work)]
        );
    }

    private function temporaryCopy(string $path): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ue_src_scan_');
        if ($temporary === false) {
            throw new \RuntimeException('Could not create temporary file for profiled source import.');
        }
        if (!copy($path, $temporary)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not copy source file to temporary scan file.');
        }
        return $temporary;
    }
}
