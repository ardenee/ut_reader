<?php
/**
 * Resolves UE3 Imports against the v4 export projection using the deterministic
 * file-backed portion of ULinkerLoad::VerifyImportInner.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeProviderSnapshotLoader;

require_once dirname(__DIR__) . '/Metadata/CatalogUnrealIdentityHash.php';

final class PdoUe3VerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x0000000400000000;
    private const HASH_BATCH_SIZE = 300;
    private const FAILURE_SENTINEL = -2147483648;

    public const PROFILE_UT3_V512 = 'ue3-ut3-v512-early2008';

    /**
     * Source-shaped UT3 VerifyImport outcome using the selected provider only.
     * The provider is chosen before this method; provider contents never select a different file.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<int>|null $targetImportIndexes
     * @return array<int,array<string,mixed>>
     */
    public static function resolveProviderOutcome(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        string $profile,
        array $consumerExports = [],
        ?array $targetImportIndexes = null,
        ?string $storageRoot = null
    ): array {
        if ($profile !== self::PROFILE_UT3_V512 || $providerFileId < 1 || $consumerImports === []) {
            return [];
        }
        $imports = self::indexedImports($consumerImports);
        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($imports);
        $targets = $targetImportIndexes === null
            ? array_map('intval', array_keys($imports))
            : array_values(array_unique(array_map('intval', $targetImportIndexes)));
        if ($targets === []) { return []; }
        $needed = self::importClosure($imports, $targets);
        $objectNames = self::objectNamesForImports($imports, $needed);
        $candidates = self::loadUedb5Candidates(
            $db,
            $providerFileId,
            $objectNames,
            self::storageRoot($storageRoot)
        );
        $outcomes = self::resolveTargetOutcomes($imports, $candidates, $targets, $consumerExports);

        $fallbackTargets = [];
        foreach ($targets as $importIndex) {
            $row = $imports[(int)$importIndex] ?? null;
            $status = (string)($outcomes[(int)$importIndex]['status'] ?? '');
            if (is_array($row)
                && (int)($row['outer_index'] ?? 0) < 0
                && !self::hasNameNone($row)
                && $status !== 'resolved'
                && $status !== 'private_export') {
                $fallbackTargets[] = (int)$importIndex;
            }
        }
        if ($fallbackTargets === []) { return $outcomes; }
        $fallbackNeeded = self::importClosure($imports, $fallbackTargets);
        $fallbackNames = self::objectNamesForImports($imports, $fallbackNeeded);
        $fallback = self::loadUedb5Candidates(
            $db,
            $providerFileId,
            $fallbackNames,
            self::storageRoot($storageRoot)
        );
        if ($fallback === []) { return $outcomes; }
        return self::resolveTargetOutcomes(
            $imports,
            self::mergeCandidates($candidates, $fallback),
            $targets,
            $consumerExports
        );
    }

    /**
     * In-memory source outcome for UEDB5/local publication.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @param list<array<string,mixed>> $consumerExports
     * @return array<int,array<string,mixed>>
     */
    public static function resolveInMemoryOutcome(
        string $profile,
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        ?int $providerPackageVersion = null,
        array $consumerExports = []
    ): array {
        if ($profile !== self::PROFILE_UT3_V512) { return []; }
        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap(
            self::indexedImports($consumerImports)
        );
        $providerImportsByIndex = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap(
            self::indexedImports($providerImports)
        );
        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (!is_array($row)) { continue; }
            $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
        }
        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectName = (string)($export['object_name'] ?? '');
            if ($objectName === '') { continue; }
            [$classPackage,$className] = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName,
                $providerPackageVersion,
                true
            );
            $identityKey = self::identityKey($objectName,$className,$classPackage);
            $candidates[$identityKey][] = [
                'export_index'=>(int)$exportIndex,
                'object_name'=>$objectName,
                'outer_index'=>(int)($export['outer_index'] ?? 0),
                'object_flags'=>(int)($export['object_flags'] ?? 0),
                'class_package'=>$classPackage,
                'class_name'=>$className,
            ];
        }
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a,array $b):int => (int)$b['export_index'] <=> (int)$a['export_index']);
        }
        unset($rows);
        return self::resolveTargetOutcomes(
            $imports,
            $candidates,
            array_map('intval', array_keys($imports)),
            $consumerExports
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @return array<int,int>
     */
    public static function resolveProvider(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        ?array $targetImportIndexes = null,
        ?string $storageRoot = null
    ): array {
        if ($providerFileId < 1 || $consumerImports === []) {
            return [];
        }

        $imports = [];
        foreach ($consumerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
            $imports[$index] = $row;
        }
        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($imports);

        $targets = $targetImportIndexes === null
            ? array_map('intval', array_keys($imports))
            : array_values(array_unique(array_map('intval', $targetImportIndexes)));
        if ($targets === []) {
            return [];
        }

        $needed = self::importClosure($imports, $targets);
        $objectNames = self::objectNamesForImports($imports, $needed);
        $candidates = self::loadUedb5Candidates(
            $db,
            $providerFileId,
            $objectNames,
            self::storageRoot($storageRoot)
        );
        $matches = self::resolveTargetImports($imports, $candidates, $targets);

        $unresolved = [];
        foreach ($targets as $importIndex) {
            $import = $imports[$importIndex] ?? null;
            if (is_array($import) && (int)($import['outer_index'] ?? 0) !== 0 && !isset($matches[$importIndex])) {
                $unresolved[] = $importIndex;
            }
        }
        if ($unresolved === []) {
            return $matches;
        }

        // FName comparison is case-insensitive. The compact term dictionary keeps
        // display casing, so only unresolved names pay for the slower collation
        // fallback that discovers differently-cased provider terms.
        $fallbackNeeded = self::importClosure($imports, $unresolved);
        $fallbackNames = self::objectNamesForImports($imports, $fallbackNeeded);
        $fallback = self::loadUedb5Candidates(
            $db,
            $providerFileId,
            $fallbackNames,
            self::storageRoot($storageRoot)
        );
        if ($fallback === []) {
            return $matches;
        }
        return self::resolveTargetImports($imports, self::mergeCandidates($candidates, $fallback), $targets);
    }

    /**
     * In-memory equivalent used while the provider package is being published.
     *
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
        ?int $providerPackageVersion = null,
        bool $ut3SourcePolicy = false
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
        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($imports);
        $providerImportsByIndex = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($providerImportsByIndex);

        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (is_array($row)) {
                $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectKey = self::key((string)($export['object_name'] ?? ''));
            if ($objectKey === '') {
                continue;
            }
            [$classPackage, $className] =
                \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $export,
                    $providerImportsByIndex,
                    $providerExportsByIndex,
                    $providerPackageName,
                    $providerPackageVersion,
                    $ut3SourcePolicy
                );
            $identityKey = self::identityKey((string)($export['object_name'] ?? ''), $className, $classPackage);
            $candidates[$identityKey][] = [
                'export_index' => (int)$exportIndex,
                'object_name' => (string)($export['object_name'] ?? ''),
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
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
            self::resolveImport((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }
        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::FAILURE_SENTINEL) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function indexedImports(array $rows): array
    {
        $result=[];
        foreach($rows as $fallback=>$row){
            if(!is_array($row))continue;
            $result[isset($row['import_index'])?(int)$row['import_index']:(int)$fallback]=$row;
        }
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param list<int> $targets
     * @param list<array<string,mixed>> $consumerExports
     * @return array<int,array<string,mixed>>
     */
    private static function resolveTargetOutcomes(array $imports,array $candidates,array $targets,array $consumerExports): array
    {
        $cache=[];$visiting=[];
        foreach($targets as$index){
            self::resolveImportOutcome((int)$index,$imports,$candidates,$consumerExports,$cache,$visiting);
        }
        $result=[];
        foreach($targets as$index){
            if(isset($cache[(int)$index]))$result[(int)$index]=$cache[(int)$index];
        }
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param list<array<string,mixed>> $consumerExports
     * @param array<int,array<string,mixed>> $cache
     * @param array<int,true> $visiting
     * @return array<string,mixed>
     */
    private static function resolveImportOutcome(int $index,array $imports,array $candidates,array $consumerExports,array &$cache,array &$visiting): array
    {
        if(isset($cache[$index]))return $cache[$index];
        if(isset($visiting[$index]))return $cache[$index]=self::outcome('invalid','import_parent_cycle',false,null);
        $import=$imports[$index]??null;
        if(!is_array($import))return $cache[$index]=self::outcome('invalid','import_index_unavailable',false,null);
        $visiting[$index]=true;
        if(self::hasNameNone($import)){
            unset($visiting[$index]);
            return $cache[$index]=self::outcome('ignored','name_none',false,null);
        }
        $outer=(int)($import['outer_index']??0);
        if($outer===0){
            unset($visiting[$index]);
            if(self::key((string)($import['class_name']??''))!==self::key('Package')
                ||self::key((string)($import['class_package']??''))!==self::key('Core')){
                return $cache[$index]=self::outcome('invalid','root_import_is_not_core_package',false,null);
            }
            return $cache[$index]=self::outcome('package_linker','top_level_package_linker',true,null);
        }
        if($outer>0){
            unset($visiting[$index]);
            return $cache[$index]=self::outcome('unresolved','cooked_export_outer_source_todo',false,null);
        }
        $parentIndex=-$outer-1;
        $parent=self::resolveImportOutcome($parentIndex,$imports,$candidates,$consumerExports,$cache,$visiting);
        $parentStatus=(string)($parent['status']??'');
        if(!in_array($parentStatus,['package_linker','resolved'],true)){
            unset($visiting[$index]);
            if(in_array($parentStatus,['private_export','invalid'],true)){
                return $cache[$index]=self::outcome('invalid','parent_verify_import_failed',false,null,['parent_import_index'=>$parentIndex]);
            }
            $reason=!empty($parent['source_linker'])
                ?'parent_runtime_context_unavailable'
                :'parent_source_linker_unavailable_tolerated';
            return $cache[$index]=self::outcome('runtime_only',$reason,(bool)($parent['source_linker']??false),null,['parent_import_index'=>$parentIndex]);
        }
        $parentSourceIndex=$parentStatus==='resolved'?(int)($parent['export_index']??-1):null;
        $direct=self::candidateOutcome(
            $index,$import,(string)($import['class_name']??''),(string)($import['class_package']??''),
            $parentSourceIndex,$imports,$consumerExports,$candidates,false
        );
        if(($direct['status']??'')==='resolved'||($direct['status']??'')==='private_export'||($direct['status']??'')==='runtime_only'){
            unset($visiting[$index]);
            return $cache[$index]=$direct;
        }
        if(self::key((string)($import['object_name']??''))!==self::key('ObjectRedirector')){
            $redir=self::candidateOutcome(
                $index,$import,'ObjectRedirector','Core',$parentSourceIndex,$imports,$consumerExports,$candidates,true
            );
            if(($redir['status']??'')==='resolved'){
                unset($visiting[$index]);
                return $cache[$index]=self::outcome(
                    'unresolved','object_redirector_target_unavailable',true,null,
                    ['redirector_index'=>(int)($redir['export_index']??-1)]
                );
            }
            if(($redir['status']??'')==='private_export'||($redir['status']??'')==='runtime_only'){
                unset($visiting[$index]);
                return $cache[$index]=$redir;
            }
        }
        unset($visiting[$index]);
        return $cache[$index]=self::outcome(
            'runtime_only','runtime_native_transient_findif_fail_or_missing_class_context',true,null
        );
    }

    /**
     * @param array<string,mixed> $import
     * @param array<int,array<string,mixed>> $imports
     * @param list<array<string,mixed>> $consumerExports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @return array<string,mixed>
     */
    private static function candidateOutcome(
        int $importIndex,array $import,string $className,string $classPackage,?int $parentSourceIndex,
        array $imports,array $consumerExports,array $candidates,bool $redirector
    ): array {
        $objectName=(string)($import['object_name']??'');
        $identity=self::identityKey($objectName,$className,$classPackage);
        foreach($candidates[$identity]??[]as$candidate){
            $sourceOuter=(int)($candidate['outer_index']??0);
            if($parentSourceIndex===null){if($sourceOuter!==0)continue;}
            elseif($sourceOuter!==$parentSourceIndex+1)continue;
            $exportIndex=(int)($candidate['export_index']??-1);
            if((((int)($candidate['object_flags']??0))&self::RF_PUBLIC)===0){
                if(self::privateImportIsReferenced($importIndex,$imports,$consumerExports)){
                    return self::outcome('private_export',$redirector?'redirector_private_export':'private_export',true,null,['candidate_export_index'=>$exportIndex]);
                }
                return self::outcome(
                    'runtime_only',
                    $redirector?'redirector_private_editor_safe_replace_context':'private_export_editor_safe_replace_context',
                    true,null,['candidate_export_index'=>$exportIndex]
                );
            }
            return self::outcome('resolved',$redirector?'object_redirector_match':'exact_verify_import_match',true,$exportIndex);
        }
        return self::outcome('not_found',$redirector?'object_redirector_not_found':'verify_import_not_found',true,null);
    }

    /** @param array<int,array<string,mixed>> $imports @param list<array<string,mixed>> $exports */
    private static function privateImportIsReferenced(int $importIndex,array $imports,array $exports): bool
    {
        $foundIndex=-($importIndex+1);
        foreach($exports as$export){
            if(!is_array($export))continue;
            foreach(['super_index','class_index','outer_index','archetype_index']as$field){
                if(array_key_exists($field,$export)&&(int)$export[$field]===$foundIndex)return true;
            }
        }
        foreach($imports as$import){
            if(is_array($import)&&(int)($import['outer_index']??0)===$foundIndex)return true;
        }
        return false;
    }

    /** @param array<string,mixed> $import */
    private static function hasNameNone(array $import): bool
    {
        foreach(['class_package','class_name','object_name']as$field){
            if(self::key((string)($import[$field]??''))===self::key('None'))return true;
        }
        return false;
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private static function outcome(string $status,string $reason,bool $sourceLinker,?int $exportIndex,array $detail=[]): array
    {
        return ['status'=>$status,'reason'=>$reason,'source_linker'=>$sourceLinker,'source_index'=>$exportIndex,'export_index'=>$exportIndex]+$detail;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $imports,
        array $candidates,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($importIndex, $resolved)) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $import = $imports[$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $objectName = (string)($import['object_name'] ?? '');
        $className = (string)($import['class_name'] ?? '');
        $classPackage = (string)($import['class_package'] ?? '');
        if ($objectName === '' || $className === '' || $classPackage === '') {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            // UE3 VerifyImportInner requires top-level package imports to be
            // exactly Core.Package. A malformed root must not establish the
            // SourceLinker anchor used to qualify descendant imports.
            if (self::key($className) !== self::key('Package')
                || self::key($classPackage) !== self::key('Core')) {
                return $resolved[$importIndex] = self::FAILURE_SENTINEL;
            }
            // Valid package imports establish SourceLinker but never SourceIndex.
            return $resolved[$importIndex] = null;
        }
        if ($outerIndex > 0) {
            // UE3 cooked packages can contain import->export outers. The audited
            // source explicitly returns here with a TODO instead of inventing a
            // provider-linker resolution path, so the catalog does the same.
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $visiting[$importIndex] = true;
        $parentImportIndex = -$outerIndex - 1;
        $parentSourceIndex = self::resolveImport(
            $parentImportIndex,
            $imports,
            $candidates,
            $resolved,
            $visiting
        );
        if ($parentSourceIndex === self::FAILURE_SENTINEL) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $identityKey = self::identityKey($objectName, $className, $classPackage);
        foreach ($candidates[$identityKey] ?? [] as $candidate) {
            if (self::identityKey(
                (string)($candidate['object_name'] ?? ''),
                (string)($candidate['class_name'] ?? ''),
                (string)($candidate['class_package'] ?? '')
            ) !== $identityKey) {
                continue;
            }

            $sourceOuter = (int)($candidate['outer_index'] ?? 0);
            if ($parentSourceIndex === null) {
                if ($sourceOuter !== 0) {
                    continue;
                }
            } elseif ($sourceOuter !== $parentSourceIndex + 1) {
                continue;
            }

            if ((((int)($candidate['object_flags'] ?? 0)) & self::RF_PUBLIC) === 0) {
                unset($visiting[$importIndex]);
                return $resolved[$importIndex] = self::FAILURE_SENTINEL;
            }

            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = (int)$candidate['export_index'];
        }

        unset($visiting[$importIndex]);
        // Only a valid top-level Core.Package import may carry SourceLinker with
        // SourceIndex == INDEX_NONE. Any unresolved non-root import must remain
        // a failure so descendants cannot be mistaken for root-level exports.
        return $resolved[$importIndex] = self::FAILURE_SENTINEL;
    }

    /**
     * Load UE3 provider candidates from authoritative UEDB5.
     *
     * @param array<string,string> $objectNames normalized name => original name
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadUedb5Candidates(
        PDO $db,
        int $providerFileId,
        array $objectNames,
        string $storageRoot
    ): array {
        if ($objectNames === []) {
            return [];
        }
        $snapshot = (new Uedb5RuntimeProviderSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $providerImports = self::indexedImports((array)($snapshot['imports'] ?? []));
        $providerImports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap(
            $providerImports
        );
        $providerExports = [];
        foreach ((array)($snapshot['exports'] ?? []) as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['export_index']) ? (int)$row['export_index']
                : (isset($row['index']) ? (int)$row['index'] : (int)$fallback);
            $providerExports[$index] = $row;
        }
        $file = (array)($snapshot['file'] ?? []);
        $summary = (array)($snapshot['summary'] ?? []);
        $providerPackageName = (string)($file['package_name'] ?? '');
        $providerPackageVersion = array_key_exists('package_version',$summary)
            ? (int)$summary['package_version']
            : (isset($file['package_version']) ? (int)$file['package_version'] : null);

        $result = [];
        foreach ($providerExports as $exportIndex => $export) {
            $objectName = (string)($export['object_name'] ?? '');
            if ($objectName === '' || !isset($objectNames[self::key($objectName)])) {
                continue;
            }
            [$classPackage, $className] =
                \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $export,
                    $providerImports,
                    $providerExports,
                    $providerPackageName,
                    $providerPackageVersion,
                    true
                );
            $identityKey = self::identityKey($objectName, $className, $classPackage);
            $result[$identityKey][] = [
                'export_index' => (int)$exportIndex,
                'object_name' => $objectName,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'class_package' => $classPackage,
                'class_name' => $className,
            ];
        }
        foreach ($result as &$rows) {
            usort($rows, static fn(array $left,array $right):int =>
                (int)$right['export_index'] <=> (int)$left['export_index']
            );
        }
        unset($rows);
        return $result;
    }

    private static function storageRoot(?string $storageRoot): string
    {
        $storageRoot = trim((string)$storageRoot);
        if ($storageRoot !== '') {
            return $storageRoot;
        }
        if (!function_exists('catalog_config')) {
            throw new \RuntimeException('Catalog configuration is required for authoritative UE3 VerifyImport resolution.');
        }
        $config = \catalog_config();
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new \RuntimeException('Catalog storage_path is required for authoritative UE3 VerifyImport resolution.');
        }
        return $storageRoot;
    }

    /** @param array<int,array<string,mixed>> $imports @param list<int> $targets @return list<int> */
    private static function importClosure(array $imports, array $targets): array
    {
        $needed = [];
        foreach ($targets as $target) {
            $index = (int)$target;
            $seen = [];
            while (isset($imports[$index]) && !isset($seen[$index])) {
                $seen[$index] = true;
                $needed[$index] = true;
                $outer = (int)($imports[$index]['outer_index'] ?? 0);
                if ($outer >= 0) {
                    break;
                }
                $index = -$outer - 1;
            }
        }
        return array_map('intval', array_keys($needed));
    }

    /** @param array<int,array<string,mixed>> $imports @param list<int> $indexes @return array<string,string> */
    private static function objectNamesForImports(array $imports, array $indexes): array
    {
        $names = [];
        foreach ($indexes as $index) {
            $row = $imports[(int)$index] ?? null;
            if (!is_array($row) || (int)($row['outer_index'] ?? 0) === 0) {
                continue;
            }
            $name = (string)($row['object_name'] ?? '');
            if ($name !== '') {
                $names[self::key($name)] = $name;
            }
        }
        return $names;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param list<int> $targets
     * @return array<int,int>
     */
    private static function resolveTargetImports(array $imports, array $candidates, array $targets): array
    {
        $resolved = [];
        $visiting = [];
        foreach ($targets as $importIndex) {
            self::resolveImport((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }
        $matches = [];
        foreach ($targets as $importIndex) {
            $exportIndex = $resolved[(int)$importIndex] ?? self::FAILURE_SENTINEL;
            if ($exportIndex !== null && $exportIndex !== self::FAILURE_SENTINEL) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $first
     * @param array<string,list<array<string,mixed>>> $second
     * @return array<string,list<array<string,mixed>>>
     */
    private static function mergeCandidates(array $first, array $second): array
    {
        foreach ($second as $key => $rows) {
            $byExport = [];
            foreach (array_merge($first[$key] ?? [], $rows) as $row) {
                $byExport[(int)($row['export_index'] ?? -1)] = $row;
            }
            $merged = array_values($byExport);
            usort($merged, static fn(array $a, array $b): int => (int)$b['export_index'] <=> (int)$a['export_index']);
            $first[$key] = $merged;
        }
        return $first;
    }

    private static function identityKey(string $objectName, string $className, string $classPackage): string
    {
        return CatalogUnrealIdentityHash::verifyImportHex($objectName, $className, $classPackage);
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::fnameKey($value);
    }
}
