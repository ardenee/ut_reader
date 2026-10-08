<?php
/** Source-exact UE1 import verification for the audited Unreal and UT99 revisions. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeProviderSnapshotLoader;

final class PdoUe1VerifyImportProjectionResolver
{
    public const PROFILE_UNREAL_V120 = 'ue1-unreal-v120';
    public const PROFILE_UT99_V1400 = 'ue1-ut99-v1400';

    private const RF_PUBLIC = 0x00000004;

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @return array<int,array<string,mixed>>
     */
    public static function resolveProviderOutcome(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        string $profile,
        ?int $consumerVersion = null
    ): array {
        if ($providerFileId < 1) {
            return [];
        }
        if (!function_exists('catalog_config')) {
            throw new RuntimeException('Catalog configuration is required for authoritative UE1 VerifyImport resolution.');
        }
        $config = \catalog_config();
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for authoritative UE1 VerifyImport resolution.');
        }
        $snapshot = (new Uedb5RuntimeProviderSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $providerVersion = null;
        try {
            $statement = $db->prepare('SELECT package_version FROM ue_files WHERE id=? LIMIT 1');
            $statement->execute([$providerFileId]);
            $value = $statement->fetchColumn();
            if ($value !== false && $value !== null) {
                $providerVersion = (int)$value;
            }
        } catch (\Throwable) {
            $providerVersion = null;
        }
        $file = (array)($snapshot['file'] ?? []);
        return self::resolveInMemoryOutcome(
            $profile,
            $consumerImports,
            array_values((array)($snapshot['imports'] ?? [])),
            array_values((array)($snapshot['exports'] ?? [])),
            (string)($file['package_name'] ?? ''),
            $consumerVersion,
            $providerVersion
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array<int,array<string,mixed>>
     */
    public static function resolveInMemoryOutcome(
        string $profile,
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        ?int $consumerVersion = null,
        ?int $providerVersion = null
    ): array {
        if (!in_array($profile, [self::PROFILE_UNREAL_V120, self::PROFILE_UT99_V1400], true)) {
            throw new RuntimeException('Unsupported UE1 VerifyImport source profile: ' . $profile);
        }

        $imports = self::indexRows($consumerImports, 'import_index');
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');
        $exports = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            [$classPackage, $className] = self::exportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName
            );
            $exports[] = [
                'export_index' => (int)$exportIndex,
                'object_name' => (string)($export['object_name'] ?? ''),
                'class_name' => $className,
                'class_package' => $classPackage,
                'outer_index' => (int)($export['outer_index'] ?? $export['package_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
            ];
        }
        usort($exports, static fn(array $a, array $b): int => $a['export_index'] <=> $b['export_index']);

        $results = [];
        $resolving = [];
        foreach (array_keys($imports) as $index) {
            self::resolveIndex(
                (int)$index,
                $profile,
                $imports,
                $exports,
                $consumerVersion,
                $providerVersion,
                $results,
                $resolving
            );
        }
        ksort($results, SORT_NUMERIC);
        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    private static function indexRows(array $rows, string $indexField): array
    {
        $indexed = [];
        foreach ($rows as $fallback => $row) {
            if (!is_array($row)) { continue; }
            $index = array_key_exists($indexField, $row) ? (int)$row[$indexField] : (int)$fallback;
            $indexed[$index] = $row;
        }
        return $indexed;
    }

    /** @return array{0:string,1:string} */
    private static function exportClassIdentity(
        array $export,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) { return ['', '']; }
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? $classImport['package_index'] ?? 0);
            if ($classOuter >= 0) { return ['', $className]; }
            $classPackageImport = $providerImports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport) ? (string)($classPackageImport['object_name'] ?? '') : '',
                $className,
            ];
        }
        if ($classIndex > 0) {
            $classExport = $providerExports[$classIndex - 1] ?? null;
            return [
                $providerPackageName,
                is_array($classExport) ? (string)($classExport['object_name'] ?? '') : '',
            ];
        }
        return ['Core', 'Class'];
    }

    /** @return array<string,mixed> */
    private static function resolveIndex(
        int $index,
        string $profile,
        array $imports,
        array $exports,
        ?int $consumerVersion,
        ?int $providerVersion,
        array &$results,
        array &$resolving
    ): array {
        if (isset($results[$index])) { return $results[$index]; }
        if (isset($resolving[$index]) || !isset($imports[$index])) {
            return $results[$index] = self::result('invalid', 'recursive_or_missing_import', false, null);
        }
        $resolving[$index] = true;
        $import = $imports[$index];

        if (self::isNameNoneImport($import)) {
            unset($resolving[$index]);
            return $results[$index] = self::result('ignored', 'name_none', false, null);
        }

        $outer = (int)($import['outer_index'] ?? $import['package_index'] ?? 0);
        $objectPackage = (string)($import['object_package'] ?? $import['object_package_text'] ?? '');
        $pre50Unreal = $profile === self::PROFILE_UNREAL_V120
            && $consumerVersion !== null
            && $consumerVersion < 50;

        $expectedOuter = null;
        $sourceLinker = true;
        $isPackageLinkerImport = false;

        if ($pre50Unreal) {
            if ($objectPackage === '' || self::isNone($objectPackage)) {
                unset($resolving[$index]);
                return $results[$index] = self::result('ignored', 'pre50_object_package_none', false, null);
            }
        } elseif ($outer === 0) {
            if (!self::same((string)($import['class_package'] ?? ''), 'Core')
                || !self::same((string)($import['class_name'] ?? ''), 'Package')) {
                unset($resolving[$index]);
                return $results[$index] = self::result('invalid', 'invalid_root_package_import', false, null);
            }
            $isPackageLinkerImport = true;
        } elseif ($outer < 0) {
            $parentIndex = -$outer - 1;
            $parent = self::resolveIndex(
                $parentIndex,
                $profile,
                $imports,
                $exports,
                $consumerVersion,
                $providerVersion,
                $results,
                $resolving
            );
            if (empty($parent['source_linker'])) {
                unset($resolving[$index]);
                return $results[$index] = self::result(
                    'invalid',
                    'parent_source_linker_unavailable',
                    false,
                    null,
                    ['parent_import_index' => $parentIndex]
                );
            }
            $parentSourceIndex = array_key_exists('source_index', $parent) && $parent['source_index'] !== null
                ? (int)$parent['source_index']
                : null;
            $expectedOuter = $parentSourceIndex === null ? 0 : $parentSourceIndex + 1;
        } else {
            unset($resolving[$index]);
            return $results[$index] = self::result('invalid', 'positive_import_outer', false, null);
        }

        $match = self::findDirectMatch(
            $profile,
            $import,
            $exports,
            $expectedOuter,
            $consumerVersion,
            $providerVersion
        );
        $ut99Mesh = $profile === self::PROFILE_UT99_V1400
            && self::same((string)($import['class_name'] ?? ''), 'Mesh');
        if ($match['status'] === 'private_export') {
            unset($resolving[$index]);
            return $results[$index] = $match + ['source_linker' => $sourceLinker];
        }
        if ($match['status'] === 'resolved' && !$ut99Mesh) {
            unset($resolving[$index]);
            return $results[$index] = $match + ['source_linker' => $sourceLinker];
        }
        $meshMatch = $match['status'] === 'resolved' ? $match : null;

        if ($profile === self::PROFILE_UNREAL_V120 && self::usesUnrealLegacyOuterFallback($import)) {
            $legacy = self::findUnrealLegacyNoOuterMatch($import, $exports);
            if ($legacy !== null) {
                unset($resolving[$index]);
                return $results[$index] = self::result(
                    'resolved',
                    'unreal_v120_legacy_no_outer_match',
                    true,
                    (int)$legacy['export_index']
                );
            }
        }

        if ($ut99Mesh) {
            $lodImport = $import;
            $lodImport['class_name'] = 'LodMesh';
            $lod = self::findDirectMatch(
                $profile,
                $lodImport,
                $exports,
                $expectedOuter,
                $consumerVersion,
                $providerVersion
            );
            if ($lod['status'] === 'resolved' || $lod['status'] === 'private_export') {
                unset($resolving[$index]);
                $lod['reason'] = $lod['status'] === 'resolved'
                    ? 'ut99_mesh_to_lodmesh'
                    : 'private_export';
                return $results[$index] = $lod + ['source_linker' => $sourceLinker];
            }
            if (is_array($meshMatch)) {
                unset($resolving[$index]);
                $meshMatch['reason'] = 'ut99_mesh_exact_retained_after_lodmesh_rehack';
                return $results[$index] = $meshMatch + ['source_linker' => $sourceLinker];
            }
        }

        unset($resolving[$index]);
        if ($isPackageLinkerImport) {
            return $results[$index] = self::result('package_linker', 'top_level_package_linker', true, null);
        }

        return $results[$index] = self::result(
            'runtime_only',
            $profile === self::PROFILE_UNREAL_V120
                ? 'unreal_v120_runtime_transient_or_safe_replace'
                : 'ut99_runtime_native_transient_or_safe_replace',
            true,
            null
        );
    }

    /** @return array<string,mixed> */
    private static function findDirectMatch(
        string $profile,
        array $import,
        array $exports,
        ?int $expectedOuter,
        ?int $consumerVersion,
        ?int $providerVersion
    ): array {
        $object = (string)($import['object_name'] ?? '');
        $class = (string)($import['class_name'] ?? '');
        $classPackage = (string)($import['class_package'] ?? '');
        $ordered = $exports;
        if ($profile === self::PROFILE_UT99_V1400) {
            usort($ordered, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }

        $enforceOuter = true;
        if ($profile === self::PROFILE_UNREAL_V120) {
            $enforceOuter = ($consumerVersion ?? 0) >= 50 && ($providerVersion ?? 0) >= 50;
        }

        foreach ($ordered as $candidate) {
            if (!self::same((string)$candidate['object_name'], $object)
                || !self::same((string)$candidate['class_name'], $class)) {
                continue;
            }
            $candidateClassPackage = (string)$candidate['class_package'];
            $classPackageMatches = self::same($candidateClassPackage, $classPackage);
            if (!$classPackageMatches && $profile === self::PROFILE_UT99_V1400) {
                // UT99 v1.400 ClassHack: an UnrealI import may bind an export
                // whose class package is UnrealShare. The inverse is not allowed.
                $classPackageMatches = self::same($classPackage, 'UnrealI')
                    && self::same($candidateClassPackage, 'UnrealShare');
            }
            if (!$classPackageMatches) { continue; }

            if ($enforceOuter && $expectedOuter !== null) {
                $candidateOuter = (int)($candidate['outer_index'] ?? 0);
                if ($candidateOuter !== $expectedOuter && $candidateOuter !== 0) {
                    continue;
                }
            }

            if ((((int)$candidate['object_flags']) & self::RF_PUBLIC) === 0) {
                return self::result(
                    'private_export',
                    'private_export',
                    true,
                    null,
                    ['candidate_export_index' => (int)$candidate['export_index']]
                );
            }
            return self::result(
                'resolved',
                ($profile === self::PROFILE_UT99_V1400
                    && !self::same($candidateClassPackage, $classPackage))
                    ? 'ut99_unreali_unrealshare_class_package'
                    : 'exact_verify_import_match',
                true,
                (int)$candidate['export_index']
            );
        }
        return self::result('no_direct_match', 'no_direct_export_match', true, null);
    }

    private static function usesUnrealLegacyOuterFallback(array $import): bool
    {
        static $classes = [
            'texture' => true,
            'sound' => true,
            'firetexture' => true,
            'icetexture' => true,
            'watertexture' => true,
            'wavetexture' => true,
            'wettexture' => true,
        ];
        return isset($classes[self::key((string)($import['class_name'] ?? ''))]);
    }

    /** @return array<string,mixed>|null */
    private static function findUnrealLegacyNoOuterMatch(array $import, array $exports): ?array
    {
        foreach ($exports as $candidate) {
            if (self::same((string)$candidate['object_name'], (string)($import['object_name'] ?? ''))
                && self::same((string)$candidate['class_name'], (string)($import['class_name'] ?? ''))
                && self::same((string)$candidate['class_package'], (string)($import['class_package'] ?? ''))) {
                // Unreal v1.200's old-version retry records SourceIndex directly;
                // it does not re-run the RF_Public or parent check in this branch.
                return $candidate;
            }
        }
        return null;
    }

    private static function isNameNoneImport(array $import): bool
    {
        foreach (['class_package', 'class_name', 'object_name'] as $field) {
            if (array_key_exists($field . '_is_none', $import) && !empty($import[$field . '_is_none'])) {
                return true;
            }
            if (self::isNone((string)($import[$field] ?? ''))) { return true; }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function result(
        string $status,
        string $reason,
        bool $sourceLinker,
        ?int $sourceIndex,
        array $detail = []
    ): array {
        return $detail + [
            'status' => $status,
            'reason' => $reason,
            'source_linker' => $sourceLinker,
            'source_index' => $sourceIndex,
            'export_index' => $sourceIndex,
        ];
    }

    private static function isNone(string $value): bool
    {
        return self::same($value, 'None');
    }

    private static function same(string $a, string $b): bool
    {
        return self::key($a) === self::key($b);
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
