<?php
/**
 * Replays the deterministic file-backed portion of UE5 5.8.3
 * FLinkerLoad::VerifyImportInner over source-shaped UEDB5 classic metadata.
 * Runtime-only redirects, native/transient objects, instancing and dynamic
 * import injection are deliberately reported rather than fabricated.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ue5ClassicVerifyImportResolver
{
    private const RF_PUBLIC = 0x00000001;
    private const CORE_UOBJECT_PACKAGE = '/Script/CoreUObject';
    private const OBJECT_REDIRECTOR = 'ObjectRedirector';
    private const UE5_ADD_SOFTOBJECTPATH_LIST = 1008;

    /**
     * Resolve against one already-selected physical provider linker per package.
     *
     * @param array<string,mixed> $consumerSnapshot
     * @param list<array{package_name:string,snapshot:array<string,mixed>,provider_id?:int|string}> $selectedProviders
     * @return array<int,array<string,mixed>> import index => resolution
     */
    public static function resolve(array $consumerSnapshot, array $selectedProviders): array
    {
        self::assertClassicSnapshot($consumerSnapshot, 'consumer');
        $consumer = self::tables($consumerSnapshot);
        $relocationContext = self::relocationContext($consumerSnapshot);
        $providers = [];
        foreach ($selectedProviders as $provider) {
            if (!is_array($provider)) {
                throw new RuntimeException('UE5 classic provider selection contains a non-row value.');
            }
            $packageName = (string)($provider['package_name'] ?? '');
            $snapshot = $provider['snapshot'] ?? null;
            if ($packageName === '' || !is_array($snapshot)) {
                throw new RuntimeException('UE5 classic provider selection requires package_name and snapshot.');
            }
            self::assertClassicSnapshot($snapshot, 'provider ' . $packageName);
            $key = self::key($packageName);
            if (isset($providers[$key])) {
                throw new RuntimeException('UE5 classic resolver requires exactly one selected physical linker for package ' . $packageName . '.');
            }
            $providers[$key] = [
                'package_name' => $packageName,
                'provider_id' => $provider['provider_id'] ?? null,
                'tables' => self::tables($snapshot),
            ];
        }

        $resolved = [];
        $visiting = [];
        foreach (array_keys($consumer['imports']) as $importIndex) {
            self::resolveImport((int)$importIndex, $consumer, $providers, $resolved, $visiting, $relocationContext);
        }
        ksort($resolved, SORT_NUMERIC);
        return $resolved;
    }

    /**
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $consumer
     * @param array<string,array<string,mixed>> $providers
     * @param array<int,array<string,mixed>> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $consumer,
        array $providers,
        array &$resolved,
        array &$visiting,
        ?array $relocationContext
    ): array {
        if (isset($resolved[$importIndex])) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = self::terminal('invalid', 'consumer import outer cycle');
        }
        $import = $consumer['imports'][$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = self::terminal('invalid', 'consumer import index is absent');
        }
        $visiting[$importIndex] = true;

        $className = self::fnameText($import['class_name'] ?? null);
        $classPackage = self::fnameText($import['class_package'] ?? null);
        $objectName = self::fnameText($import['object_name'] ?? null);
        if ($className === '' || $classPackage === '' || $objectName === '') {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::terminal('ignored', 'None class/package/object name');
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        $explicitPackage = self::effectivePackageName($import);
        if ($outerIndex === 0 && $relocationContext !== null) {
            $relocated = self::relocatePackageName($relocationContext, $objectName);
            if ($relocated !== null && $relocated !== $objectName) {
                unset($visiting[$importIndex]);
                return $resolved[$importIndex] = self::terminal(
                    'runtime_only',
                    'Package.Relocation runtime CVar may rewrite top-level import provider identity',
                    $objectName,
                    ['relocated_provider_package_if_enabled' => $relocated]
                );
            }
        }
        if ($outerIndex === 0 && self::key($className) !== self::key('Package')) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::terminal('invalid', 'non-package import has null outer');
        }
        $outerResolution = null;
        if ($outerIndex < 0) {
            $outerImportIndex = -$outerIndex - 1;
            $outerResolution = self::resolveImport(
                $outerImportIndex,
                $consumer,
                $providers,
                $resolved,
                $visiting,
                $relocationContext
            );
        } elseif ($outerIndex > 0 && $explicitPackage === '') {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::terminal(
                'invalid',
                'import with export outer requires explicit PackageName'
            );
        }

        $providerPackage = $explicitPackage;
        if ($providerPackage === '') {
            if ($outerIndex === 0) {
                $providerPackage = $objectName;
            } elseif (is_array($outerResolution)) {
                $providerPackage = (string)($outerResolution['provider_package'] ?? '');
            }
        }

        if ($providerPackage === '') {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::terminal(
                'runtime_only',
                'outer import has no file-backed SourceLinker'
            );
        }

        $provider = $providers[self::key($providerPackage)] ?? null;
        if (!is_array($provider)) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::missing($import, $providerPackage);
        }

        if ($outerIndex === 0) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::packageOnly($provider);
        }
        $providerTables = (array)$provider['tables'];
        $redirectorFallbackSeen = false;
        foreach (self::candidateExports($providerTables, $objectName) as $candidate) {
            $exportIndex = (int)$candidate['index'];
            [$exportClassPackage, $exportClassName] = self::exportClassIdentity(
                $candidate,
                $providerTables,
                (string)$provider['package_name']
            );
            $importWantsRedirector = self::key($className) === self::key(self::OBJECT_REDIRECTOR);
            $candidateIsRedirector = self::key($exportClassName) === self::key(self::OBJECT_REDIRECTOR);
            if ($importWantsRedirector !== $candidateIsRedirector) {
                if (!$importWantsRedirector && $candidateIsRedirector) {
                    $redirectOuter = self::candidateOuterMatches(
                        $import,
                        $candidate,
                        $consumer,
                        $provider,
                        $outerResolution
                    );
                    $redirectPrivate = self::privateGraphAllowance($consumer, $importIndex);
                    if ($redirectOuter['matches']
                        && (self::isPublicExport($candidate) || $redirectPrivate['allowed'])) {
                        $redirectorFallbackSeen = true;
                    }
                }
                continue;
            }

            $outerCheck = self::candidateOuterMatches(
                $import,
                $candidate,
                $consumer,
                $provider,
                $outerResolution
            );
            if (!$outerCheck['matches']) {
                continue;
            }

            $privateGraph = self::privateGraphAllowance($consumer, $importIndex);
            if (!self::isPublicExport($candidate) && !$privateGraph['allowed']) {
                unset($visiting[$importIndex]);
                return $resolved[$importIndex] = self::providerResult(
                    'private_export',
                    $provider,
                    null,
                    'first matching export is private and no source graph exception applies',
                    false,
                    $privateGraph,
                    (bool)$outerCheck['deferred_outer_class_verification']
                );
            }

            $deferredClass = self::key($className) !== self::key($exportClassName)
                || self::key($classPackage) !== self::key($exportClassPackage);
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::providerResult(
                'resolved',
                $provider,
                $exportIndex,
                'file-backed VerifyImportInner match',
                $deferredClass,
                $privateGraph,
                (bool)$outerCheck['deferred_outer_class_verification']
            );
        }
        unset($visiting[$importIndex]);
        if ($redirectorFallbackSeen) {
            return $resolved[$importIndex] = self::providerResult(
                'runtime_only',
                $provider,
                null,
                'ObjectRedirector fallback requires runtime object loading',
                false,
                self::emptyPrivateGraph(),
                false
            );
        }
        return $resolved[$importIndex] = self::providerResult(
            self::isOptional($import) ? 'optional_missing' : 'missing',
            $provider,
            null,
            'no file-backed export matches VerifyImportInner',
            false,
            self::emptyPrivateGraph(),
            false
        );
    }

    /** @param array<string,mixed> $import */
    private static function effectivePackageName(array $import): string
    {
        $name = $import['effective_package_name'] ?? null;
        if (!is_array($name) || !empty($name['is_none'])) {
            return '';
        }
        return (string)($name['text'] ?? '');
    }
    /**
     * ExportHash is built low-to-high and prepends entries, so candidates with
     * the same ObjectName are visited from highest export index to lowest.
     *
     * @param array{exports:array<int,array<string,mixed>>} $provider
     * @return list<array<string,mixed>>
     */
    private static function candidateExports(array $provider, string $objectName): array
    {
        $rows = [];
        foreach ($provider['exports'] as $index => $export) {
            if (self::key(self::fnameText($export['object_name'] ?? null)) === self::key($objectName)) {
                $rows[(int)$index] = $export;
            }
        }
        krsort($rows, SORT_NUMERIC);
        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $export
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $provider
     * @return array{0:string,1:string}
     */
    private static function exportClassIdentity(array $export, array $provider, string $providerPackageName): array
    {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex === 0) {
            return [self::CORE_UOBJECT_PACKAGE, 'Class'];
        }
        if ($classIndex > 0) {
            $classExport = $provider['exports'][$classIndex - 1] ?? null;
            return [
                $providerPackageName,
                is_array($classExport) ? self::fnameText($classExport['object_name'] ?? null) : '',
            ];
        }

        $classImport = $provider['imports'][-$classIndex - 1] ?? null;
        if (!is_array($classImport)) {
            return ['', ''];
        }
        $className = self::fnameText($classImport['object_name'] ?? null);
        $classOuter = (int)($classImport['outer_index'] ?? 0);
        if ($classOuter === 0) {
            return [$className, $className];
        }
        $outerResource = self::resource($provider, $classOuter);
        return [
            is_array($outerResource) ? self::fnameText($outerResource['object_name'] ?? null) : '',
            $className,
        ];
    }

    /**
     * @param array<string,mixed> $consumerImport
     * @param array<string,mixed> $candidate
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $consumer
     * @param array<string,mixed> $provider
     * @param array<string,mixed>|null $outerResolution
     * @return array{matches:bool,deferred_outer_class_verification:bool}
     */
    private static function candidateOuterMatches(
        array $consumerImport,
        array $candidate,
        array $consumer,
        array $provider,
        ?array $outerResolution
    ): array {
        $outerIndex = (int)($consumerImport['outer_index'] ?? 0);
        if ($outerIndex >= 0) {
            // VerifyImportInner performs this SourceExport outer qualification
            // only when the consumer OuterIndex is an import.
            return ['matches' => true, 'deferred_outer_class_verification' => false];
        }
        $outerImport = $consumer['imports'][-$outerIndex - 1] ?? null;
        if (!is_array($outerImport) || !is_array($outerResolution)) {
            return ['matches' => false, 'deferred_outer_class_verification' => false];
        }
        $outerProviderPackage = (string)($outerResolution['provider_package'] ?? '');
        if ($outerProviderPackage === '') {
            return ['matches' => false, 'deferred_outer_class_verification' => false];
        }
        $candidateOuter = (int)($candidate['outer_index'] ?? 0);
        $outerExportIndex = $outerResolution['export_index'] ?? null;

        if ($outerExportIndex === null) {
            return ['matches' => $candidateOuter === 0, 'deferred_outer_class_verification' => false];
        }
        if (self::sameProvider($outerResolution, $provider)) {
            return [
                'matches' => $candidateOuter === ((int)$outerExportIndex + 1),
                'deferred_outer_class_verification' => false,
            ];
        }

        if ($candidateOuter >= 0) {
            return ['matches' => false, 'deferred_outer_class_verification' => false];
        }
        $providerTables = (array)$provider['tables'];
        $sourceExportOuter = $providerTables['imports'][-$candidateOuter - 1] ?? null;
        if (!is_array($sourceExportOuter)
            || self::key(self::fnameText($sourceExportOuter['object_name'] ?? null))
                !== self::key(self::fnameText($outerImport['object_name'] ?? null))) {
            return ['matches' => false, 'deferred_outer_class_verification' => false];
        }

        $deferred = self::key(self::fnameText($sourceExportOuter['class_name'] ?? null))
                !== self::key(self::fnameText($outerImport['class_name'] ?? null))
            || self::key(self::fnameText($sourceExportOuter['class_package'] ?? null))
                !== self::key(self::fnameText($outerImport['class_package'] ?? null));
        return ['matches' => true, 'deferred_outer_class_verification' => $deferred];
    }

    /**
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $consumer
     * @return array{allowed:bool,import_in_export:bool,export_in_import:bool,shared_outermost:bool}
     */
    private static function privateGraphAllowance(array $consumer, int $importIndex): array
    {
        $a = self::importIsInAnyExport($consumer, $importIndex);
        $b = self::anyExportIsInImport($consumer, $importIndex);
        $c = self::anyExportShareOuterWithImport($consumer, $importIndex);
        return [
            'allowed' => $a || $b || $c,
            'import_in_export' => $a,
            'export_in_import' => $b,
            'shared_outermost' => $c,
        ];
    }

    /** @return array{allowed:bool,import_in_export:bool,export_in_import:bool,shared_outermost:bool} */
    private static function emptyPrivateGraph(): array
    {
        return [
            'allowed' => false,
            'import_in_export' => false,
            'export_in_import' => false,
            'shared_outermost' => false,
        ];
    }

    /** @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function importIsInAnyExport(array $tables, int $importIndex): bool
    {
        $import = $tables['imports'][$importIndex] ?? null;
        if (!is_array($import)) {
            return false;
        }
        $linkerIndex = (int)($import['outer_index'] ?? 0);
        $seen = [];
        while ($linkerIndex !== 0) {
            if (isset($seen[$linkerIndex])) {
                return false;
            }
            $seen[$linkerIndex] = true;
            $linkerIndex = self::resourceOuter($tables, $linkerIndex);
            if ($linkerIndex > 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function anyExportIsInImport(array $tables, int $importIndex): bool
    {
        $outerIndex = -($importIndex + 1);
        foreach (array_keys($tables['exports']) as $exportIndex) {
            if (self::resourceIsIn($tables, (int)$exportIndex + 1, $outerIndex)) {
                return true;
            }
        }
        return false;
    }

    /** @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function anyExportShareOuterWithImport(array $tables, int $importIndex): bool
    {
        $importPackageIndex = -($importIndex + 1);
        foreach ($tables['exports'] as $exportIndex => $export) {
            if ((int)($export['outer_index'] ?? 0) >= 0) {
                continue;
            }
            if (self::resourceOutermost($tables, (int)$exportIndex + 1)
                === self::resourceOutermost($tables, $importPackageIndex)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exact FLinkerTables::ResourceIsIn traversal: the immediate outer is
     * assigned before the loop, and comparison occurs after stepping again.
     *
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables
     */
    private static function resourceIsIn(array $tables, int $linkerIndex, int $outerIndex): bool
    {
        $linkerIndex = self::resourceOuter($tables, $linkerIndex);
        $seen = [];
        while ($linkerIndex !== 0) {
            if (isset($seen[$linkerIndex])) {
                return false;
            }
            $seen[$linkerIndex] = true;
            $linkerIndex = self::resourceOuter($tables, $linkerIndex);
            if ($linkerIndex === $outerIndex) {
                return true;
            }
        }
        return false;
    }
    /** @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function resourceOutermost(array $tables, int $linkerIndex): int
    {
        $seen = [];
        while ($linkerIndex !== 0) {
            if (isset($seen[$linkerIndex])) {
                return 0;
            }
            $seen[$linkerIndex] = true;
            $outer = self::resourceOuter($tables, $linkerIndex);
            if ($outer === 0) {
                return $linkerIndex;
            }
            $linkerIndex = $outer;
        }
        return 0;
    }

    /** @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function resourceOuter(array $tables, int $packageIndex): int
    {
        $resource = self::resource($tables, $packageIndex);
        return is_array($resource) ? (int)($resource['outer_index'] ?? 0) : 0;
    }

    /**
     * @param array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables
     * @return array<string,mixed>|null
     */
    private static function resource(array $tables, int $packageIndex): ?array
    {
        if ($packageIndex < 0) {
            return $tables['imports'][-$packageIndex - 1] ?? null;
        }
        if ($packageIndex > 0) {
            return $tables['exports'][$packageIndex - 1] ?? null;
        }
        return null;
    }
    /** @return array{current_path:string,original_path:string,original_mount:string}|null */
    private static function relocationContext(array $snapshot): ?array
    {
        $summary = (array)(((array)($snapshot['sections']['summary'] ?? []))[0] ?? []);
        $version = (int)($summary['effective_file_version']['ue5'] ?? $summary['serialized_file_version']['ue5'] ?? 0);
        if ($version < self::UE5_ADD_SOFTOBJECTPATH_LIST) { return null; }
        $currentPath = self::packagePath((string)($snapshot['file']['package_name'] ?? ''));
        $originalPath = self::packagePath((string)($summary['package_name'] ?? ''));
        if ($currentPath === '' || $originalPath === '' || $currentPath === $originalPath) { return null; }
        $mount = self::packageMount($originalPath);
        if ($mount === '' || str_starts_with($mount, '/Classes_')) { return null; }
        return ['current_path'=>$currentPath,'original_path'=>$originalPath,'original_mount'=>$mount];
    }

    private static function packagePath(string $packageName): string
    {
        $slash = strrpos($packageName, '/');
        return $slash === false || $slash === 0 ? '' : substr($packageName, 0, $slash);
    }

    private static function packageMount(string $packagePath): string
    {
        if ($packagePath === '' || $packagePath[0] !== '/') { return ''; }
        $next = strpos($packagePath, '/', 1);
        if ($next === false) { return $packagePath . '/'; }
        return substr($packagePath, 0, $next + 1);
    }

    /** Returns null when TryRelocateReference would not touch this package name. */
    private static function relocatePackageName(array $context, string $packageName): ?string
    {
        $mount = (string)$context['original_mount'];
        if (!str_starts_with($packageName, $mount)) { return null; }
        $split = static fn(string $path): array => array_values(array_filter(explode('/', $path), static fn(string $v): bool => $v !== ''));
        $target = $split(substr($packageName, strlen($mount)));
        $original = $split(substr((string)$context['original_path'], strlen($mount)));
        $identical = 0;
        while ($identical < count($target) && $identical < count($original) && $target[$identical] === $original[$identical]) { $identical++; }
        $folderUp = count($original) - $identical;
        $append = array_slice($target, $identical);
        $current = $split((string)$context['current_path']);
        if (count($current) <= $folderUp) { return ''; }
        $parts = array_merge(array_slice($current, 0, count($current) - $folderUp), $append);
        return '/' . implode('/', $parts);
    }

    private static function sameProvider(array $outerResolution, array $provider): bool
    {
        $outerId = $outerResolution['provider_id'] ?? null;
        $providerId = $provider['provider_id'] ?? null;
        if ($outerId !== null && $providerId !== null) {
            return (string)$outerId === (string)$providerId;
        }
        return self::key((string)($outerResolution['provider_package'] ?? ''))
            === self::key((string)($provider['package_name'] ?? ''));
    }

    /** @param array<string,mixed> $export */
    private static function isPublicExport(array $export): bool
    {
        $hex = strtoupper(trim((string)($export['object_flags'] ?? '')));
        if (preg_match('/^[0-9A-F]{16}$/', $hex) !== 1) {
            throw new RuntimeException('UE5 classic export ObjectFlags must be canonical 16-hex-digit UEDB5 data.');
        }
        $low = (int)hexdec(substr($hex, -8));
        return ($low & self::RF_PUBLIC) !== 0;
    }

    /** @param array<string,mixed> $import */
    private static function isOptional(array $import): bool
    {
        return !empty($import['b_import_optional_present']) && !empty($import['b_import_optional']);
    }

    /** @param mixed $value */
    private static function fnameText(mixed $value): string
    {
        return is_array($value) ? (string)($value['text'] ?? '') : '';
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::fnameKey($value);
    }

    /** @return array<string,mixed> */
    private static function missing(array $import, string $providerPackage): array
    {
        if (strncasecmp($providerPackage, '/Script/', 8) === 0) {
            return self::terminal(
                'runtime_only',
                'script package/native object resolution requires runtime state',
                $providerPackage
            );
        }
        return self::terminal(
            self::isOptional($import) ? 'optional_missing' : 'missing',
            'selected package linker is unavailable',
            $providerPackage
        );
    }
    /** @return array<string,mixed> */
    private static function packageOnly(array $provider): array
    {
        return [
            'status' => 'package_only',
            'provider_package' => (string)$provider['package_name'],
            'provider_id' => $provider['provider_id'] ?? null,
            'export_index' => null,
            'reason' => 'top-level UPackage import establishes SourceLinker only',
            'deferred_class_verification' => false,
            'deferred_outer_class_verification' => false,
            'private_graph' => self::emptyPrivateGraph(),
        ];
    }

    /** @return array<string,mixed> */
    private static function terminal(string $status, string $reason, string $providerPackage = '', array $detail = []): array
    {
        return array_merge([
            'status' => $status,
            'provider_package' => $providerPackage,
            'provider_id' => null,
            'export_index' => null,
            'reason' => $reason,
            'deferred_class_verification' => false,
            'deferred_outer_class_verification' => false,
            'private_graph' => self::emptyPrivateGraph(),
        ], $detail);
    }
    /**
     * @param array{allowed:bool,import_in_export:bool,export_in_import:bool,shared_outermost:bool} $privateGraph
     * @return array<string,mixed>
     */
    private static function providerResult(
        string $status,
        array $provider,
        ?int $exportIndex,
        string $reason,
        bool $deferredClass,
        array $privateGraph,
        bool $deferredOuterClass
    ): array {
        return [
            'status' => $status,
            'provider_package' => (string)$provider['package_name'],
            'provider_id' => $provider['provider_id'] ?? null,
            'export_index' => $exportIndex,
            'reason' => $reason,
            'deferred_class_verification' => $deferredClass,
            'deferred_outer_class_verification' => $deferredOuterClass,
            'private_graph' => $privateGraph,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>}
     */
    private static function tables(array $snapshot): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
        return [
            'imports' => self::indexRows((array)($sections['imports'] ?? []), 'index'),
            'exports' => self::indexRows((array)($sections['exports'] ?? []), 'index'),
        ];
    }
    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function indexRows(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $fallback => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('UE5 classic UEDB5 section contains a non-row value.');
            }
            $index = array_key_exists($field, $row) ? (int)$row[$field] : (int)$fallback;
            if ($index < 0 || isset($indexed[$index])) {
                throw new RuntimeException('UE5 classic UEDB5 section contains an invalid or duplicate index.');
            }
            $indexed[$index] = $row;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /** @param array<string,mixed> $snapshot */
    private static function assertClassicSnapshot(array $snapshot, string $label): void
    {
        if ((string)($snapshot['package_family'] ?? '') !== Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY
            || (string)($snapshot['source_policy'] ?? '') !== Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY) {
            throw new RuntimeException('UE5 classic resolver received incompatible ' . $label . ' source policy.');
        }
        $schemas = (array)($snapshot['section_schemas'] ?? []);
        if ((string)($schemas['imports'] ?? '') !== 'ue5.classic.object-import.v1'
            || (string)($schemas['exports'] ?? '') !== 'ue5.classic.object-export.v1') {
            throw new RuntimeException('UE5 classic resolver requires Section 4 import/export schemas.');
        }
        $sections = (array)($snapshot['sections'] ?? []);
        foreach (['imports', 'exports'] as $section) {
            if (!isset($sections[$section]) || !is_array($sections[$section]) || !array_is_list($sections[$section])) {
                throw new RuntimeException('UE5 classic resolver requires list section ' . $section . '.');
            }
        }
        foreach ((array)$sections['imports'] as $row) {
            if (!is_array($row)
                || !isset($row['class_package'], $row['class_name'], $row['object_name'])
                || !array_key_exists('outer_index', $row)
                || !array_key_exists('effective_package_name', $row)
                || !array_key_exists('b_import_optional_present', $row)
                || !array_key_exists('b_import_optional', $row)) {
                throw new RuntimeException('UE5 classic resolver import row is missing Section 4 source fields.');
            }
        }
        foreach ((array)$sections['exports'] as $row) {
            if (!is_array($row)
                || !isset($row['object_name'], $row['object_flags'])
                || !array_key_exists('class_index', $row)
                || !array_key_exists('outer_index', $row)) {
                throw new RuntimeException('UE5 classic resolver export row is missing Section 4 source fields.');
            }
            if ((int)($row['object_flags_serialized_width_bits'] ?? 0) !== 32) {
                throw new RuntimeException('UE5 classic resolver requires uint32-serialized ObjectFlags semantics.');
            }
        }
    }
}
