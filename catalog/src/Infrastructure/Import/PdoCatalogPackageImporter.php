<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Imports verified Unreal packages into the catalog and implements the application package-import port.
 * Why: Package parsing, PDO persistence, verified-file storage, compact metadata finalisation and dependency refresh
 *      are infrastructure concerns rather than procedural scanner responsibilities.
 * Role: Primary verified-package import implementation for profile uploads, durable jobs and legacy scanner delegates.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;

final class PdoCatalogPackageImporter implements CatalogPackageImporter
{
    private readonly PdoCatalogDependencyRebuilder $dependencyRebuilder;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $this->dependencyRebuilder = new PdoCatalogDependencyRebuilder($db, $config);
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

        // Preserve the established ProfiledUploadService adapter contract: the
        // scanner-compatible path finalises verified metadata first, then this
        // port-level call verifies the compact result while reporting progress.
        $result = VerifiedFileCompactMetadataFinalizer::finalize(
            $this->db,
            $this->config,
            $result,
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
        \scanner_source_path_schema_ensure($this->db);
        $sourceRelativePath = \scanner_normalize_source_relative_path(
            (string)($scannerOptions['source_relative_path'] ?? '')
        );
        $deferDependencyRebuild = !empty($scannerOptions['defer_dependency_rebuild']);
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
        $profileExtensions = \scanner_profile_extensions($profile, $this->config);
        $extensionOutsideProfile = !in_array($ext, $profileExtensions, true);
        if ($extensionOutsideProfile && !$allowProfileOverride) {
            throw new RuntimeException(
                'Extension not allowed by assigned profile: ' . $ext
                . '. Allowed: ' . implode(', ', $profileExtensions)
            );
        }

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
            throw new RuntimeException(
                'Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown')
                . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. '
                . implode(' ', $classification['notes'])
                . ($suggested ? ' Suggested: ' . implode(', ', $suggested) : '')
            );
        }

        $readerEngine = strtoupper((string)($classification['reader_engine'] ?? $profileEngine));
        $detectedEngine = strtoupper((string)($classification['detected_engine'] ?? ''));
        if ((!$strictProfile || $allowProfileOverride)
            && in_array($detectedEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            $readerEngine = $detectedEngine;
        }
        if ($readerEngine === '') {
            $readerEngine = $profileEngine;
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
            throw new RuntimeException(implode("\n", $fatalIssues));
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

        if ($packageGuid !== '') {
            $duplicate = \catalog_one(
                $this->db,
                'SELECT id, original_name, package_name, package_guid, file_size, md5 '
                . 'FROM ue_files WHERE game_id=? AND package_guid=? AND md5=?',
                [$gameId, $packageGuid, $md5]
            );
        } else {
            $duplicate = \catalog_one(
                $this->db,
                'SELECT id, original_name, package_name, package_guid, file_size, md5 '
                . 'FROM ue_files WHERE game_id=? AND md5=? AND (package_guid IS NULL OR package_guid="")',
                [$gameId, $md5]
            );
        }

        if ($duplicate) {
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
            $extensionOutsideProfile,
            $strictProfile,
            $detectedEngine,
            $ext,
            $game,
            $profile
        );

        \scanner_emit_percent($progress, 'database', 23, 'Storing file');
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . '/games/' . \scanner_slug_text((string)$game['slug']) . '/verified';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create storage folder: ' . $directory);
        }
        $storedName = $md5 . '.' . $ext;
        $destination = $directory . '/' . $storedName;
        $storedFileCreated = false;
        if (is_file($destination)) {
            if (is_file($temporaryPath) && !@unlink($temporaryPath)) {
                throw new RuntimeException('Could not discard duplicate physical upload');
            }
        } elseif (!rename($temporaryPath, $destination)) {
            throw new RuntimeException('Could not store upload');
        } else {
            $storedFileCreated = true;
        }
        $relativePath = 'storage/games/' . \scanner_slug_text((string)$game['slug'])
            . '/verified/' . $storedName;

        $fileId = 0;
        try {
            $fileId = $this->persistLegacyStagingRows(
                $gameId,
                $packageName,
                $originalName,
                $sourceRelativePath,
                $storedName,
                $relativePath,
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
                $progress
            );
        } catch (Throwable $error) {
            if ($storedFileCreated && is_file($destination)) {
                @unlink($destination);
            }
            throw $error;
        }

        $resultLabel = ($classification['compatibility_status'] ?? 'native') === 'legacy_compatible'
            ? ('; ' . (string)($classification['compatibility_label'] ?? 'legacy-compatible'))
            : '';
        $result = [
            'verified',
            $fileId,
            'Imported. Profile=' . $profileEngine . ', reader=' . $readerEngine
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
            ],
        ];
        $result = VerifiedFileCompactMetadataFinalizer::finalize(
            $this->db,
            $this->config,
            $result,
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
                $refreshWarning = '; dependency refresh warning logged for maintenance';
            }
        }
        if ($refreshWarning !== '') {
            $result[2] = (string)$result[2] . $refreshWarning;
        }

        \scanner_emit_percent(
            $progress,
            'done',
            100,
            'Imported ' . $nameCount . ' names, ' . $importCount
            . ' imports, ' . $exportCount . ' exports with compact metadata'
        );
        return $result;
    }

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason
    ): void {
        if (!is_file($temporaryPath)) {
            return;
        }

        $bytes = @file_get_contents($temporaryPath, false, null, 0, 4);
        $tag = is_string($bytes) && strlen($bytes) === 4 ? (int)(unpack('V', $bytes)[1] ?? 0) : 0;
        if ($tag !== 0x9E2A83C1) {
            @unlink($temporaryPath);
            return;
        }

        \scanner_store_failed_upload($this->config, $temporaryPath, $originalName, $gameSlug, $reason);
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
        \scanner_record_source_relative_path($this->db, $duplicateFileId, $sourceRelativePath);
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
        bool $extensionOutsideProfile,
        bool $strictProfile,
        string $detectedEngine,
        string $extension,
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
            . '; package reader=' . $readerEngine
            . $parserNote
            . '; package=' . $packageName
            . '; compatibility=' . ($classification['compatibility_status'] ?? 'native')
            . '; detection=' . $classification['confidence']
            . $cleanNote . $sourceNote . $sourcePackageNote
            . '; ' . implode(' ', $classification['notes'])
        ]);

        if ($extensionOutsideProfile) {
            $notes[] = 'Administrator override: extension .' . $extension
                . ' is outside the assigned profile extension list.';
        }
        if (!$strictProfile && ($detectedEngine !== '' && $detectedEngine !== $profileEngine)) {
            $notes[] = 'Administrator compatibility override: catalogued under ' . $profileEngine
                . ' game using detected ' . $detectedEngine . ' reader.';
        }

        return $notes ? implode("\n", $notes) : null;
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $header
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     */
    private function persistLegacyStagingRows(
        int $gameId,
        string $packageName,
        string $originalName,
        string $sourceRelativePath,
        string $storedName,
        string $relativePath,
        string $extension,
        array $classification,
        int $size,
        string $md5,
        string $sha1,
        string $packageGuid,
        array $header,
        array $names,
        array $imports,
        array $exports,
        ?string $scanNotes,
        ?int $userId,
        ?callable $progress
    ): int {
        $nameCount = count($names);
        $importCount = count($imports);
        $exportCount = count($exports);
        $totalRows = max(1, $nameCount + $importCount + $exportCount + 1);
        $writtenRows = 0;
        $progressDb = static function (string $message, int $rowsDone = 1) use (
            $progress,
            &$writtenRows,
            $totalRows
        ): void {
            $writtenRows = min($totalRows, $writtenRows + max(1, $rowsDone));
            \scanner_emit_percent(
                $progress,
                'database',
                \scanner_range_percent(23, 35, $writtenRows, $totalRows),
                $message
            );
        };

        try {
            $this->db->beginTransaction();
            $statement = $this->db->prepare(
                'INSERT INTO ue_files('
                . 'game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
                . 'detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,'
                . 'compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,'
                . 'package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                $gameId,
                $packageName,
                $originalName,
                $sourceRelativePath !== '' ? $sourceRelativePath : null,
                $storedName,
                $relativePath,
                $extension,
                $classification['detected_engine'],
                $classification['package_version'],
                $classification['licensee_version'],
                $classification['confidence'],
                $classification['compatibility_status'] ?? 'native',
                $classification['compatibility_label'] ?? null,
                implode("\n", $classification['notes']),
                $size,
                $md5,
                $sha1,
                $packageGuid,
                (int)($header['version'] ?? 0),
                (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)),
                $nameCount,
                $importCount,
                $exportCount,
                'verified',
                $scanNotes,
                $userId,
            ]);
            $fileId = (int)$this->db->lastInsertId();
            $progressDb('Writing file row');

            $batch = [];
            foreach ($names as $index => $name) {
                $batch[] = [
                    $fileId,
                    $index,
                    (string)($name['name'] ?? $name['text'] ?? ''),
                    isset($name['flags']) ? (int)$name['flags'] : null,
                ];
                $done = $index + 1;
                if (count($batch) >= 250 || $done === $nameCount) {
                    $batchCount = count($batch);
                    \scanner_bulk_insert(
                        $this->db,
                        'ue_names',
                        ['file_id', 'name_index', 'name_text', 'flags'],
                        $batch
                    );
                    $batch = [];
                    $progressDb('Writing names table ' . $done . '/' . $nameCount, $batchCount);
                }
            }

            $cache = [];
            $common = array_map('strtolower', $this->config['common_packages'] ?? []);
            $batch = [];
            foreach ($imports as $index => $import) {
                $fullPath = \scanner_ref_path(-($index + 1), $imports, $exports, $cache);
                $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
                $rootPackage = $parts[0] ?? '';
                $relativeObjectPath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
                $batch[] = [
                    $fileId,
                    $index,
                    (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')),
                    (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')),
                    (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')),
                    (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0),
                    $fullPath,
                    $rootPackage,
                    $relativeObjectPath,
                    in_array(strtolower((string)$rootPackage), $common, true) ? 1 : 0,
                ];
                $done = $index + 1;
                if (count($batch) >= 250 || $done === $importCount) {
                    $batchCount = count($batch);
                    \scanner_bulk_insert(
                        $this->db,
                        'ue_imports',
                        [
                            'file_id', 'import_index', 'class_package', 'class_name', 'object_name',
                            'outer_index', 'full_path', 'root_package', 'relative_object_path', 'is_common',
                        ],
                        $batch
                    );
                    $batch = [];
                    $progressDb('Writing imports table ' . $done . '/' . $importCount, $batchCount);
                }
            }

            $batch = [];
            foreach ($exports as $index => $export) {
                $localPath = \scanner_ref_path($index + 1, $imports, $exports, $cache);
                $classReference = (int)($export['classIndex'] ?? $export['class'] ?? 0);
                $className = $classReference
                    ? \scanner_ref_path($classReference, $imports, $exports, $cache)
                    : '';
                $batch[] = [
                    $fileId,
                    $index,
                    $className,
                    (string)($export['objectNameText'] ?? ''),
                    (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0),
                    $localPath,
                    \scanner_join_path_parts([$packageName, $localPath]),
                    isset($export['objectFlags']) ? (int)$export['objectFlags'] : null,
                    isset($export['serialSize']) ? (int)$export['serialSize'] : null,
                    isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
                ];
                $done = $index + 1;
                if (count($batch) >= 250 || $done === $exportCount) {
                    $batchCount = count($batch);
                    \scanner_bulk_insert(
                        $this->db,
                        'ue_exports',
                        [
                            'file_id', 'export_index', 'class_name', 'object_name', 'outer_index',
                            'local_path', 'full_path', 'object_flags', 'serial_size', 'serial_offset',
                        ],
                        $batch
                    );
                    $batch = [];
                    $progressDb('Writing exports table ' . $done . '/' . $exportCount, $batchCount);
                }
            }

            \scanner_emit_percent($progress, 'dependencies', 36, 'Rebuilding dependencies for imported file');
            $this->dependencyRebuilder->rebuild(
                $fileId,
                $progress,
                36,
                55,
                'Imported file dependency links'
            );
            $this->db->commit();
            return $fileId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
