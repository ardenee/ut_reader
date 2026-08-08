<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rebuilds explicit UE asset metadata and parsed reference metadata for one catalog file.
 * Why: Reader selection, safe storage resolution, reference extraction and metadata persistence form one bounded metadata use case rather than a procedural helper collection.
 * Role: Infrastructure metadata service preserving the existing Asset Metadata Rebuild behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;

final class CatalogAssetMetadataService
{
    private const MAX_SOFT_REFS = 2000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogDependencySchema.php';
        require_once $root . '/lib/GameProfiles.php';
        require_once $root . '/lib/CatalogUE4ParserProfile.php';
    }

    /**
     * Rebuilds explicit export-derived asset metadata plus parsed UE4 summary-level
     * reference metadata. It does not invent redirector aliases or folder/object
     * guesses; every dependency row is tagged by source.
     *
     * @param object|null $reader Optional already-open package reader.
     * @return array{assets:int,string_asset_refs:int,preload_deps:int,soft_refs:int,redirectors:int}
     */
    public function rebuildFile(int $fileId, ?object $reader = null): array
    {
        \catalog_dependency_schema_ensure($this->db);

        $file = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new RuntimeException('File not found: ' . $fileId);
        }

        $path = $this->resolvePath($file);
        if ($reader === null) {
            try {
                $reader = $this->openReader($file, $path);
            } catch (Throwable $error) {
                error_log(
                    '[UnrealDB asset metadata reader] file_id=' . $fileId
                    . ' error=' . $error->getMessage()
                );
            }
        }

        $this->db->prepare('DELETE FROM ue_asset_registry_dependencies WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_asset_registry_assets WHERE file_id=?')->execute([$fileId]);

        $exports = \catalog_all(
            $this->db,
            'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index',
            [$fileId]
        );
        $insertAsset = $this->db->prepare(
            'INSERT IGNORE INTO ue_asset_registry_assets('
            . 'file_id,object_path,package_name,package_path,asset_name,asset_class'
            . ') VALUES(?,?,?,?,?,?)'
        );

        $packageName = (string)$file['package_name'];
        $packagePath = $this->packagePath($packageName);
        $assets = 0;
        $redirectors = 0;

        foreach ($exports as $export) {
            $objectPath = trim((string)($export['full_path'] ?? ''));
            $assetName = trim((string)($export['object_name'] ?? ''));
            if ($objectPath === '' || $assetName === '') {
                continue;
            }

            $className = (string)($export['class_name'] ?? '');
            $insertAsset->execute([
                $fileId,
                $objectPath,
                $packageName,
                $packagePath,
                $assetName,
                $className,
            ]);
            $assets += $insertAsset->rowCount() > 0 ? 1 : 0;

            if (stripos($className, 'ObjectRedirector') !== false) {
                $this->insertDependency(
                    $fileId,
                    null,
                    $objectPath,
                    'object_redirector_unparsed'
                );
                $redirectors++;
            }
        }

        $stringRefs = 0;
        if ($reader !== null && method_exists($reader, 'getStringAssetReferences')) {
            $refs = $reader->getStringAssetReferences();
            if (is_array($refs)) {
                foreach ($refs as $ref) {
                    $refPath = is_array($ref) ? (string)($ref['path'] ?? '') : (string)$ref;
                    if ($refPath === $packageName || str_starts_with($refPath, $packageName . '.')) {
                        continue;
                    }
                    if ($this->insertDependency(
                        $fileId,
                        null,
                        $refPath,
                        'string_asset_reference'
                    )) {
                        $stringRefs++;
                    }
                }
            }
        }

        $preloadDeps = 0;
        if ($reader !== null && method_exists($reader, 'getPreloadDependencies')) {
            $deps = $reader->getPreloadDependencies();
            if (is_array($deps)) {
                foreach ($deps as $dep) {
                    if (!is_array($dep)) {
                        continue;
                    }
                    $depPath = (string)($dep['path'] ?? '');
                    if ($depPath === ''
                        || $depPath === $packageName
                        || str_starts_with($depPath, $packageName . '.')) {
                        continue;
                    }
                    if ($this->insertDependency(
                        $fileId,
                        null,
                        $depPath,
                        'preload_dependency'
                    )) {
                        $preloadDeps++;
                    }
                }
            }
        }

        $softRefs = $path ? $this->extractSoftReferenceCandidates($path) : [];
        $softCount = 0;
        foreach ($softRefs as $ref) {
            if ($ref === $packageName || str_starts_with($ref, $packageName . '.')) {
                continue;
            }
            if ($this->insertDependency(
                $fileId,
                null,
                $ref,
                'soft_reference_candidate'
            )) {
                $softCount++;
            }
        }

        return [
            'assets' => $assets,
            'string_asset_refs' => $stringRefs,
            'preload_deps' => $preloadDeps,
            'soft_refs' => $softCount,
            'redirectors' => $redirectors,
        ];
    }

    private function packagePath(string $packageName): string
    {
        $packageName = rtrim(str_replace('\\', '/', trim($packageName)), '/');
        if ($packageName === '') {
            return '';
        }
        $position = strrpos($packageName, '/');
        return $position === false || $position === 0 ? '' : substr($packageName, 0, $position);
    }

    /** @return list<string> */
    private function extractSoftReferenceCandidates(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return [];
        }

        $sources = [
            preg_replace('/[^\x20-\x7E]+/', "\n", $bytes) ?? '',
            preg_replace('/[^\x20-\x7E]+/', "\n", str_replace("\0", '', $bytes)) ?? '',
        ];

        $refs = [];
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            if (preg_match_all(
                '~/(?:Game|Engine|Script|Plugin|Plugins|[A-Za-z0-9_]+)/[A-Za-z0-9_./$+\-]+(?:\.[A-Za-z0-9_$+\-]+)?~',
                $source,
                $matches
            )) {
                foreach ($matches[0] as $match) {
                    $value = trim((string)$match, " \t\r\n\0\x0B.,;:'\"()[]{}<>");
                    if ($value !== '' && strlen($value) <= 1000) {
                        $refs[$value] = true;
                    }
                }
            }
        }

        return array_slice(array_keys($refs), 0, self::MAX_SOFT_REFS);
    }

    /** @param array<string,mixed> $file */
    private function resolvePath(array $file): ?string
    {
        $relative = (string)($file['relative_path'] ?? '');
        if ($relative === '') {
            return null;
        }

        $path = realpath(dirname(__DIR__, 3) . '/' . $relative);
        $storageRoot = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        if (!$path || !$storageRoot || !str_starts_with($path, $storageRoot)) {
            return null;
        }

        return $path;
    }

    private function normalizeReference(string $reference): string
    {
        $reference = trim(str_replace('\\', '/', $reference));
        $reference = trim($reference, " \t\r\n\0\x0B.,;:'\"()[]{}<>");
        return strlen($reference) <= 1000 ? $reference : '';
    }

    private function insertDependency(
        int $fileId,
        ?int $sourceAssetId,
        string $path,
        string $type
    ): bool {
        $path = $this->normalizeReference($path);
        $type = preg_replace('/[^a-z0-9_\-]+/i', '_', trim($type)) ?? 'unknown';
        if ($path === '' || $type === '') {
            return false;
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_asset_registry_dependencies('
            . 'file_id,source_asset_id,dependency_object_path,dependency_type'
            . ') VALUES(?,?,?,?)'
        );
        $statement->execute([$fileId, $sourceAssetId, $path, $type]);
        return true;
    }

    /** @param array<string,mixed> $file */
    private function readerEngine(array $file): string
    {
        $engine = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        if (in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            return $engine;
        }

        $fallback = strtoupper((string)(\gp_detect_from_extension(
            (string)($file['extension'] ?? '')
        ) ?? ''));
        return in_array($fallback, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)
            ? $fallback
            : '';
    }

    /** @param array<string,mixed> $file */
    private function openReader(array $file, ?string $path): ?object
    {
        if (!$path || !is_file($path)) {
            return null;
        }

        $engine = $this->readerEngine($file);
        if (!in_array($engine, ['UE4', 'UE5'], true)) {
            return null;
        }

        $readerClass = CatalogReaderResolver::resolve(
            $this->config,
            $engine,
            'Reader not found for package engine',
            'Reader file loaded for package engine ',
            ['UE4', 'UE5']
        );

        $game = \catalog_one(
            $this->db,
            'SELECT * FROM ue_games WHERE id=?',
            [(int)$file['game_id']]
        ) ?: [];
        $profile = \gp_required_profile_for_game($this->db, (int)$file['game_id']);
        \catalog_ue4_set_next_reader_options(
            \catalog_ue4_reader_options($this->config, $game, $profile)
        );

        $reader = new $readerClass($path);
        if (method_exists($reader, 'validatePackage')) {
            $issues = $reader->validatePackage();
            if (is_array($issues)) {
                foreach ($issues as $issue) {
                    $text = trim((string)$issue);
                    if ($text !== ''
                        && !str_starts_with(
                            $text,
                            'Package is unversioned; using assumed UE4 parser version '
                        )) {
                        error_log(
                            '[UnrealDB asset metadata reader] file_id=' . (int)$file['id']
                            . ' issue=' . $text
                        );
                    }
                }
            }
        }

        return $reader;
    }
}
