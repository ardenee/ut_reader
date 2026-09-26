<?php
/**
 * Resolves UE1/UE2 Imports against the indexed projection of ULinkerLoad::VerifyImport identity.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

require_once dirname(__DIR__) . '/Metadata/CatalogUnrealIdentityHash.php';

final class PdoLegacyVerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const HASH_BATCH_SIZE = 400;
    private const PRIVATE_FAILURE = -2147483648;

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param array<string,string> $classRemaps normalized source name => replacement ObjectName
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveProviderVariants(PDO $db, int $providerFileId, array $consumerImports, array $classRemaps = []): array
    {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        $imports = [];
        $hashes = [];
        foreach ($consumerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
            $imports[$index] = $row;

            $objectName = trim((string)($row['object_name'] ?? ''));
            $className = trim((string)($row['class_name'] ?? ''));
            $classPackage = trim((string)($row['class_package'] ?? ''));
            if ($objectName === '' || $className === '' || $classPackage === '') {
                continue;
            }
            $hashes[bin2hex(self::identityHash($objectName, $className, $classPackage))] = true;
            $remappedObjectName = self::remappedObjectName($objectName, $classRemaps);
            if ($remappedObjectName !== null) {
                $hashes[bin2hex(self::identityHash($remappedObjectName, $className, $classPackage))] = true;
            }
            if (self::key($className) === 'mesh') {
                $hashes[bin2hex(self::identityHash($objectName, 'LodMesh', $classPackage))] = true;
                if ($remappedObjectName !== null) {
                    $hashes[bin2hex(self::identityHash($remappedObjectName, 'LodMesh', $classPackage))] = true;
                }
            }
        }
        if ($hashes === []) {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        $candidates = self::loadCandidates($db, $providerFileId, array_keys($hashes));
        $standard = self::resolveVariant($imports, $candidates, true, $classRemaps);
        $unreal2 = self::resolveVariant($imports, $candidates, false, $classRemaps);

        $unreal2Only = [];
        foreach ($unreal2 as $importIndex => $exportIndex) {
            if (!isset($standard[$importIndex])) {
                $unreal2Only[(int)$importIndex] = (int)$exportIndex;
            }
        }

        return [
            'standard' => $standard,
            'unreal2' => $unreal2,
            'unreal2_only' => $unreal2Only,
        ];
    }

    /**
     * In-memory equivalent used while a package is being published and its projection
     * is not yet visible in ue_legacy_export_identity_lookup.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @param array<string,string> $classRemaps normalized source name => replacement ObjectName
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveInMemoryVariants(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $classRemaps = []
    ): array {
        $imports = [];
        foreach ($consumerImports as $fallback => $row) {
            if (is_array($row)) {
                $imports[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
            }
        }

        $providerImportsByIndex = [];
        foreach ($providerImports as $fallback => $row) {
            if (is_array($row)) {
                $providerImportsByIndex[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
            }
        }
        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (is_array($row)) {
                $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            [$classPackage, $className] = self::exportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName
            );
            $objectName = trim((string)($export['object_name'] ?? ''));
            if ($objectName === '' || $classPackage === '' || $className === '') {
                continue;
            }
            $key = bin2hex(self::identityHash($objectName, $className, $classPackage));
            $candidates[$key][] = [
                'export_index' => (int)$exportIndex,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'object_name' => $objectName,
                'class_name' => $className,
                'class_package' => $classPackage,
            ];
        }
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);

        $standard = self::resolveVariant($imports, $candidates, true, $classRemaps);
        $unreal2 = self::resolveVariant($imports, $candidates, false, $classRemaps);
        $unreal2Only = [];
        foreach ($unreal2 as $importIndex => $exportIndex) {
            if (!isset($standard[$importIndex])) {
                $unreal2Only[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return ['standard' => $standard, 'unreal2' => $unreal2, 'unreal2_only' => $unreal2Only];
    }

    /**
     * @param array<string,mixed> $export
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
        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = trim((string)($classImport['object_name'] ?? ''));
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) {
                return ['', $className];
            }
            $classPackageImport = $providerImports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport) ? trim((string)($classPackageImport['object_name'] ?? '')) : '',
                $className,
            ];
        }
        if ($classIndex > 0) {
            $classExport = $providerExports[$classIndex - 1] ?? null;
            return [
                trim($providerPackageName),
                is_array($classExport) ? trim((string)($classExport['object_name'] ?? '')) : '',
            ];
        }
        return ['Core', 'Class'];
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array{export_index:int,outer_index:int,object_flags:int,object_name:string,class_name:string,class_package:string}>> $candidates
     * @param array<string,string> $classRemaps
     * @return array<int,int>
     */
    private static function resolveVariant(array $imports, array $candidates, bool $requirePublic, array $classRemaps): array
    {
        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImport(
                (int)$importIndex,
                $imports,
                $candidates,
                $requirePublic,
                $classRemaps,
                $resolved,
                $visiting
            );
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::PRIVATE_FAILURE) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array{export_index:int,outer_index:int,object_flags:int}>> $candidates
     * @param array<string,string> $classRemaps
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $imports,
        array $candidates,
        bool $requirePublic,
        array $classRemaps,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($importIndex, $resolved)) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = null;
        }

        $import = $imports[$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = null;
        }

        $objectName = trim((string)($import['object_name'] ?? ''));
        $className = trim((string)($import['class_name'] ?? ''));
        $classPackage = trim((string)($import['class_package'] ?? ''));
        if ($objectName === '' || $className === '' || $classPackage === '') {
            return $resolved[$importIndex] = null;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            // Core.Package: VerifyImport loads the package linker but has no SourceIndex.
            return $resolved[$importIndex] = null;
        }
        if ($outerIndex > 0) {
            return $resolved[$importIndex] = null;
        }

        $visiting[$importIndex] = true;
        $parentImportIndex = -$outerIndex - 1;
        $parentSourceIndex = self::resolveImport(
            $parentImportIndex,
            $imports,
            $candidates,
            $requirePublic,
            $classRemaps,
            $resolved,
            $visiting
        );
        if ($parentSourceIndex === self::PRIVATE_FAILURE) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::PRIVATE_FAILURE;
        }

        $matched = self::findCandidate(
            $candidates,
            self::identityHash($objectName, $className, $classPackage),
            $objectName,
            $className,
            $classPackage,
            $parentSourceIndex,
            $requirePublic
        );

        // Literal Rehack control flow: Mesh is retried as LodMesh only when the
        // exact lookup found no acceptable identity/outer candidate. A matching
        // private Export in UE2.5/UT2004 fails immediately and does not reach here.
        if ($matched === null && self::key($className) === 'mesh') {
            $matched = self::findCandidate(
                $candidates,
                self::identityHash($objectName, 'LodMesh', $classPackage),
                $objectName,
                'LodMesh',
                $classPackage,
                $parentSourceIndex,
                $requirePublic
            );
        }

        // Explicit administrator ClassRemap compatibility is intentionally a
        // fallback after the serialized name (and source-defined Mesh Rehack)
        // fails. Only ObjectName changes; class package/name, outer relationship,
        // selected physical provider and public/private policy remain identical.
        // The retry is single-hop: the replacement is never remapped again.
        if ($matched === null) {
            $remappedObjectName = self::remappedObjectName($objectName, $classRemaps);
            if ($remappedObjectName !== null) {
                $matched = self::findCandidate(
                    $candidates,
                    self::identityHash($remappedObjectName, $className, $classPackage),
                    $remappedObjectName,
                    $className,
                    $classPackage,
                    $parentSourceIndex,
                    $requirePublic
                );
                if ($matched === null && self::key($className) === 'mesh') {
                    $matched = self::findCandidate(
                        $candidates,
                        self::identityHash($remappedObjectName, 'LodMesh', $classPackage),
                        $remappedObjectName,
                        'LodMesh',
                        $classPackage,
                        $parentSourceIndex,
                        $requirePublic
                    );
                }
            }
        }

        unset($visiting[$importIndex]);
        return $resolved[$importIndex] = $matched;
    }

    /** @param array<string,string> $classRemaps */
    private static function remappedObjectName(string $objectName, array $classRemaps): ?string
    {
        $replacement = trim((string)($classRemaps[self::key($objectName)] ?? ''));
        if ($replacement === '' || self::key($replacement) === self::key($objectName)) {
            return null;
        }
        return $replacement;
    }

    /**
     * @param array<string,list<array{export_index:int,outer_index:int,object_flags:int}>> $candidates
     */
    private static function findCandidate(
        array $candidates,
        string $identityHash,
        string $objectName,
        string $className,
        string $classPackage,
        ?int $parentSourceIndex,
        bool $requirePublic
    ): ?int {
        foreach ($candidates[bin2hex($identityHash)] ?? [] as $candidate) {
            if (CatalogUnrealIdentityHash::nameKey((string)$candidate['object_name'])
                    !== CatalogUnrealIdentityHash::nameKey($objectName)
                || CatalogUnrealIdentityHash::nameKey((string)$candidate['class_name'])
                    !== CatalogUnrealIdentityHash::nameKey($className)
                || CatalogUnrealIdentityHash::nameKey((string)$candidate['class_package'])
                    !== CatalogUnrealIdentityHash::nameKey($classPackage)) {
                continue;
            }

            $sourceOuter = (int)$candidate['outer_index'];
            if ($parentSourceIndex === null) {
                if ($sourceOuter !== 0) {
                    continue;
                }
            } elseif ($sourceOuter !== 0 && $sourceOuter !== $parentSourceIndex + 1) {
                continue;
            }

            if ($requirePublic && (((int)$candidate['object_flags'] & self::RF_PUBLIC) === 0)) {
                return self::PRIVATE_FAILURE;
            }
            return (int)$candidate['export_index'];
        }
        return null;
    }

    /**
     * @param list<string> $hashHex
     * @return array<string,list<array{export_index:int,outer_index:int,object_flags:int}>>
     */
    private static function loadCandidates(PDO $db, int $providerFileId, array $hashHex): array
    {
        $result = [];
        foreach (array_chunk($hashHex, self::HASH_BATCH_SIZE) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), 'UNHEX(?)'));
            $statement = $db->prepare(
                'SELECT l.export_index,l.identity_hash,l.outer_index,l.object_flags,'
                . 'ot.value_prefix object_name,ct.value_prefix class_name,pt.value_prefix class_package'
                . ' FROM ue_legacy_export_identity_lookup l'
                . ' JOIN ue_terms ot ON ot.id=l.object_term_id'
                . ' JOIN ue_terms ct ON ct.id=l.class_name_term_id'
                . ' JOIN ue_terms pt ON pt.id=l.class_package_term_id'
                . ' WHERE l.file_id=? AND l.identity_hash IN (' . $placeholders . ')'
                . ' ORDER BY l.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $chunk));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $key = bin2hex((string)$row['identity_hash']);
                $result[$key][] = [
                    'export_index' => (int)$row['export_index'],
                    'outer_index' => (int)$row['outer_index'],
                    'object_flags' => (int)$row['object_flags'],
                    'object_name' => (string)$row['object_name'],
                    'class_name' => (string)$row['class_name'],
                    'class_package' => (string)$row['class_package'],
                ];
            }
        }
        return $result;
    }

    public static function identityHash(string $objectName, string $className, string $classPackage): string
    {
        return CatalogUnrealIdentityHash::verifyImportBinary($objectName, $className, $classPackage);
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::nameKey($value);
    }
}
