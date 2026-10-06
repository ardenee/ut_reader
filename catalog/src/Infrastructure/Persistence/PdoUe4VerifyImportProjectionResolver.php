<?php
/**
 * Resolves UT4 clean-master UE4 Imports against one physical provider using
 * the deterministic, file-backed portion of FLinkerLoad::VerifyImportInner.
 *
 * Runtime-only redirects, editor state and already-loaded native/transient
 * object behavior are deliberately excluded from static catalog resolution.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

final class PdoUe4VerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000001;
    private const TOP_LEVEL_PACKAGE = -2147483647;
    private const PRIVATE_FAILURE = -2147483648;

    public const PROFILE_UT4_CLEAN_MASTER = 'ue4-ut4-clean-master-v511';

    /** @param list<array<string,mixed>> $consumerImports @return array<int,int> */
    public static function resolveProvider(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        return self::resolveProviderOutcome(
            $db,
            $providerFileId,
            $consumerImports,
            $consumerExports,
            $consumerGraphImports
        )['matches'];
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>,redirector_ancestry:array<int,int>,source_outcomes:array<int,array<string,mixed>>}
     */
    public static function resolveProviderOutcome(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['matches' => [], 'redirectors' => [], 'redirector_ancestry' => [], 'source_outcomes' => []];
        }
        if (!function_exists('catalog_config')) {
            throw new RuntimeException('Catalog configuration is required for authoritative UE4 VerifyImport resolution.');
        }
        $config = \catalog_config();
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for authoritative UE4 VerifyImport resolution.');
        }

        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $file = (array)($snapshot['file'] ?? []);
        return self::resolveInMemoryOutcome(
            $consumerImports,
            (array)($snapshot['imports'] ?? []),
            (array)($snapshot['exports'] ?? []),
            (string)($file['package_name'] ?? ''),
            $consumerExports,
            $consumerGraphImports
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array<int,int>
     */
    public static function resolveInMemory(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        return self::resolveInMemoryOutcome(
            $consumerImports,
            $providerImports,
            $providerExports,
            $providerPackageName,
            $consumerExports,
            $consumerGraphImports
        )['matches'];
    }

    /**
     * Deterministic table-level part of VerifyImport + VerifyImportInner.
     * A matching ObjectRedirector is reported separately because UE4 must
     * preload its UObject payload and validate DestinationObject before the
     * original Import can be considered resolved.
     * Descendants of a redirector-resolved outer are reported in redirector_ancestry because
     * Epic rewrites the outer SourceLinker/SourceIndex from DestinationObject before continuing.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>,redirector_ancestry:array<int,int>,source_outcomes:array<int,array<string,mixed>>}
     */
    public static function resolveInMemoryOutcome(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        $imports = self::indexRows($consumerImports, 'import_index');
        $graphImports = self::indexRows(
            $consumerGraphImports !== [] ? $consumerGraphImports : $consumerImports,
            'import_index'
        );
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');
        $consumerExportsByIndex = self::indexRows($consumerExports, 'export_index');

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectName = (string)($export['object_name'] ?? '');
            if ($objectName === '') {
                continue;
            }
            [$classPackage, $className] = self::exportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName
            );
            if ($classPackage === '' || $className === '') {
                continue;
            }
            $key = self::candidateKey($objectName, $className);
            $candidates[$key][] = [
                'export_index' => (int)$exportIndex,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'object_name' => $objectName,
                'class_package' => $classPackage,
                'class_name' => $className,
            ];
        }
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);

        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImportIndex(
                (int)$importIndex,
                $imports,
                $consumerExportsByIndex,
                $graphImports,
                $candidates,
                $resolved,
                $visiting
            );
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if (is_int($exportIndex) && $exportIndex >= 0) {
                $matches[(int)$importIndex] = $exportIndex;
            }
        }

        $redirectors = [];
        foreach ($imports as $importIndex => $import) {
            $importIndex = (int)$importIndex;
            if (isset($matches[$importIndex])) {
                continue;
            }
            $objectName = (string)($import['object_name'] ?? '');
            $className = (string)($import['class_name'] ?? '');
            $classPackage = (string)($import['class_package'] ?? '');
            if ($objectName === '' || $className === '' || $classPackage === ''
                || self::hasNameNone($import)
                || self::key($objectName) === self::key('ObjectRedirector')) {
                continue;
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex >= 0) {
                continue;
            }
            $parentIndex = -$outerIndex - 1;
            $parentSource = $resolved[$parentIndex] ?? null;
            if (!is_int($parentSource) || $parentSource === self::PRIVATE_FAILURE) {
                continue;
            }
            $expectedOuter = $parentSource === self::TOP_LEVEL_PACKAGE ? 0 : $parentSource + 1;
            $redirector = self::findCandidate(
                $candidates, $objectName, 'ObjectRedirector', '/Script/CoreUObject', $expectedOuter, false
            );
            if (is_int($redirector) && $redirector >= 0) {
                $redirectors[$importIndex] = $redirector;
            }
        }

        $redirectorAncestry = [];
        if ($redirectors !== []) {
            foreach (array_keys($imports) as $importIndex) {
                $importIndex = (int)$importIndex;
                if (isset($matches[$importIndex]) || isset($redirectors[$importIndex])) continue;
                $current = $importIndex;
                $seen = [];
                while (isset($imports[$current]) && !isset($seen[$current])) {
                    $seen[$current] = true;
                    $outerIndex = (int)($imports[$current]['outer_index'] ?? 0);
                    if ($outerIndex >= 0) break;
                    $parentIndex = -$outerIndex - 1;
                    if (isset($redirectors[$parentIndex])) {
                        $redirectorAncestry[$importIndex] = $parentIndex;
                        break;
                    }
                    $current = $parentIndex;
                }
            }
        }

        $sourceOutcomes = self::sourceOutcomes(
            $imports,
            $graphImports,
            $consumerExportsByIndex,
            $providerExportsByIndex,
            $resolved,
            $matches,
            $redirectors,
            $redirectorAncestry
        );

        return [
            'matches' => $matches,
            'redirectors' => $redirectors,
            'redirector_ancestry' => $redirectorAncestry,
            'source_outcomes' => $sourceOutcomes,
        ];
    }

    /**
     * Source-shaped 4.27.2 outcomes layered over the deterministic table matcher.
     * Only a public file-backed export is statically resolved. Compile/editor,
     * SafeReplace, native/transient, memory-only and redirector payload branches
     * remain unresolved because their required runtime state is not package metadata.
     *
     * @param array<int,array<string,mixed>> $imports
     * @param array<int,array<string,mixed>> $graphImports
     * @param array<int,array<string,mixed>> $consumerExports
     * @param array<int,array<string,mixed>> $providerExports
     * @param array<int,int|null> $resolved
     * @param array<int,int> $matches
     * @param array<int,int> $redirectors
     * @param array<int,int> $redirectorAncestry
     * @return array<int,array<string,mixed>>
     */
    private static function sourceOutcomes(
        array $imports,
        array $graphImports,
        array $consumerExports,
        array $providerExports,
        array $resolved,
        array $matches,
        array $redirectors,
        array $redirectorAncestry
    ): array {
        $outcomes = [];
        foreach ($imports as $importIndex => $import) {
            $importIndex = (int)$importIndex;
            if (self::hasNameNone($import)) {
                $outcomes[$importIndex] = self::sourceOutcome('ignored', 'name_none');
                continue;
            }
            if (isset($redirectors[$importIndex])) {
                $outcomes[$importIndex] = self::sourceOutcome(
                    'unresolved', 'object_redirector_target_unavailable',
                    ['redirector_index'=>(int)$redirectors[$importIndex]]
                );
                continue;
            }
            if (isset($redirectorAncestry[$importIndex])) {
                $outcomes[$importIndex] = self::sourceOutcome(
                    'unresolved', 'object_redirector_ancestor_target_unavailable',
                    ['blocked_by_import_index'=>(int)$redirectorAncestry[$importIndex]]
                );
                continue;
            }
            if (isset($matches[$importIndex])) {
                $exportIndex = (int)$matches[$importIndex];
                $flags = (int)($providerExports[$exportIndex]['object_flags'] ?? 0);
                if (($flags & self::RF_PUBLIC) === 0) {
                    $outcomes[$importIndex] = self::sourceOutcome(
                        'runtime_only', 'private_export_with_editor_containment_context',
                        ['candidate_export_index'=>$exportIndex]
                    );
                } else {
                    $outcomes[$importIndex] = self::sourceOutcome(
                        'resolved', 'exact_verify_import_match', ['export_index'=>$exportIndex]
                    );
                }
                continue;
            }

            $objectName = (string)($import['object_name'] ?? '');
            $className = (string)($import['class_name'] ?? '');
            $classPackage = (string)($import['class_package'] ?? '');
            if ($objectName === '' || $className === '' || $classPackage === '') {
                $outcomes[$importIndex] = self::sourceOutcome('invalid', 'incomplete_import_identity');
                continue;
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex === 0) {
                $outcomes[$importIndex] = self::key($className) === self::key('Package')
                    ? self::sourceOutcome('package_linker', 'top_level_package_linker')
                    : self::sourceOutcome('invalid', 'null_outer_non_package_import');
                continue;
            }
            if ($outerIndex > 0) {
                $outcomes[$importIndex] = self::sourceOutcome(
                    'invalid', 'export_outer_source_assert_boundary'
                );
                continue;
            }

            $state = $resolved[$importIndex] ?? null;
            if ($state === self::PRIVATE_FAILURE) {
                $hardReference = self::privateSafeReplaceBlocked($importIndex, $graphImports, $consumerExports);
                $outcomes[$importIndex] = $hardReference
                    ? self::sourceOutcome('private_export', 'private_export_rejected')
                    : self::sourceOutcome('runtime_only', 'private_export_editor_safe_replace_context');
                continue;
            }

            $parentIndex = -$outerIndex - 1;
            $parentState = $resolved[$parentIndex] ?? null;
            if ($parentState === null || $parentState === self::PRIVATE_FAILURE) {
                $outcomes[$importIndex] = self::sourceOutcome(
                    'runtime_only', 'parent_source_linker_or_runtime_context_unavailable',
                    ['parent_import_index'=>$parentIndex]
                );
                continue;
            }

            $outcomes[$importIndex] = self::sourceOutcome(
                'runtime_only', 'runtime_native_transient_findif_fail_or_missing_class_context'
            );
        }
        ksort($outcomes, SORT_NUMERIC);
        return $outcomes;
    }

    /** @param array<int,array<string,mixed>> $imports @param array<int,array<string,mixed>> $exports */
    private static function privateSafeReplaceBlocked(int $importIndex, array $imports, array $exports): bool
    {
        $foundIndex = -($importIndex + 1);
        foreach ($exports as $export) {
            if (!is_array($export)) { continue; }
            foreach (['super_index','class_index','outer_index'] as $field) {
                if (array_key_exists($field, $export) && (int)$export[$field] === $foundIndex) {
                    return true;
                }
            }
        }
        foreach ($imports as $otherIndex => $otherImport) {
            if ((int)$otherIndex === $importIndex || !is_array($otherImport)) { continue; }
            if ((int)($otherImport['outer_index'] ?? 0) === $foundIndex) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private static function sourceOutcome(string $status, string $reason, array $detail=[]): array
    {
        return ['status'=>$status,'reason'=>$reason] + $detail;
    }

    /** @param array<string,mixed> $import */
    private static function hasNameNone(array $import): bool
    {
        foreach (['class_package','class_name','object_name'] as $field) {
            if (self::key((string)($import[$field] ?? '')) === self::key('None')) {
                return true;
            }
        }
        return false;
    }

    /**
     * UE4 FLinker::GetExportClassName/GetExportClassPackage equivalent using the
     * parsed signed ClassIndex graph. Current v4 metadata retains the complete
     * provider import/export graph required for pre-519 packages.
     *
     * @param array<int,array<string,mixed>> $providerImports
     * @param array<int,array<string,mixed>> $providerExports
     * @return array{0:string,1:string}
     */
    private static function exportClassIdentity(
        array $export,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex === 0) {
            return ['/Script/CoreUObject', 'Class'];
        }

        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter === 0) {
                return ['', $className];
            }
            $classPackageResource = $classOuter < 0
                ? ($providerImports[-$classOuter - 1] ?? null)
                : ($providerExports[$classOuter - 1] ?? null);
            return [
                is_array($classPackageResource)
                    ? (string)($classPackageResource['object_name'] ?? '')
                    : '',
                $className,
            ];
        }

        $classExport = $providerExports[$classIndex - 1] ?? null;
        if (!is_array($classExport)) {
            return ['', ''];
        }
        return [$providerPackageName, (string)($classExport['object_name'] ?? '')];
    }


    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImportIndex(
        int $index,
        array $imports,
        array $consumerExports,
        array $consumerGraphImports,
        array $candidates,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($index, $resolved)) {
            return $resolved[$index];
        }
        if (isset($visiting[$index]) || !isset($imports[$index])) {
            return $resolved[$index] = null;
        }
        $visiting[$index] = true;
        $import = $imports[$index];

        $objectName = (string)($import['object_name'] ?? '');
        $className = (string)($import['class_name'] ?? '');
        $classPackage = (string)($import['class_package'] ?? '');
        if (self::hasNameNone($import)) {
            unset($visiting[$index]);
            return $resolved[$index] = null;
        }
        if ($objectName === '' || $className === '' || $classPackage === '') {
            unset($visiting[$index]);
            return $resolved[$index] = null;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            unset($visiting[$index]);
            return $resolved[$index] = self::key($className) === 'package'
                ? self::TOP_LEVEL_PACKAGE
                : null;
        }
        if ($outerIndex > 0) {
            // UT4 clean-master asserts that every non-null Import outer is
            // another Import. Export-outers are outside this source profile.
            unset($visiting[$index]);
            return $resolved[$index] = null;
        }

        $parentIndex = -$outerIndex - 1;
        $parentSource = self::resolveImportIndex(
            $parentIndex,
            $imports,
            $consumerExports,
            $consumerGraphImports,
            $candidates,
            $resolved,
            $visiting
        );
        if ($parentSource === null || $parentSource === self::PRIVATE_FAILURE) {
            unset($visiting[$index]);
            return $resolved[$index] = $parentSource === self::PRIVATE_FAILURE ? self::PRIVATE_FAILURE : null;
        }
        $expectedOuter = $parentSource === self::TOP_LEVEL_PACKAGE ? 0 : $parentSource + 1;

        $matched = self::findCandidate(
            $candidates, $objectName, $className, $classPackage, $expectedOuter, false
        );
        unset($visiting[$index]);
        return $resolved[$index] = $matched;
    }

    /** @param array<string,list<array<string,mixed>>> $candidates */
    private static function findCandidate(
        array $candidates,
        string $objectName,
        string $className,
        string $classPackage,
        int $expectedOuter,
        bool $privateImportAllowed
    ): ?int {
        $rows = $candidates[self::candidateKey($objectName, $className)] ?? [];
        if ($rows === []) {
            return null;
        }

        // UE4's package-name transition rule first determines whether an exact
        // full ClassPackage match exists. Only when no such candidate exists may
        // the short package name be used. Outer filtering happens afterwards.
        $hasFullPackageMatch = false;
        foreach ($rows as $candidate) {
            if (self::key((string)$candidate['class_package']) === self::key($classPackage)) {
                $hasFullPackageMatch = true;
                break;
            }
        }

        $expectedPackageKey = $hasFullPackageMatch
            ? self::key($classPackage)
            : self::key(self::shortPackageName($classPackage));

        foreach ($rows as $candidate) {
            $candidatePackage = $hasFullPackageMatch
                ? (string)$candidate['class_package']
                : self::shortPackageName((string)$candidate['class_package']);
            if (self::key($candidatePackage) !== $expectedPackageKey) {
                continue;
            }
            if ((int)$candidate['outer_index'] !== $expectedOuter) {
                continue;
            }
            if ((((int)$candidate['object_flags']) & self::RF_PUBLIC) === 0 && !$privateImportAllowed) {
                return self::PRIVATE_FAILURE;
            }
            return (int)$candidate['export_index'];
        }
        return null;
    }

    /**
     * Read-only explanation of deterministic table-level VerifyImport failures.
     * This does not participate in dependency resolution; it explains the same
     * serialized object/class/class-package/outer/public checks used above.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>,redirector_ancestry:array<int,int>,rejections:array<int,array<string,mixed>>}
     */
    public static function diagnoseProviderOutcome(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['matches'=>[], 'redirectors'=>[], 'redirector_ancestry'=>[], 'rejections'=>[]];
        }
        if (!function_exists('catalog_config')) {
            throw new RuntimeException('Catalog configuration is required for authoritative UE4 VerifyImport diagnosis.');
        }
        $config = \catalog_config();
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for authoritative UE4 VerifyImport diagnosis.');
        }
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $file = (array)($snapshot['file'] ?? []);
        return self::diagnoseInMemoryOutcome(
            $consumerImports,
            (array)($snapshot['imports'] ?? []),
            (array)($snapshot['exports'] ?? []),
            (string)($file['package_name'] ?? ''),
            $consumerExports,
            $consumerGraphImports
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>,redirector_ancestry:array<int,int>,rejections:array<int,array<string,mixed>>}
     */
    public static function diagnoseInMemoryOutcome(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        $outcome = self::resolveInMemoryOutcome(
            $consumerImports, $providerImports, $providerExports, $providerPackageName,
            $consumerExports, $consumerGraphImports
        );
        $imports = self::indexRows($consumerImports, 'import_index');
        $graphImports = self::indexRows(
            $consumerGraphImports !== [] ? $consumerGraphImports : $consumerImports,
            'import_index'
        );
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');
        $consumerExportsByIndex = self::indexRows($consumerExports, 'export_index');

        $providerRows = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectName = (string)($export['object_name'] ?? '');
            if ($objectName === '') continue;
            [$classPackage, $className] = self::exportClassIdentity(
                $export, $providerImportsByIndex, $providerExportsByIndex, $providerPackageName
            );
            $providerRows[] = [
                'export_index'=>(int)$exportIndex,
                'outer_index'=>(int)($export['outer_index'] ?? 0),
                'object_flags'=>(int)($export['object_flags'] ?? 0),
                'object_name'=>$objectName,
                'class_package'=>$classPackage,
                'class_name'=>$className,
            ];
        }
        usort($providerRows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);

        $rejections = [];
        foreach ($imports as $importIndex => $import) {
            $importIndex = (int)$importIndex;
            if (isset($outcome['matches'][$importIndex])) continue;
            $base = ['import_index'=>$importIndex];
            if (isset($outcome['redirectors'][$importIndex])) {
                $rejections[$importIndex] = $base + ['reason'=>'object_redirector_target_unavailable'];
                continue;
            }
            if (isset($outcome['redirector_ancestry'][$importIndex])) {
                $rejections[$importIndex] = $base + [
                    'reason'=>'object_redirector_ancestor_target_unavailable',
                    'blocked_by_import_index'=>(int)$outcome['redirector_ancestry'][$importIndex],
                ];
                continue;
            }
            $objectName = (string)($import['object_name'] ?? '');
            $className = (string)($import['class_name'] ?? '');
            $classPackage = (string)($import['class_package'] ?? '');
            if ($objectName === '' || $className === '' || $classPackage === '') {
                $rejections[$importIndex] = $base + ['reason'=>'incomplete_import_identity'];
                continue;
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex > 0) {
                $rejections[$importIndex] = $base + ['reason'=>'v4_package_context_unavailable'];
                continue;
            }
            if ($outerIndex === 0) {
                if (self::key($className) === 'package') continue;
                $rejections[$importIndex] = $base + ['reason'=>'null_outer_non_package_import'];
                continue;
            }
            $parentIndex = -$outerIndex - 1;
            $parent = $imports[$parentIndex] ?? null;
            if (!is_array($parent)) {
                $rejections[$importIndex] = $base + ['reason'=>'outer_import_missing_from_consumer_graph','blocked_by_import_index'=>$parentIndex];
                continue;
            }
            $parentOuter = (int)($parent['outer_index'] ?? 0);
            $parentClass = self::key((string)($parent['class_name'] ?? ''));
            if ($parentOuter === 0 && $parentClass === 'package') {
                $expectedOuter = 0;
            } elseif (isset($outcome['matches'][$parentIndex])) {
                $expectedOuter = (int)$outcome['matches'][$parentIndex] + 1;
            } else {
                $rejections[$importIndex] = $base + ['reason'=>'outer_import_unresolved','blocked_by_import_index'=>$parentIndex];
                continue;
            }

            $objectRows = array_values(array_filter($providerRows, static fn(array $row): bool =>
                self::key((string)$row['object_name']) === self::key($objectName)
            ));
            if ($objectRows === []) {
                $rejections[$importIndex] = $base + ['reason'=>'object_name_not_found'];
                continue;
            }
            $classRows = array_values(array_filter($objectRows, static fn(array $row): bool =>
                self::key((string)$row['class_name']) === self::key($className)
            ));
            if ($classRows === []) {
                $rejections[$importIndex] = $base + ['reason'=>'class_name_mismatch'];
                continue;
            }
            $hasFullPackageMatch = false;
            foreach ($classRows as $row) {
                if (self::key((string)$row['class_package']) === self::key($classPackage)) {
                    $hasFullPackageMatch = true;
                    break;
                }
            }
            $expectedPackageKey = $hasFullPackageMatch
                ? self::key($classPackage)
                : self::key(self::shortPackageName($classPackage));
            $packageRows = array_values(array_filter($classRows, static function(array $row) use ($hasFullPackageMatch, $expectedPackageKey): bool {
                $candidatePackage = $hasFullPackageMatch
                    ? (string)$row['class_package']
                    : self::shortPackageName((string)$row['class_package']);
                return self::key($candidatePackage) === $expectedPackageKey;
            }));
            if ($packageRows === []) {
                $rejections[$importIndex] = $base + ['reason'=>'class_package_mismatch','class_package_mode'=>$hasFullPackageMatch ? 'full' : 'short_fallback'];
                continue;
            }
            $outerRows = array_values(array_filter($packageRows, static fn(array $row): bool =>
                (int)$row['outer_index'] === $expectedOuter
            ));
            if ($outerRows === []) {
                $candidateOuters = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['outer_index'], $packageRows)));
                sort($candidateOuters);
                $rejections[$importIndex] = $base + [
                    'reason'=>'outer_mismatch',
                    'expected_outer_index'=>$expectedOuter,
                    'candidate_outer_indexes'=>$candidateOuters,
                ];
                continue;
            }
            $candidate = $outerRows[0];
            if ((((int)$candidate['object_flags']) & self::RF_PUBLIC) === 0) {
                $hardReference = self::privateSafeReplaceBlocked(
                    $importIndex, $graphImports, $consumerExportsByIndex
                );
                $rejections[$importIndex] = $base + [
                    'reason'=>$hardReference
                        ? 'private_export_rejected'
                        : 'private_export_editor_safe_replace_context',
                    'candidate_export_index'=>(int)$candidate['export_index'],
                ];
                continue;
            }
            $rejections[$importIndex] = $base + [
                'reason'=>'unexpected_unmatched_candidate',
                'candidate_export_index'=>(int)$candidate['export_index'],
            ];
        }
        $outcome['rejections'] = $rejections;
        return $outcome;
    }

    private static function candidateKey(string $objectName, string $className): string
    {
        return self::key($objectName) . "\0" . self::key($className);
    }

    private static function shortPackageName(string $packageName): string
    {
        $packageName = (string)$packageName;
        if ($packageName === '') {
            return '';
        }
        $slash = strrpos($packageName, '/');
        $dot = strrpos($packageName, '.');
        $position = max($slash === false ? -1 : $slash, $dot === false ? -1 : $dot);
        return $position >= 0 ? substr($packageName, $position + 1) : $packageName;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function indexRows(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row[$field]) ? (int)$row[$field] : (int)$fallback;
            $indexed[$index] = $row;
        }
        return $indexed;
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::fnameKey($value);
    }
}
