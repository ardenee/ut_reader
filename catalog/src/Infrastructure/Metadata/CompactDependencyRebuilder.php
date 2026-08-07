<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompactDependencyRebuilder` for compact dependency rebuilder.
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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

/** Re-resolves one file's Imports and rewrites its compact dependency section. */
final class CompactDependencyRebuilder
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact dependency rebuilding.');
        }
    }

    /** @return array<string,mixed> */
    public function rebuild(int $fileId): array
    {
        $loader = new BlockedCompressedMetadataSnapshotLoader($this->db, $this->storageRoot);
        $snapshot = $loader->load($fileId);
        $file = (array)$snapshot['file'];
        $imports = array_values((array)$snapshot['imports']);
        $previous = [];
        foreach ((array)$snapshot['dependencies'] as $row) {
            if (is_array($row)) {
                $previous[(int)$row['import_index']] = $row;
            }
        }

        $resolutions = PdoDependencyResolver::resolve(
            $this->db,
            (int)$file['game_id'],
            $fileId,
            $imports
        );

        $dependencies = [];
        $changed = 0;
        foreach ($imports as $import) {
            if (!is_array($import)) {
                throw new RuntimeException('Compact Import snapshot contains a non-row value.');
            }
            $importId = (int)($import['id'] ?? 0);
            $importIndex = (int)($import['import_index'] ?? -1);
            if ($importId < 1 || $importIndex < 0) {
                throw new RuntimeException('Compact Import snapshot contains an invalid identity.');
            }

            $resolution = $resolutions[$importId] ?? [
                'status' => 'missing',
                'resolved_file_id' => null,
                'resolved_export_index' => null,
                'source' => 'none',
                'confidence' => 'missing',
            ];
            $row = [
                'file_id' => $fileId,
                'import_index' => $importIndex,
                'required_package' => (string)$import['root_package'],
                'required_object_path' => (string)$import['full_path'],
                'resolved_file_id' => $resolution['resolved_file_id'] !== null
                    ? (int)$resolution['resolved_file_id']
                    : null,
                'resolved_export_index' => $resolution['resolved_export_index'] !== null
                    ? (int)$resolution['resolved_export_index']
                    : null,
                'status' => (string)$resolution['status'],
                'resolution_source' => (string)($resolution['source'] ?? 'none'),
                'resolution_confidence' => (string)($resolution['confidence'] ?? 'missing'),
            ];
            if (!$this->sameDependency($previous[$importIndex] ?? null, $row)) {
                $changed++;
            }
            $dependencies[] = $row;
        }

        if (count($dependencies) !== (int)$file['import_count']) {
            throw new RuntimeException('Compact dependency rebuild did not produce one row per Import.');
        }

        $snapshot['dependencies'] = $dependencies;
        $written = (new BlockedCompressedMetadataSnapshotWriter($this->db, $this->storageRoot))->write($snapshot);

        return array_merge($written, [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'imports_processed' => count($imports),
            'dependencies_changed' => $changed,
            'compact_native' => true,
        ]);
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private function sameDependency(?array $before, array $after): bool
    {
        if ($before === null) {
            return false;
        }
        foreach ([
            'required_package',
            'required_object_path',
            'resolved_file_id',
            'resolved_export_index',
            'status',
            'resolution_source',
            'resolution_confidence',
        ] as $column) {
            if (($before[$column] ?? null) !== ($after[$column] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
