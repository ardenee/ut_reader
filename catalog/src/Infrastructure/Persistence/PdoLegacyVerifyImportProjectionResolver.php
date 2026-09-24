<?php
/**
 * Resolves UE1/UE2 Imports against the indexed projection of ULinkerLoad::VerifyImport identity.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoLegacyVerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const HASH_BATCH_SIZE = 400;
    private const PRIVATE_FAILURE = -2147483648;

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveProviderVariants(PDO $db, int $providerFileId, array $consumerImports): array
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
            if (self::key($className) === 'mesh') {
                $hashes[bin2hex(self::identityHash($objectName, 'LodMesh', $classPackage))] = true;
            }
        }
        if ($hashes === []) {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        $candidates = self::loadCandidates($db, $providerFileId, array_keys($hashes));
        $standard = self::resolveVariant($imports, $candidates, true);
        $unreal2 = self::resolveVariant($imports, $candidates, false);

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
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array{export_index:int,outer_index:int,object_flags:int}>> $candidates
     * @return array<int,int>
     */
    private static function resolveVariant(array $imports, array $candidates, bool $requirePublic): array
    {
        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImport(
                (int)$importIndex,
                $imports,
                $candidates,
                $requirePublic,
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
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $imports,
        array $candidates,
        bool $requirePublic,
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
                $parentSourceIndex,
                $requirePublic
            );
        }

        unset($visiting[$importIndex]);
        return $resolved[$importIndex] = $matched;
    }

    /**
     * @param array<string,list<array{export_index:int,outer_index:int,object_flags:int}>> $candidates
     */
    private static function findCandidate(
        array $candidates,
        string $identityHash,
        ?int $parentSourceIndex,
        bool $requirePublic
    ): ?int {
        foreach ($candidates[bin2hex($identityHash)] ?? [] as $candidate) {
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
                'SELECT export_index,identity_hash,outer_index,object_flags'
                . ' FROM ue_legacy_export_identity_lookup'
                . ' WHERE file_id=? AND identity_hash IN (' . $placeholders . ')'
                . ' ORDER BY export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $chunk));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $key = bin2hex((string)$row['identity_hash']);
                $result[$key][] = [
                    'export_index' => (int)$row['export_index'],
                    'outer_index' => (int)$row['outer_index'],
                    'object_flags' => (int)$row['object_flags'],
                ];
            }
        }
        return $result;
    }

    public static function identityHash(string $objectName, string $className, string $classPackage): string
    {
        return md5(
            self::key($objectName) . "\0"
            . self::key($className) . "\0"
            . self::key($classPackage),
            true
        );
    }

    private static function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
