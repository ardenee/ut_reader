<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `BlockedCompressedMetadataSnapshotLoader` for blocked compressed metadata
 *          snapshot loader.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/** Loads complete or dependency-only snapshots from a format-2 blocked container. */
final class BlockedCompressedMetadataSnapshotLoader
{
    private const PAGE_SIZE = 5000;

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact metadata loading.');
        }
    }

    /** @return array<string,mixed> */
    public function load(int $fileId): array
    {
        $file = $this->loadFileRow($fileId);
        $reader = new BlockedCompressedMetadataReader($this->db, $this->storageRoot);
        $names = $this->loadSection($reader, $fileId, 'names', (int)$file['name_count']);
        $imports = $this->loadSection($reader, $fileId, 'imports', (int)$file['import_count']);
        $exports = $this->loadSection($reader, $fileId, 'exports', (int)$file['export_count']);
        $dependencies = $this->decorateDependencies(
            $fileId,
            $this->loadSection($reader, $fileId, 'dependencies', (int)$file['import_count'])
        );

        $paths = ['imports' => [], 'exports' => []];
        foreach ($imports as $row) {
            $index = (int)$row['import_index'];
            $paths['imports'][$index] = [
                'full' => (string)$row['full_path'],
                'root' => (string)$row['root_package'],
                'relative' => (string)$row['relative_object_path'],
            ];
        }
        foreach ($exports as $row) {
            $index = (int)$row['export_index'];
            $paths['exports'][$index] = [
                'local' => (string)$row['local_path'],
                'full' => (string)$row['full_path'],
            ];
        }

        return [
            'file' => $this->snapshotFile($fileId, $file),
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
            'paths' => $paths,
            'source_format' => 'blocked-metadata-v2',
        ];
    }

    /**
     * Load only the sections required to re-resolve dependencies.
     *
     * Targeted provider refreshes frequently confirm that the current resolution
     * is already correct. Avoid inflating names and exports for that no-change
     * path; callers can request load() only when a container rewrite is needed.
     *
     * @return array<string,mixed>
     */
    public function loadDependencySnapshot(int $fileId): array
    {
        $file = $this->loadFileRow($fileId);
        $reader = new BlockedCompressedMetadataReader($this->db, $this->storageRoot);
        $imports = $this->loadSection($reader, $fileId, 'imports', (int)$file['import_count']);
        $dependencies = $this->decorateDependencies(
            $fileId,
            $this->loadSection($reader, $fileId, 'dependencies', (int)$file['import_count'])
        );

        return [
            'file' => $this->snapshotFile($fileId, $file),
            'imports' => $imports,
            'dependencies' => $dependencies,
            'source_format' => 'blocked-metadata-v2-dependencies',
        ];
    }

    /** @return array<string,mixed> */
    private function loadFileRow(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }

        $statement = $this->db->prepare(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,f.scan_status,'
            . 'm.format_version,m.codec,m.name_count metadata_name_count,'
            . 'm.import_count metadata_import_count,m.export_count metadata_export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id WHERE f.id=?'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            throw new RuntimeException('File #' . $fileId . ' has no compact metadata registration.');
        }
        if ((string)$file['scan_status'] !== 'verified') {
            throw new RuntimeException('File #' . $fileId . ' is not verified.');
        }
        if ((int)$file['format_version'] !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('File #' . $fileId . ' is not using blocked metadata format version 2.');
        }
        if ((int)$file['codec'] !== BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP) {
            throw new RuntimeException('File #' . $fileId . ' uses an unsupported compact metadata codec.');
        }

        foreach (['name', 'import', 'export'] as $type) {
            if ((int)$file[$type . '_count'] !== (int)$file['metadata_' . $type . '_count']) {
                throw new RuntimeException('File #' . $fileId . ' ' . $type . ' count differs from ue_file_metadata.');
            }
        }
        return $file;
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function snapshotFile(int $fileId, array $file): array
    {
        return [
            'id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'name_count' => (int)$file['name_count'],
            'import_count' => (int)$file['import_count'],
            'export_count' => (int)$file['export_count'],
            'scan_status' => (string)$file['scan_status'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loadSection(
        BlockedCompressedMetadataReader $reader,
        int $fileId,
        string $section,
        int $expectedCount
    ): array {
        $rows = [];
        for ($start = 0; $start < $expectedCount; $start += self::PAGE_SIZE) {
            $page = $reader->page($fileId, $section, $start, self::PAGE_SIZE);
            if ($page === []) {
                throw new RuntimeException(
                    'Compact ' . $section . ' section ended at row ' . $start
                    . ' of ' . $expectedCount . ' for file #' . $fileId . '.'
                );
            }
            array_push($rows, ...$page);
        }
        if (count($rows) !== $expectedCount) {
            throw new RuntimeException(
                'Compact ' . $section . ' row count mismatch for file #' . $fileId
                . ': expected ' . $expectedCount . ', found ' . count($rows) . '.'
            );
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $dependencies @return list<array<string,mixed>> */
    private function decorateDependencies(int $fileId, array $dependencies): array
    {
        $labels = $this->resolutionLabels($fileId);
        if (count($labels) !== count($dependencies)) {
            throw new RuntimeException(
                'Compact dependency label count mismatch for file #' . $fileId
                . ': dependencies=' . count($dependencies) . ', labels=' . count($labels) . '.'
            );
        }
        foreach ($dependencies as &$dependency) {
            $index = (int)$dependency['import_index'];
            $label = $labels[$index] ?? null;
            if (!is_array($label)) {
                throw new RuntimeException('Missing compact resolution labels for Import #' . $index . '.');
            }
            $dependency['file_id'] = $fileId;
            $dependency['resolution_source'] = $label['source'];
            $dependency['resolution_confidence'] = $label['confidence'];
        }
        unset($dependency);
        return $dependencies;
    }

    /** @return array<int,array{source:string,confidence:string}> */
    private function resolutionLabels(int $fileId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.import_index,st.value_prefix source_value,st.is_overflow source_overflow,'
            . 'ct.value_prefix confidence_value,ct.is_overflow confidence_overflow '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_terms st ON st.id=l.resolution_source_term_id '
            . 'JOIN ue_terms ct ON ct.id=l.resolution_confidence_term_id '
            . 'WHERE l.file_id=? ORDER BY l.import_index'
        );
        $statement->execute([$fileId]);
        $labels = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((int)$row['source_overflow'] !== 0 || (int)$row['confidence_overflow'] !== 0) {
                throw new RuntimeException('Dependency resolution labels cannot be reconstructed from overflow terms.');
            }
            $source = (string)$row['source_value'];
            $confidence = (string)$row['confidence_value'];
            if ($source === '' || $confidence === '') {
                throw new RuntimeException('Dependency resolution labels are empty for Import #' . (int)$row['import_index'] . '.');
            }
            $labels[(int)$row['import_index']] = [
                'source' => $source,
                'confidence' => $confidence,
            ];
        }
        return $labels;
    }
}
