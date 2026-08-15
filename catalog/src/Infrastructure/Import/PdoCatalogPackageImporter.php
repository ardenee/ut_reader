<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Imports verified Unreal packages into the catalog and implements the application package-import port.
 * Why: This class orchestrates classification, reading, identity/duplicate policy and post-import refresh while
 *      storage, row persistence, failed-upload retention and source-path writes are delegated to focused collaborators.
 * Role: Primary verified-package import orchestration for profile uploads, durable jobs and maintenance scanner delegates.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Dependency\CatalogPostImportDependencyQueue;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourcePathStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogVerifiedPackagePersistence;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogVerifiedPackageStorage;

final class PdoCatalogPackageImporter implements CatalogPackageImporter
{
    private readonly PdoCatalogDependencyRebuilder $dependencyRebuilder;
    private readonly PdoCatalogSourcePathStore $sourcePaths;
    private readonly PdoCatalogVerifiedPackagePersistence $persistence;
    private readonly CatalogVerifiedPackageStorage $storage;
    private readonly CatalogFailedUploadRetention $failedUploads;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $this->dependencyRebuilder = new PdoCatalogDependencyRebuilder($db, $config);
        $this->sourcePaths = new PdoCatalogSourcePathStore($db);
        $this->persistence = new PdoCatalogVerifiedPackagePersistence($db, $config);
        $this->storage = new CatalogVerifiedPackageStorage($config);
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
        // importUploadedFile() already publishes and verifies format-2 metadata
        // before returning a new verified result. Do not perform a second full
        // compact-container verification pass here.
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
     * Reader selection is authoritative-header-only. Filename extensions remain
     * storage/display metadata and never choose or override an engine parser.
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
        $this->sourcePaths->ensureSchema();
        $sourceRelativePath = \scanner_normalize_source_relative_path(
            (string)($scannerOptions['source_relative_path'] ?? '')
        );
        $deferDependencyRebuild = !empty($scannerOptions['defer_dependency_rebuild']);
        $maintenanceReplaceFileId = max(0, (int)($scannerOptions['maintenance_replace_file_id'] ?? 0));
        if ($maintenanceReplaceFileId > 0) {
            $target = \catalog_one(
                $this->db,
                'SELECT id,game_id,scan_status FROM ue_files WHERE id=?',
                [$maintenanceReplaceFileId]
            );
            if (!$target
                || (int)$target['game_id'] !== $gameId
                || (string)$target['scan_status'] !== 'verified') {
                throw new RuntimeException(
                    'Maintenance refresh target #' . $maintenanceReplaceFileId
                    . ' is no longer a verified package in the selected game.'
                );
            }
        }

        $submittedOriginalName = $originalName;
        $sourceOriginalName = \scanner_original_name_from_source_relative($sourceRelativePath);
        if ($sourceOriginalName !== '') {
            $originalName = $sourceOriginalName;
        }
        $originalName = \scanner_clean_original_filename($originalName);
        \scanner_emit_percent($progress, 'start', 0, 'Preparing ' . $originalName);

