<?php
/**
 * Enriches current compact snapshots with durable derived identity metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

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
            $relative = (string)($row['relative_object_path'] ?? '');
            $row['path_hash_ci'] = $relative !== ''
                ? CatalogUnrealIdentityHash::objectPathHex($relative)
                : '';

            if ($legacyVerifyImport) {
                $objectName = trim((string)($row['object_name'] ?? ''));
                $className = trim((string)($row['class_name'] ?? ''));
                $classPackage = trim((string)($row['class_package'] ?? ''));
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
        $packageName = trim((string)($snapshot['file']['package_name'] ?? ''));

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
                $objectName = trim((string)($row['object_name'] ?? ''));
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
            'row_schema_version' => 2,
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
            $className = trim((string)($classImport['object_name'] ?? ''));
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) {
                return ['', $className];
            }
            $classPackageImport = $imports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport)
                    ? trim((string)($classPackageImport['object_name'] ?? ''))
                    : '',
                $className,
            ];
        }

        if ($classIndex > 0) {
            $classExport = $exports[$classIndex - 1] ?? null;
            return [
                trim($packageName),
                is_array($classExport)
                    ? trim((string)($classExport['object_name'] ?? ''))
                    : '',
            ];
        }

        return ['Core', 'Class'];
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
