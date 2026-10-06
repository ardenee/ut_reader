<?php
/**
 * Resolves UE3 Imports against the v4 export projection using the deterministic
 * file-backed portion of ULinkerLoad::VerifyImportInner.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

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
        $sourceBacked = trim((string)$storageRoot) !== '' && self::isUt3Provider($db, $providerFileId);
        $candidates = $sourceBacked
            ? self::loadSourceCandidates($db, $providerFileId, $objectNames, (string)$storageRoot)
            : self::loadCandidates($db, $providerFileId, $objectNames);
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
        $fallback = $sourceBacked
            ? self::loadSourceCaseInsensitiveCandidates($db, $providerFileId, $fallbackNames, (string)$storageRoot)
            : self::loadCaseInsensitiveCandidates($db, $providerFileId, $fallbackNames);
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
        $sourceBacked = trim((string)$storageRoot) !== '' && self::isUt3Provider($db, $providerFileId);
        $candidates = $sourceBacked
            ? self::loadSourceCandidates($db, $providerFileId, $objectNames, (string)$storageRoot)
            : self::loadCandidates($db, $providerFileId, $objectNames);
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
        $fallback = $sourceBacked
            ? self::loadSourceCaseInsensitiveCandidates($db, $providerFileId, $fallbackNames, (string)$storageRoot)
            : self::loadCaseInsensitiveCandidates($db, $providerFileId, $fallbackNames);
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

    private static function isUt3Provider(PDO $db, int $providerFileId): bool
    {
        $statement = $db->prepare(
            'SELECT LOWER(TRIM(g.slug)) FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=? LIMIT 1'
        );
        $statement->execute([$providerFileId]);
        return (string)($statement->fetchColumn() ?: '') === 'ut3';
    }

    /**
     * Discover candidate export indexes through the indexed SQL accelerator, then
     * obtain the source-semantic class/outer/flag fields from authoritative UEDB4.
     *
     * @param array<string,string> $objectNames
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadSourceCandidates(
        PDO $db,
        int $providerFileId,
        array $objectNames,
        string $storageRoot
    ): array {
        $termNames = self::loadExactObjectNameTerms($db, $objectNames);
        if ($termNames === []) {
            return [];
        }
        $refs = [];
        foreach (array_chunk($termNames, self::HASH_BATCH_SIZE, true) as $chunk) {
            $termIds = array_map('intval', array_keys($chunk));
            $statement = $db->prepare(
                'SELECT export_index,object_term_id FROM ue_export_lookup '
                . 'WHERE file_id=? AND object_term_id IN ('
                . implode(',', array_fill(0, count($termIds), '?')) . ') '
                . 'ORDER BY export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $termIds));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objectName = (string)($chunk[(int)$row['object_term_id']] ?? '');
                if ($objectName !== '' && isset($objectNames[self::key($objectName)])) {
                    $refs[] = [
                        'export_index' => (int)$row['export_index'],
                        'object_name' => $objectName,
                    ];
                }
            }
        }
        return self::hydrateSourceCandidates($db, $providerFileId, $refs, $storageRoot);
    }

    /** @param array<string,string> $objectNames @return array<string,list<array<string,mixed>>> */
    private static function loadSourceCaseInsensitiveCandidates(
        PDO $db,
        int $providerFileId,
        array $objectNames,
        string $storageRoot
    ): array {
        if ($objectNames === []) {
            return [];
        }
        $refs = [];
        foreach (array_chunk(array_values($objectNames), self::HASH_BATCH_SIZE) as $chunk) {
            $statement = $db->prepare(
                'SELECT e.export_index,ot.value_prefix object_name FROM ue_export_lookup e '
                . 'JOIN ue_terms ot ON ot.id=e.object_term_id '
                . 'WHERE e.file_id=? AND CONVERT(ot.value_prefix USING utf8mb4) '
                . 'COLLATE utf8mb4_unicode_ci IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ') '
                . 'ORDER BY e.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $chunk));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objectName = (string)($row['object_name'] ?? '');
                if ($objectName !== '' && isset($objectNames[self::key($objectName)])) {
                    $refs[] = [
                        'export_index' => (int)$row['export_index'],
                        'object_name' => $objectName,
                    ];
                }
            }
        }
        return self::hydrateSourceCandidates($db, $providerFileId, $refs, $storageRoot);
    }

    /**
     * @param list<array{export_index:int,object_name:string}> $refs
     * @return array<string,list<array<string,mixed>>>
     */
    private static function hydrateSourceCandidates(
        PDO $db,
        int $providerFileId,
        array $refs,
        string $storageRoot
    ): array {
        if ($refs === []) {
            return [];
        }
        $provider = $db->prepare(
            'SELECT package_name,package_version FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1'
        );
        $provider->execute([$providerFileId]);
        $providerRow = $provider->fetch(PDO::FETCH_ASSOC);
        if (!is_array($providerRow)) {
            return [];
        }

        $reader = new \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader(
            $db,
            $storageRoot
        );
        $candidateIndexes = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['export_index'],
            $refs
        )));
        $candidateExports = $reader->rowsByIndexes($providerFileId, 'exports', $candidateIndexes);
        if ($candidateExports === []) {
            return [];
        }

        $classImportIndexes = [];
        $classExportIndexes = [];
        foreach ($candidateExports as $export) {
            $classIndex = (int)($export['class_index'] ?? 0);
            if ($classIndex < 0) {
                $classImportIndexes[-$classIndex - 1] = true;
            } elseif ($classIndex > 0) {
                $classExportIndexes[$classIndex - 1] = true;
            }
        }
        $providerImports = $reader->rowsByIndexes(
            $providerFileId,
            'imports',
            array_map('intval', array_keys($classImportIndexes))
        );
        $outerImportIndexes = [];
        foreach ($providerImports as $import) {
            $outer = (int)($import['outer_index'] ?? 0);
            if ($outer < 0) {
                $outerImportIndexes[-$outer - 1] = true;
            }
        }
        if ($outerImportIndexes !== []) {
            $providerImports += $reader->rowsByIndexes(
                $providerFileId,
                'imports',
                array_map('intval', array_keys($outerImportIndexes))
            );
        }
        $providerImports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap(
            $providerImports
        );

        $identityExports = $candidateExports;
        if ($classExportIndexes !== []) {
            $identityExports += $reader->rowsByIndexes(
                $providerFileId,
                'exports',
                array_map('intval', array_keys($classExportIndexes))
            );
        }

        $refsByIndex = [];
        foreach ($refs as $ref) {
            $refsByIndex[(int)$ref['export_index']] = (string)$ref['object_name'];
        }
        $result = [];
        foreach ($candidateIndexes as $exportIndex) {
            $export = $candidateExports[$exportIndex] ?? null;
            if (!is_array($export)) {
                continue;
            }
            $objectName = (string)($export['object_name'] ?? '');
            $discoveredName = (string)($refsByIndex[$exportIndex] ?? '');
            if ($objectName === '' || $discoveredName === '' || self::key($objectName) !== self::key($discoveredName)) {
                continue;
            }
            [$classPackage, $className] =
                \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $export,
                    $providerImports,
                    $identityExports,
                    (string)$providerRow['package_name'],
                    isset($providerRow['package_version']) ? (int)$providerRow['package_version'] : null,
                    true
                );
            $identityKey = self::identityKey($objectName, $className, $classPackage);
            $result[$identityKey][] = [
                'export_index' => $exportIndex,
                'object_name' => $objectName,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'class_package' => $classPackage,
                'class_name' => $className,
            ];
        }
        foreach ($result as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);
        return $result;
    }

    /**
     * Load UE3 candidates by serialized ObjectName, mirroring the first key of
     * VerifyImportInner. The fast path resolves exact ue_terms IDs through the
     * dictionary hash index, then probes ue_export_lookup by object_term_id.
     *
     * @param array<string,string> $objectNames normalized name => original name
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadCandidates(PDO $db, int $providerFileId, array $objectNames): array
    {
        if ($objectNames === []) {
            return [];
        }
        return self::loadCandidatesForTerms(
            $db,
            $providerFileId,
            $objectNames,
            self::loadExactObjectNameTerms($db, $objectNames)
        );
    }

    /** @param array<string,string> $objectNames @return array<int,string> */
    private static function loadExactObjectNameTerms(PDO $db, array $objectNames): array
    {
        $result = [];
        foreach (array_chunk(array_values($objectNames), self::HASH_BATCH_SIZE) as $chunk) {
            $predicates = [];
            $arguments = [];
            $expected = [];
            foreach ($chunk as $name) {
                $hash = md5($name, true);
                $length = strlen($name);
                $predicates[] = '(value_hash=? AND value_length=?)';
                $arguments[] = $hash;
                $arguments[] = $length;
                $expected[bin2hex($hash) . ':' . $length] = $name;
            }
            $statement = $db->prepare(
                'SELECT id,value_hash,value_length FROM ue_terms WHERE ' . implode(' OR ', $predicates)
            );
            $statement->execute($arguments);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $key = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
                if (isset($expected[$key])) {
                    $result[(int)$row['id']] = $expected[$key];
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string,string> $objectNames
     * @param array<int,string> $termNames
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadCandidatesForTerms(
        PDO $db,
        int $providerFileId,
        array $objectNames,
        array $termNames
    ): array {
        if ($termNames === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($termNames, self::HASH_BATCH_SIZE, true) as $chunk) {
            $termIds = array_map('intval', array_keys($chunk));
            $placeholders = implode(',', array_fill(0, count($termIds), '?'));
            $statement = $db->prepare(
                'SELECT e.export_index,e.object_term_id,l.outer_index,l.object_flags,'
                . 'cpt.value_prefix class_package,cnt.value_prefix class_name'
                . ' FROM ue_export_lookup e'
                . ' JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index'
                . ' LEFT JOIN ue_terms cpt ON cpt.id=l.class_package_term_id'
                . ' LEFT JOIN ue_terms cnt ON cnt.id=l.class_name_term_id'
                . ' WHERE e.file_id=? AND e.object_term_id IN (' . $placeholders . ')'
                . ' ORDER BY e.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $termIds));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objectName = (string)($chunk[(int)$row['object_term_id']] ?? '');
                if ($objectName === '' || !isset($objectNames[self::key($objectName)])) {
                    continue;
                }
                self::appendCandidate($result, $row, $objectName);
            }
        }
        return $result;
    }

    /** @param array<string,string> $objectNames @return array<string,list<array<string,mixed>>> */
    private static function loadCaseInsensitiveCandidates(PDO $db, int $providerFileId, array $objectNames): array
    {
        if ($objectNames === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk(array_values($objectNames), self::HASH_BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $db->prepare(
                'SELECT e.export_index,ot.value_prefix object_name,l.outer_index,l.object_flags,'
                . 'cpt.value_prefix class_package,cnt.value_prefix class_name'
                . ' FROM ue_export_lookup e'
                . ' JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index'
                . ' JOIN ue_terms ot ON ot.id=e.object_term_id'
                . ' LEFT JOIN ue_terms cpt ON cpt.id=l.class_package_term_id'
                . ' LEFT JOIN ue_terms cnt ON cnt.id=l.class_name_term_id'
                . ' WHERE e.file_id=? AND CONVERT(ot.value_prefix USING utf8mb4)'
                . ' COLLATE utf8mb4_unicode_ci IN (' . $placeholders . ')'
                . ' ORDER BY e.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $chunk));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objectName = (string)($row['object_name'] ?? '');
                if ($objectName === '' || !isset($objectNames[self::key($objectName)])) {
                    continue;
                }
                self::appendCandidate($result, $row, $objectName);
            }
        }
        return $result;
    }

    /** @param array<string,list<array<string,mixed>>> $result @param array<string,mixed> $row */
    private static function appendCandidate(array &$result, array $row, string $objectName): void
    {
        $className = (string)($row['class_name'] ?? '');
        $classPackage = (string)($row['class_package'] ?? '');
        $identityKey = self::identityKey($objectName, $className, $classPackage);
        $result[$identityKey][] = [
            'export_index' => (int)$row['export_index'],
            'object_name' => $objectName,
            'outer_index' => (int)($row['outer_index'] ?? 0),
            'object_flags' => (int)($row['object_flags'] ?? 0),
            'class_package' => $classPackage,
            'class_name' => $className,
        ];
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