        $game = \catalog_one($this->db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('Game not found');
        }
        $profile = \gp_required_profile_for_game($this->db, $gameId);
        $profileEngine = strtoupper((string)$profile['engine_key']);
        $ext = \catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION));

        $size = filesize($temporaryPath) ?: 0;
        if ($size <= 0 || $size > (int)$this->config['max_upload_bytes']) {
            throw new RuntimeException('Bad file size: ' . \catalog_bytes((int)$size));
        }

        \scanner_emit_percent($progress, 'scan', 2, 'Reading package header');
        $classification = \gp_classify_file($this->db, $gameId, $temporaryPath, $originalName);
        if ($strictProfile && empty($classification['ok_for_selected_game'])) {
            $suggested = [];
            foreach ($classification['suggested_games'] as $suggestion) {
                $suggested[] = $suggestion['game_name'] . ' (' . $suggestion['engine_key'] . ')';
            }
            throw new CatalogInvalidPackageException(
                'Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown')
                . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. '
                . implode(' ', $classification['notes'])
                . ($suggested ? ' Suggested: ' . implode(', ', $suggested) : '')
            );
        }

        $readerEngine = strtoupper(trim((string)($classification['reader_engine'] ?? 'UNKNOWN')));
        $detectedEngine = strtoupper(trim((string)($classification['detected_engine'] ?? 'UNKNOWN')));
        if (!in_array($readerEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            throw new CatalogInvalidPackageException(
                'No supported package reader can be selected from serialized header data. '
                . implode(' ', (array)($classification['notes'] ?? []))
            );
        }

        $sourcePackageName = '';
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $sourcePackageName = \scanner_ue_package_name_from_source_relative($sourceRelativePath);
            if ($sourcePackageName === '') {
                throw new RuntimeException(
                    'UE4 package identity requires a mounted source-relative path, matching UT4 '
                    . 'FPackageName::FilenameToLongPackageName behaviour. Reimport using Local Source Scan, folder '
                    . 'upload, PAK import, or a source manifest path; single loose UE4 files cannot be catalogued safely.'
                );
            }
            $packageName = $sourcePackageName;
        } else {
            $packageName = \scanner_logical_package_name($originalName);
        }

        \scanner_emit_percent($progress, 'scan', 4, 'Hashing file');
        $md5 = md5_file($temporaryPath);
        $sha1 = sha1_file($temporaryPath);
        if (!$md5 || !$sha1) {
            throw new RuntimeException('Could not hash file');
        }

        \scanner_emit_percent($progress, 'scan', 7, 'Opening ' . $readerEngine . ' reader');
        $readerClass = \scanner_load_reader_class($this->config, $readerEngine);
        $ue4ReaderOptions = [];
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $ue4ReaderOptions = \catalog_ue4_reader_options($this->config, $game, $profile);
            \catalog_ue4_set_next_reader_options($ue4ReaderOptions);
        }
        $package = new $readerClass($temporaryPath);

        \scanner_emit_percent($progress, 'scan', 9, 'Validating package');
        $issues = method_exists($package, 'validatePackage')
            ? $package->validatePackage()
            : (method_exists($package, 'getDebugErrors') ? $package->getDebugErrors() : []);
        [$fatalIssues, $scanNotes] = \scanner_split_reader_issues($issues);
        if ($fatalIssues) {
            throw new CatalogInvalidPackageException(implode("\n", $fatalIssues));
        }
        foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
            if (!method_exists($package, $method)) {
                throw new RuntimeException('Reader is missing method: ' . $method);
            }
        }

        \scanner_emit_percent($progress, 'scan', 11, 'Reading header');
        $header = $package->getHeader();
        $packageGuid = (string)($header['guid'] ?? '');
        $names = $package->getNames();
        $packageName = \scanner_package_name_from_reader($packageName, $readerEngine, $names, $header);
        \catalog_package_aliases_ensure($this->db);

        // Only an active verified row is a canonical duplicate target. Historical
        // failed/duplicate/unverified rows must never absorb a new physical import.
        $duplicateSql = 'SELECT id, original_name, package_name, package_guid, file_size, md5 '
            . 'FROM ue_files WHERE game_id=? AND scan_status="verified"';
        $duplicateArgs = [$gameId];
        if ($packageGuid !== '') {
            $duplicateSql .= ' AND package_guid=? AND md5=?';
            $duplicateArgs[] = $packageGuid;
            $duplicateArgs[] = $md5;
        } else {
            $duplicateSql .= ' AND md5=? AND (package_guid IS NULL OR package_guid="")';
            $duplicateArgs[] = $md5;
        }
        if ($maintenanceReplaceFileId > 0) {
            $duplicateSql .= ' AND id<>?';
            $duplicateArgs[] = $maintenanceReplaceFileId;
        }
        $duplicateSql .= ' LIMIT 1';
        $duplicate = \catalog_one($this->db, $duplicateSql, $duplicateArgs);

        if ($duplicate) {
            if ($maintenanceReplaceFileId > 0) {
                throw new RuntimeException(
                    'Maintenance refresh would collide with existing file #' . (int)$duplicate['id']
                    . '; refusing to merge stable file identities automatically.'
                );
            }
            return $this->handleDuplicate(
                $gameId,
                $duplicate,
                $packageName,
                $packageGuid,
                $md5,
                (int)$size,
                $originalName,
                $sourceRelativePath,
                $classification,
                $deferDependencyRebuild,
                $progress
            );
        }

        \scanner_emit_percent($progress, 'scan', 14, 'Reading names table');
        \scanner_emit_percent($progress, 'scan', 17, 'Reading imports table');
        $imports = $package->getImports();
        \scanner_emit_percent($progress, 'scan', 20, 'Reading exports table');
        $exports = $package->getExports();
        $nameCount = count($names);
        $importCount = count($imports);
        $exportCount = count($exports);
        \scanner_emit_percent(
            $progress,
            'scan',
            22,
            'Read ' . $nameCount . ' names, ' . $importCount . ' imports, ' . $exportCount . ' exports'
        );

        $scanNotesText = $this->scanNotes(
            $classification,
            $profileEngine,
            $readerEngine,
            $packageName,
            $header,
            $ue4ReaderOptions,
            $scanNotes,
            $submittedOriginalName,
            $originalName,
            $sourceRelativePath,
            $sourcePackageName,
            $strictProfile,
            $detectedEngine,
            $game,
            $profile
        );

        \scanner_emit_percent($progress, 'database', 23, 'Storing file');
        $stored = $this->storage->store(
            $temporaryPath,
            (string)$game['slug'],
            $md5,
            $ext,
            $maintenanceReplaceFileId === 0
        );

        try {
            $fileId = $this->persistence->persist(
                $gameId,
                $packageName,
                $originalName,
                $sourceRelativePath,
                (string)$stored['stored_name'],
                (string)$stored['relative_path'],
                $ext,
                $classification,
                (int)$size,
                $md5,
                $sha1,
                $packageGuid,
                $header,
                $names,
                $imports,
                $exports,
                $scanNotesText,
                $userId,
                $progress,
                $maintenanceReplaceFileId
            );
        } catch (Throwable $error) {
            $this->storage->rollbackCreated($stored);
            throw $error;
        }

        $resultLabel = ($classification['compatibility_status'] ?? 'native') === 'legacy_compatible'
            ? ('; ' . (string)($classification['compatibility_label'] ?? 'legacy-compatible'))
            : '';
        $verb = $maintenanceReplaceFileId > 0 ? 'Refreshed' : 'Imported';
        $result = [
            'verified',
            $fileId,
            $verb . '. Profile=' . $profileEngine . ', reader=' . $readerEngine
                . ', detection=' . $classification['confidence'] . $resultLabel
                . ', size=' . \catalog_bytes((int)$size)
                . ', names=' . $nameCount . ', imports=' . $importCount . ', exports=' . $exportCount,
            $classification,
            [
                'file_id' => $fileId,
                'package_name' => $packageName,
                'package_guid' => $packageGuid,
                'file_size' => (int)$size,
                'file_size_text' => \catalog_bytes((int)$size),
                'source_relative_path' => $sourceRelativePath,
                'maintenance_replace_file_id' => $maintenanceReplaceFileId,
            ],
        ];
        $result = VerifiedFileCompactMetadataFinalizer::finalizeParsed(
            $this->db,
            $this->config,
            $result,
            $names,
            $imports,
            $exports,
            null
        );

        $refreshWarning = '';
        if ($deferDependencyRebuild) {
            \scanner_emit_percent(
                $progress,
                'dependencies',
                99,
                'Affected dependency refresh deferred to the final Full Sync pass'
            );
        } else {
            try {
                $this->dependencyRebuilder->rebuildAffected($fileId, $progress, 56, 99);
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
                    $refreshWarning = '; synchronous dependency refresh failed; durable repair job #'
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
        if ($refreshWarning !== '') {
            $result[2] = (string)$result[2] . $refreshWarning;
        }

        \scanner_emit_percent(
            $progress,
            'done',
            100,
            $verb . ' ' . $nameCount . ' names, ' . $importCount
            . ' imports, ' . $exportCount . ' exports with compact metadata'
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

    /** @param array<string,mixed> $duplicate @param array<string,mixed> $classification */
    private function handleDuplicate(
        int $gameId,
        array $duplicate,
        string $packageName,
        string $packageGuid,
        string $md5,
        int $size,
        string $originalName,
        string $sourceRelativePath,
        array $classification,
        bool $deferDependencyRebuild,
        ?callable $progress
    ): array {
        $duplicateFileId = (int)$duplicate['id'];
        $this->sourcePaths->recordIfMissing($duplicateFileId, $sourceRelativePath);
        $duplicatePackageName = (string)$duplicate['package_name'];
        $meta = [
            'file_id' => $duplicateFileId,
            'file_size' => $size,
            'file_size_text' => \catalog_bytes($size),
            'package_name' => $packageName,
            'package_guid' => $packageGuid,
            'md5' => $md5,
            'duplicate_file_id' => $duplicateFileId,
            'duplicate_original_name' => \catalog_clean_unreal_filename((string)$duplicate['original_name']),
            'duplicate_package_name' => $duplicatePackageName,
            'duplicate_md5' => (string)($duplicate['md5'] ?? ''),
        ];

        if (strcasecmp($duplicatePackageName, $packageName) === 0
            || \catalog_package_alias_exists($this->db, $duplicateFileId, $gameId, $packageName)) {
            \scanner_emit_percent($progress, 'done', 100, 'Duplicate in selected game');
            return ['duplicate', $duplicateFileId, 'Duplicate in selected game', $classification, $meta];
        }

        \catalog_package_alias_add(
            $this->db,
            $duplicateFileId,
            $gameId,
            $packageName,
            $originalName,
            $packageGuid,
            $md5,
            $size
        );
        $refreshWarning = '';
        if ($deferDependencyRebuild) {
            \scanner_emit_percent(
                $progress,
                'dependencies',
                99,
                'Alias dependency refresh deferred to the final Full Sync pass'
            );
        } else {
            try {
                $this->dependencyRebuilder->rebuildAffectedForPackage(
                    $gameId,
                    $packageName,
                    $progress,
                    56,
                    99,
                    $duplicateFileId
                );
            } catch (Throwable $refreshError) {
                error_log(
                    '[UnrealDB dependency refresh] alias_package=' . $packageName
                    . ' file_id=' . $duplicateFileId
                    . ' error=' . $refreshError->getMessage()
                );
                $refreshWarning = '; dependency refresh warning logged for maintenance';
            }
        }

        \scanner_emit_percent($progress, 'done', 100, 'Alias package added for existing file identity');
        $meta['alias_package_name'] = $packageName;
        $meta['alias_added'] = true;
        return [
            'alias',
            $duplicateFileId,
            'Package alias added for existing file identity' . $refreshWarning,
            $classification,
            $meta,
        ];
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $header
     * @param list<string> $scanNotes
     * @param array<string,mixed> $ue4ReaderOptions
     * @param array<string,mixed> $game
     * @param array<string,mixed> $profile
     */
    private function scanNotes(
        array $classification,
        string $profileEngine,
        string $readerEngine,
        string $packageName,
        array $header,
        array $ue4ReaderOptions,
        array $scanNotes,
        string $submittedOriginalName,
        string $originalName,
        string $sourceRelativePath,
        string $sourcePackageName,
        bool $strictProfile,
        string $detectedEngine,
        array $game,
        array $profile
    ): ?string {
        $cleanNote = $submittedOriginalName !== $originalName
            ? '; cleaned filename=' . $originalName . ' from upload=' . basename($submittedOriginalName)
            : '';
        $sourceNote = $sourceRelativePath !== '' ? '; source-relative=' . $sourceRelativePath : '';
        $sourcePackageNote = $sourcePackageName !== '' ? '; source package=' . $sourcePackageName : '';
        $parserNote = '';
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $parserProfile = is_array($header['parserProfile'] ?? null) && $header['parserProfile']
                ? $header['parserProfile']
                : ($ue4ReaderOptions['parser_profile']
                    ?? \catalog_ue4_parser_profile($this->config, $game, $profile));
            $parserKey = (string)($header['parserProfileKey'] ?? ($parserProfile['profile_key'] ?? 'standard-ue4'));
            $parserLabel = (string)(
                $header['parserProfileLabel']
                ?? ($parserProfile['label'] ?? 'Standard UE4 package parser')
            );
            $assumedParserVersion = (int)(
                $header['assumedUnversionedParserVersion']
                ?? ($parserProfile['assumed_unversioned_parser_version'] ?? 0)
            );
            $parserNote = '; UE4 parser profile=' . $parserKey . ' (' . $parserLabel . ')'
                . ($assumedParserVersion > 0
                    ? '; assumed UE4 parser version=' . $assumedParserVersion
                    : '');
        }

        $notes = array_merge($scanNotes, [
            'Profile engine=' . $profileEngine
            . '; header-selected package reader=' . $readerEngine
            . $parserNote
            . '; package=' . $packageName
            . '; compatibility=' . ($classification['compatibility_status'] ?? 'native')
            . '; detection=' . $classification['confidence']
            . $cleanNote . $sourceNote . $sourcePackageNote
            . '; ' . implode(' ', $classification['notes'])
        ]);

        if (!$strictProfile && ($detectedEngine !== '' && $detectedEngine !== $profileEngine)) {
            $notes[] = 'Administrator compatibility override: catalogued under ' . $profileEngine
                . ' game using header-detected ' . $detectedEngine . ' reader.';
        }

        return $notes ? implode("\n", $notes) : null;
    }
}
