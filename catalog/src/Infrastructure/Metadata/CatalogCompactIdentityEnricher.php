<?php
/**
 * Enriches current compact snapshots with durable derived identity metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

/**
 * Current-format identity enrichment. Future v5+ changes should produce their
 * own format-specific enrichment contract during the offline format migration.
 */
final class CatalogCompactIdentityEnricher
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function enrich(array $snapshot, string $engineKey): array
    {
        $engineKey = strtoupper(trim($engineKey));
        $legacyVerifyImport = in_array($engineKey, ['UE1', 'UE2'], true);

        $imports = array_values((array)($snapshot['imports'] ?? []));
        $exports = array_values((array)($snapshot['exports'] ?? []));

        foreach ($imports as &$row) {
            if (!is_array($row)) {
                continue;
            }

            // UE4 native/script modules are serialized as long package names such
            // as /Script/Engine and /Script/CoreUObject. They are runtime script
            // packages, not physical .uasset providers. Keep this source-backed
            // UE4 rule separate from the historical short-name common list.
            if ($engineKey === 'UE4' && self::isUe4ScriptPackage((string)($row['root_package'] ?? ''))) {
                $row['is_common'] = 1;
            }

            $relative = (string)($row['relative_object_path'] ?? '');
            $row['path_hash_ci'] = $relative !== ''
                ? CatalogUnrealIdentityHash::objectPathHex($relative)
                : '';

            if ($legacyVerifyImport) {
                $objectName = (string)($row['object_name'] ?? '');
                $className = (string)($row['class_name'] ?? '');
                $classPackage = (string)($row['class_package'] ?? '');
                $row['verify_identity_hash'] =
                    ($objectName !== '' && $className !== '' && $classPackage !== '')
                        ? CatalogUnrealIdentityHash::verifyImportHex($objectName, $className, $classPackage)
                        : '';
            } else {
                $row['verify_identity_hash'] = '';
            }
        }
        unset($row);

        $importsByIndex = self::indexRows($imports, 'import_index');
        $exportsByIndex = self::indexRows($exports, 'export_index');
        $packageName = (string)($snapshot['file']['package_name'] ?? '');

        foreach ($exports as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $localPath = (string)($row['local_path'] ?? '');
            $row['path_hash_ci'] = $localPath !== ''
                ? CatalogUnrealIdentityHash::objectPathHex($localPath)
                : '';

            if ($legacyVerifyImport) {
                [$classPackage, $className] = self::legacyExportClassIdentity(
                    $row,
                    $importsByIndex,
                    $exportsByIndex,
                    $packageName
                );
                $row['verify_class_package'] = $classPackage;
                $row['verify_class_name'] = $className;
                $objectName = (string)($row['object_name'] ?? '');
                $row['verify_identity_hash'] =
                    ($objectName !== '' && $className !== '' && $classPackage !== '')
                        ? CatalogUnrealIdentityHash::verifyImportHex($objectName, $className, $classPackage)
                        : '';
            } else {
                $row['verify_class_package'] = '';
                $row['verify_class_name'] = '';
                $row['verify_identity_hash'] = '';
            }
        }
        unset($row);

        $snapshot['imports'] = $imports;
        $snapshot['exports'] = $exports;
        $snapshot['identity_schema'] = [
            'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
            'verify_import_hash_algorithm' => CatalogUnrealIdentityHash::VERIFY_IMPORT_ALGORITHM,
            'object_path_hash_algorithm' => CatalogUnrealIdentityHash::OBJECT_PATH_ALGORITHM,
            'engine_key' => $engineKey,
        ];
        return $snapshot;
    }

    /**
     * @param array<string,mixed> $export
     * @param array<int,array<string,mixed>> $imports
     * @param array<int,array<string,mixed>> $exports
     * @return array{0:string,1:string}
     */
    public static function legacyExportClassIdentity(
        array $export,
        array $imports,
        array $exports,
        string $packageName
    ): array {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $imports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) {
                return ['', $className];
            }
            $classPackageImport = $imports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport)
                    ? (string)($classPackageImport['object_name'] ?? '')
                    : '',
                $className,
            ];
        }

        if ($classIndex > 0) {
            $classExport = $exports[$classIndex - 1] ?? null;
            return [
                $packageName,
                is_array($classExport)
                    ? (string)($classExport['object_name'] ?? '')
                    : '',
            ];
        }

        return ['Core', 'Class'];
    }

    /**
     * UE3 ULinkerLoad::GetExportClassPackage/GetExportClassName identity.
     * Cooked UE3 can represent a class import whose OuterIndex is an export,
     * which differs from the legacy UE1/UE2 projection rule.
     *
     * @param array<string,mixed> $export
     * @param array<int,array<string,mixed>> $imports
     * @param array<int,array<string,mixed>> $exports
     * @return array{0:string,1:string}
     */
    public static function ue3ExportClassIdentity(
        array $export,
        array $imports,
        array $exports,
        string $packageName,
        ?int $packageVersion = null,
        bool $ut3SourcePolicy = false
    ): array {
        // UT3 package version 512 predates ULinkerLoad::RemapClasses(). The
        // January 2008 source identifies 512 as VER_FULL_VERSION_OF_UT3_BUMP.
        // Do not leak the later UDK prefab remap into UT3 package matching.
        if (!$ut3SourcePolicy && $packageVersion !== null && $packageVersion < 536) {
            $remapped = self::ue3PrefabExportClassIdentity($export, $imports, $exports);
            if ($remapped !== null) {
                return $remapped;
            }
        }

        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $imports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter < 0) {
                $classPackageImport = $imports[-$classOuter - 1] ?? null;
                return [
                    is_array($classPackageImport)
                        ? (string)($classPackageImport['object_name'] ?? '')
                        : '',
                    $className,
                ];
            }
            if ($classOuter > 0) {
                if ($ut3SourcePolicy) {
                    // January 2008 GetExportClassPackage() requires the class
                    // import OuterIndex to be another import. Positive export
                    // outers were added by a later UE3 linker revision.
                    return ['', $className];
                }
                $classPackageExport = $exports[$classOuter - 1] ?? null;
                return [
                    is_array($classPackageExport)
                        ? (string)($classPackageExport['object_name'] ?? '')
                        : '',
                    $className,
                ];
            }
            return ['', $className];
        }

        if ($classIndex > 0) {
            $classExport = $exports[$classIndex - 1] ?? null;
            return [
                $packageName,
                is_array($classExport)
                    ? (string)($classExport['object_name'] ?? '')
                    : '',
            ];
        }

        return ['Core', 'Class'];
    }


    /**
     * Deterministic class-identity effect of UE3 ULinkerLoad::RemapClasses for
     * pre-VER_FIXED_PREFAB_SEQUENCES packages. Returns null when unchanged.
     *
     * @param array<string,mixed> $export
     * @param array<int,array<string,mixed>> $imports
     * @param array<int,array<string,mixed>> $exports
     * @return array{0:string,1:string}|null
     */
    private static function ue3PrefabExportClassIdentity(array $export, array $imports, array $exports): ?array
    {
        $requiresFixup = false;
        $sequenceClassIndex = 0;
        $prefabClassIndex = 0;
        foreach ($imports as $importIndex => $import) {
            if (!is_array($import) || strcasecmp((string)($import['class_name'] ?? ''), 'Class') !== 0) {
                continue;
            }
            $name = (string)($import['object_name'] ?? '');
            if (strcasecmp($name, 'Prefab') === 0 || strcasecmp($name, 'PrefabInstance') === 0) {
                $requiresFixup = true;
            }
            if (strcasecmp($name, 'Sequence') === 0) {
                $sequenceClassIndex = -((int)$importIndex) - 1;
            } elseif (strcasecmp($name, 'Prefab') === 0) {
                $prefabClassIndex = -((int)$importIndex) - 1;
            }
        }
        if (!$requiresFixup || $sequenceClassIndex === 0
            || (int)($export['class_index'] ?? 0) !== $sequenceClassIndex) {
            return null;
        }

        if (strcasecmp((string)($export['object_name'] ?? ''), 'Prefabs') === 0) {
            return ['Engine', 'PrefabSequenceContainer'];
        }
        $outerIndex = (int)($export['outer_index'] ?? 0);
        if ($outerIndex > 0) {
            $outer = $exports[$outerIndex - 1] ?? null;
            if (is_array($outer)
                && (strcasecmp((string)($outer['object_name'] ?? ''), 'Prefabs') === 0
                    || (int)($outer['class_index'] ?? 0) === $prefabClassIndex)) {
                return ['Engine', 'PrefabSequence'];
            }
        }
        return null;
    }

    /**
     * Rebuild the deterministic import path from the post-FixupImportMap outer
     * chain. When exports are supplied, cooked import->export outers follow the
     * same mixed package-index graph used by UE3 GetImportPathName.
     *
     * @param array<int,array<string,mixed>> $imports
     * @return array{root:string,full:string,relative:string}
     */
    public static function ue3EffectiveImportPath(array $imports, int $importIndex, array $exports = []): array
    {
        $imports = self::ue3FixupImportMap($imports);
        $segments = [];
        $seen = [];
        $ref = -$importIndex - 1;
        $result = '';
        while (true) {
            if ($ref === 0 || isset($seen[$ref])) {
                return ['root' => '', 'full' => '', 'relative' => ''];
            }
            $seen[$ref] = true;
            $isImport = $ref < 0;
            $row = $isImport ? ($imports[-$ref - 1] ?? null) : ($exports[$ref - 1] ?? null);
            if (!is_array($row)) {
                return ['root' => '', 'full' => '', 'relative' => ''];
            }
            $name = (string)($row['object_name'] ?? '');
            if ($name === '') {
                return ['root' => '', 'full' => '', 'relative' => ''];
            }
            $segments[] = $name;
            if ($result !== '') {
                // UE3 GetImportPathName uses ':' when the current non-package
                // resource sits directly under a package; otherwise it uses '.'.
                $delimiter = self::ue3ResourceUsesSubobjectDelimiter($row, $isImport, $imports, $exports)
                    ? ':' : '.';
                $result = $name . $delimiter . $result;
            } else {
                $result = $name;
            }
            $outerIndex = (int)($row['outer_index'] ?? 0);
            if ($outerIndex === 0) {
                break;
            }
            if ($outerIndex > 0 && $exports === []) {
                return ['root' => '', 'full' => '', 'relative' => ''];
            }
            $ref = $outerIndex;
        }
        $root = (string)end($segments);
        $relative = '';
        if ($root !== '' && $result !== $root) {
            $relative = substr($result, strlen($root));
            if ($relative !== '' && ($relative[0] === '.' || $relative[0] === ':')) {
                $relative = substr($relative, 1);
            }
        }
        return ['root' => $root, 'full' => $result, 'relative' => $relative];
    }

    /** Mirrors the delimiter decision inside UE3 ULinker::GetImportPathName. */
    private static function ue3ResourceUsesSubobjectDelimiter(
        array $row,
        bool $isImport,
        array $imports,
        array $exports
    ): bool {
        $outer = (int)($row['outer_index'] ?? 0);
        if ($isImport && strcasecmp((string)($row['class_name'] ?? ''), 'Package') === 0) {
            return false;
        }
        if ($outer === 0) {
            return !$isImport || strcasecmp((string)($row['class_name'] ?? ''), 'Package') !== 0;
        }
        return self::ue3ResourceClassName($outer, $imports, $exports) !== ''
            && strcasecmp(self::ue3ResourceClassName($outer, $imports, $exports), 'Package') === 0;
    }

    private static function ue3ResourceClassName(int $ref, array $imports, array $exports): string
    {
        if ($ref < 0) {
            $row = $imports[-$ref - 1] ?? null;
            return is_array($row) ? (string)($row['class_name'] ?? '') : '';
        }
        if ($ref > 0) {
            $row = $exports[$ref - 1] ?? null;
            if (!is_array($row)) { return ''; }
            $classIndex = (int)($row['class_index'] ?? 0);
            if ($classIndex < 0) {
                $classImport = $imports[-$classIndex - 1] ?? null;
                return is_array($classImport) ? (string)($classImport['object_name'] ?? '') : '';
            }
            if ($classIndex > 0) {
                $classExport = $exports[$classIndex - 1] ?? null;
                return is_array($classExport) ? (string)($classExport['object_name'] ?? '') : '';
            }
            return 'Class';
        }
        return '';
    }

    /**
     * UE3 ULinkerLoad::FixupImportMap compatibility remaps. These are fixed
     * engine rules, not configurable game ClassRemap entries.
     *
     * @param array<int,array<string,mixed>> $imports
     * @return array<int,array<string,mixed>>
     */
    public static function ue3FixupImportMap(array $imports): array
    {
        foreach ($imports as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $objectName = (string)($row['object_name'] ?? '');
            $className = (string)($row['class_name'] ?? '');
            $classPackage = (string)($row['class_package'] ?? '');
            $outerIndex = (int)($row['outer_index'] ?? 0);

            if (strcasecmp($objectName, 'SoundCueLocalized') === 0
                && strcasecmp($className, 'Class') === 0) {
                if ($outerIndex < 0) {
                    $outer = $imports[-$outerIndex - 1] ?? null;
                    if (is_array($outer)
                        && strcasecmp((string)($outer['object_name'] ?? ''), 'Engine') === 0) {
                        $row['object_name'] = 'SoundCue';
                    }
                }
            } elseif (strcasecmp($className, 'SoundCueLocalized') === 0
                && strcasecmp($classPackage, 'Engine') === 0) {
                $row['class_name'] = 'SoundCue';
            }

            if (strcasecmp((string)($row['object_name'] ?? ''), 'SequenceObjects') === 0
                && strcasecmp((string)($row['class_name'] ?? ''), 'Package') === 0) {
                $row['object_name'] = 'Engine';
            }
            if (strcasecmp((string)($row['class_package'] ?? ''), 'SequenceObjects') === 0) {
                $row['class_package'] = 'Engine';
            }
            $imports[$index] = $row;
        }
        return $imports;
    }

    private static function isUe4ScriptPackage(string $packageName): bool
    {
        return strncasecmp(trim($packageName), '/Script/', 8) === 0;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
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
}
