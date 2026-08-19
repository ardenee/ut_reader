<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Re-resolves compact dependency rows for a file, optionally limited to changed package providers.
 * Why: Projection reconciliation must avoid rewriting complete compact metadata containers when dependency results are unchanged.
 * Role: Infrastructure metadata rebuilder used by dependency maintenance and projection reconciliation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

/** Re-resolves one file's Imports and rewrites its compact dependency section only when required. */
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
        return $this->rebuildInternal($fileId, null);
    }

    /**
     * Re-resolve only Imports that reference one of the supplied package names.
     *
     * @param list<string> $packageNames
     * @return array<string,mixed>
     */
    public function rebuildForPackages(int $fileId, array $packageNames): array
    {
        $packageKeys = [];
        foreach ($packageNames as $packageName) {
            $name = trim((string)$packageName);
            if ($name !== '') {
                $packageKeys[mb_strtolower($name, 'UTF-8')] = true;
            }
        }
        return $this->rebuildInternal($fileId, $packageKeys);
    }

    /**
     * @param array<string,bool>|null $packageKeys null means rebuild every Import.
     * @return array<string,mixed>
     */
    private function rebuildInternal(int $fileId, ?array $packageKeys): array
    {
        $loader = new BlockedCompressedMetadataSnapshotLoader($this->db, $this->storageRoot);
        $dependencySnapshot = $loader->loadDependencySnapshot($fileId);
        $file = (array)$dependencySnapshot['file'];
        $imports = array_values((array)$dependencySnapshot['imports']);
        $previous = [];
        foreach ((array)$dependencySnapshot['dependencies'] as $row) {
            if (is_array($row)) {
                $previous[(int)$row['import_index']] = $row;
            }
        }

        // A targeted refresh assumes the existing compact dependency section is complete.
        // If an older/incomplete snapshot is encountered, fall back to the authoritative
        // full rebuild so reconciliation also repairs the malformed projection.
        if ($packageKeys !== null && count($previous) !== count($imports)) {
            return $this->rebuildInternal($fileId, null);
        }

        $importsToResolve = [];
        foreach ($imports as $import) {
            if (!is_array($import)) {
                throw new RuntimeException('Compact Import snapshot contains a non-row value.');
            }
            if ($packageKeys === null) {
                $importsToResolve[] = $import;
                continue;
            }
            $rootPackage = mb_strtolower(trim((string)($import['root_package'] ?? '')), 'UTF-8');
            if ($rootPackage !== '' && isset($packageKeys[$rootPackage])) {
                $importsToResolve[] = $import;
            }
        }

        $resolutions = $importsToResolve === []
            ? []
            : PdoDependencyResolver::resolve(
                $this->db,
                (int)$file['game_id'],
                $fileId,
                $importsToResolve
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

            $targeted = $packageKeys === null
                || isset($packageKeys[mb_strtolower(trim((string)($import['root_package'] ?? '')), 'UTF-8')]);
            if (!$targeted) {
                $existing = $previous[$importIndex] ?? null;
                if (!is_array($existing)) {
                    return $this->rebuildInternal($fileId, null);
                }
                $dependencies[] = $existing;
                continue;
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

        $baseResult = [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'imports_processed' => count($importsToResolve),
            'imports_total' => count($imports),
            'dependencies_changed' => $changed,
            'compact_native' => true,
            'dependency_count' => count($dependencies),
        ];

        // This is the critical reconciliation fast path. Resolving an affected
        // provider often confirms the existing dependency rows are already right;
        // do not inflate names/exports or rebuild/recompress the container.
        if ($changed === 0) {
            return array_merge($baseResult, [
                'sql_batches' => 0,
                'container_rewritten' => false,
            ]);
        }

        // The writer owns complete-container publication. Only pay to load names
        // and exports after the dependency-only comparison proved that bytes must
        // change.
        $snapshot = $loader->load($fileId);
        $snapshot['dependencies'] = $dependencies;
        $written = (new BlockedCompressedMetadataSnapshotWriter($this->db, $this->storageRoot))->write($snapshot);
        return array_merge($written, $baseResult);
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
