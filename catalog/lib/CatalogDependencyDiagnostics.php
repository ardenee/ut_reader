<?php
/** Dependency identity diagnostics used by catalog admin/file views. */
declare(strict_types=1);

/**
 * Locate every verified physical provider for the dependency's required package.
 * This deliberately does NOT use resolved_file_id: unresolved object imports have
 * no resolved_file_id even when the physical package exists.
 *
 * @return list<array<string,mixed>>
 */
function catalog_dependency_provider_candidates(PDO $db, int $gameId, int $consumerFileId, string $packageName): array
{
    $packageName = trim($packageName);
    if ($gameId < 1 || $packageName === '') return [];
    $rows = [];
    try {
        $statement = $db->prepare(
            'SELECT p.file_id,p.source_kind,f.package_name,f.original_name'
            . ' FROM ue_package_providers p'
            . ' JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id'
            . ' LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias" AND a.id=p.source_id'
            . ' AND a.file_id=p.file_id AND a.game_id=p.game_id AND a.package_name=p.package_name'
            . ' WHERE p.game_id=? AND p.package_name=? AND f.scan_status="verified"'
            . ' AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))'
            . ' AND ((p.source_kind="primary" AND f.package_name=p.package_name) OR (p.source_kind="alias" AND a.id IS NOT NULL))'
            . ' ORDER BY (p.source_kind="primary") DESC,(p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC'
        );
        $statement->execute([$gameId,$packageName,$consumerFileId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
    }
    // Match the resolver's compatibility fallbacks when the provider projection is absent/incomplete.
    if ($rows === []) {
        $statement = $db->prepare('SELECT id file_id,"primary" source_kind,package_name,original_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_name=? AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=ue_files.file_size AND bad.md5=LOWER(ue_files.md5) AND bad.sha1=LOWER(ue_files.sha1)) ORDER BY (id=?) DESC,uploaded_at DESC');
        $statement->execute([$gameId,$packageName,$consumerFileId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($rows === []) {
        $statement = $db->prepare('SELECT a.file_id,"alias" source_kind,f.package_name,f.original_name FROM ue_file_package_aliases a JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id WHERE a.game_id=? AND a.package_name=? AND f.scan_status="verified" AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) ORDER BY (f.id=?) DESC,f.uploaded_at DESC,a.id ASC');
        $statement->execute([$gameId,$packageName,$consumerFileId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $out=[];$seen=[];foreach($rows as $row){$id=(int)($row['file_id']??0);if($id<1||isset($seen[$id]))continue;$seen[$id]=true;$out[]=$row;}return $out;
}

/** @return list<array<string,mixed>> */
function catalog_dependency_export_candidates(PDO $db, array $dependency, ?int $providerFileId=null): array
{
    $providerId = $providerFileId ?? (int)($dependency['resolved_id'] ?? $dependency['resolved_file_id'] ?? 0);
    $objectName = trim((string)($dependency['import_object_name'] ?? ''));
    if ($providerId < 1 || $objectName === '') return [];
    $statement = $db->prepare('SELECT l.export_index,l.outer_index,l.object_flags,ot.value_prefix object_name,ct.value_prefix class_name,pt.value_prefix class_package FROM ue_legacy_export_identity_lookup l JOIN ue_terms ot ON ot.id=l.object_term_id JOIN ue_terms ct ON ct.id=l.class_name_term_id JOIN ue_terms pt ON pt.id=l.class_package_term_id WHERE l.file_id=? AND LOWER(ot.value_prefix)=LOWER(?) ORDER BY l.export_index DESC LIMIT 20');
    $statement->execute([$providerId,$objectName]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function catalog_dependency_candidate_summary(array $dependency,array $candidate):array
{
    $wantedClassPackage=trim((string)($dependency['import_class_package']??''));$wantedClassName=trim((string)($dependency['import_class_name']??''));$wantedOuter=(int)($dependency['import_outer_index']??0);$actualClassPackage=trim((string)($candidate['class_package']??''));$actualClassName=trim((string)($candidate['class_name']??''));$actualOuter=(int)($candidate['outer_index']??0);$flags=(int)($candidate['object_flags']??0);$differences=[];
    if($wantedClassPackage!==''&&strcasecmp($wantedClassPackage,$actualClassPackage)!==0)$differences[]='wanted class package '.$wantedClassPackage.', candidate has '.$actualClassPackage;
    if($wantedClassName!==''&&strcasecmp($wantedClassName,$actualClassName)!==0)$differences[]='wanted class '.$wantedClassName.', candidate has '.$actualClassName;
    return['differences'=>$differences,'wanted_outer'=>$wantedOuter,'actual_outer'=>$actualOuter,'flags_hex'=>'0x'.strtoupper(str_pad(dechex($flags&0xFFFFFFFF),8,'0',STR_PAD_LEFT)),'public'=>($flags&0x00000004)!==0];
}
