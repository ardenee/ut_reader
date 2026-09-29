<?php
/** Dependency identity diagnostics used by catalog admin/file views. */
declare(strict_types=1);

/** @return list<array<string,mixed>> */
function catalog_dependency_provider_candidates(PDO $db,int $gameId,int $consumerFileId,string $packageName):array
{
    $packageName=trim($packageName);if($gameId<1||$packageName==='')return[];$rows=[];
    try{$s=$db->prepare('SELECT p.file_id,p.source_kind,f.package_name,f.original_name FROM ue_package_providers p JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias" AND a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id AND a.package_name=p.package_name WHERE p.game_id=? AND p.package_name=? AND f.scan_status="verified" AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) AND ((p.source_kind="primary" AND f.package_name=p.package_name) OR (p.source_kind="alias" AND a.id IS NOT NULL)) ORDER BY (p.source_kind="primary") DESC,(p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC');$s->execute([$gameId,$packageName,$consumerFileId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){$rows=[];}
    if($rows===[]){$s=$db->prepare('SELECT id file_id,"primary" source_kind,package_name,original_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_name=? AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=ue_files.file_size AND bad.md5=LOWER(ue_files.md5) AND bad.sha1=LOWER(ue_files.sha1)) ORDER BY (id=?) DESC,uploaded_at DESC');$s->execute([$gameId,$packageName,$consumerFileId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    if($rows===[]){$s=$db->prepare('SELECT a.file_id,"alias" source_kind,f.package_name,f.original_name FROM ue_file_package_aliases a JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id WHERE a.game_id=? AND a.package_name=? AND f.scan_status="verified" AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) ORDER BY (f.id=?) DESC,f.uploaded_at DESC,a.id ASC');$s->execute([$gameId,$packageName,$consumerFileId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    $out=[];$seen=[];foreach($rows as $row){$id=(int)($row['file_id']??0);if($id<1||isset($seen[$id]))continue;$seen[$id]=true;$out[]=$row;}return$out;
}

function catalog_dependency_provider_engine(PDO $db,int $providerFileId):string
{
    if($providerFileId<1)return'';
    $s=$db->prepare('SELECT UPPER(TRIM(p.engine_key)) engine_key FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE f.id=? LIMIT 1');
    $s->execute([$providerFileId]);
    return strtoupper(trim((string)($s->fetchColumn()?:'')));
}

/** @return list<array<string,mixed>> */
function catalog_dependency_export_candidates(PDO $db,array $dependency,?int $providerFileId=null):array
{
    $providerId=$providerFileId??(int)($dependency['resolved_id']??$dependency['resolved_file_id']??0);
    $objectName=trim((string)($dependency['import_object_name']??''));
    if($providerId<1||$objectName==='')return[];
    $engine=catalog_dependency_provider_engine($db,$providerId);
    if($engine==='UE3'){
        $s=$db->prepare('SELECT e.export_index,l.outer_index,l.object_flags,ot.value_prefix object_name,cnt.value_prefix class_name,cpt.value_prefix class_package FROM ue_export_lookup e JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index JOIN ue_terms ot ON ot.id=e.object_term_id LEFT JOIN ue_terms cnt ON cnt.id=l.class_name_term_id LEFT JOIN ue_terms cpt ON cpt.id=l.class_package_term_id WHERE e.file_id=? AND LOWER(CONVERT(ot.value_prefix USING utf8mb4))=LOWER(?) ORDER BY e.export_index DESC LIMIT 20');
    }else{
        $s=$db->prepare('SELECT l.export_index,l.outer_index,l.object_flags,ot.value_prefix object_name,ct.value_prefix class_name,pt.value_prefix class_package FROM ue_legacy_export_identity_lookup l JOIN ue_terms ot ON ot.id=l.object_term_id JOIN ue_terms ct ON ct.id=l.class_name_term_id JOIN ue_terms pt ON pt.id=l.class_package_term_id WHERE l.file_id=? AND LOWER(ot.value_prefix)=LOWER(?) ORDER BY l.export_index DESC LIMIT 20');
    }
    $s->execute([$providerId,$objectName]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as &$row)$row['_engine_key']=$engine;unset($row);return$rows;
}

/** Resolve an export's serialized outer chain to a human-readable provider path. */
function catalog_dependency_export_outer_path(PDO $db,int $providerFileId,int $outerIndex,string $providerPackage):string
{
    if($outerIndex===0)return trim($providerPackage);
    $engine=catalog_dependency_provider_engine($db,$providerFileId);
    $parts=[];$seen=[];$current=$outerIndex;$guard=0;
    while($current>0&&$guard++<64){
        $exportIndex=$current-1;if(isset($seen[$exportIndex]))break;$seen[$exportIndex]=true;
        if($engine==='UE3')$s=$db->prepare('SELECT l.outer_index,ot.value_prefix object_name FROM ue_export_lookup e JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index JOIN ue_terms ot ON ot.id=e.object_term_id WHERE e.file_id=? AND e.export_index=? LIMIT 1');
        else $s=$db->prepare('SELECT l.outer_index,ot.value_prefix object_name FROM ue_legacy_export_identity_lookup l JOIN ue_terms ot ON ot.id=l.object_term_id WHERE l.file_id=? AND l.export_index=? LIMIT 1');
        $s->execute([$providerFileId,$exportIndex]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)){$parts[]='[export #'.$exportIndex.']';break;}
        array_unshift($parts,(string)$row['object_name']);$current=(int)$row['outer_index'];
    }
    $prefix=trim($providerPackage);return implode('.',array_values(array_filter(array_merge([$prefix],$parts),static fn(string $v):bool=>$v!=='')));
}

/** Return the exact values useful for a visible VerifyImport comparison. */
function catalog_dependency_candidate_summary(array $dependency,array $candidate,?string $candidateOuterPath=null):array
{
    $wantedObject=trim((string)($dependency['import_object_name']??''));$wantedClassPackage=trim((string)($dependency['import_class_package']??''));$wantedClassName=trim((string)($dependency['import_class_name']??''));$requiredPath=trim((string)($dependency['required_object_path']??''));$requiredPackage=trim((string)($dependency['required_package']??''));
    $dot=strrpos($requiredPath,'.');$wantedOuter=$dot===false?$requiredPackage:substr($requiredPath,0,$dot);if($wantedOuter==='')$wantedOuter=$requiredPackage;
    $actualObject=trim((string)($candidate['object_name']??''));$actualClassPackage=trim((string)($candidate['class_package']??''));$actualClassName=trim((string)($candidate['class_name']??''));$actualOuter=trim((string)($candidateOuterPath??''));$flags=(int)($candidate['object_flags']??0);$engine=strtoupper(trim((string)($candidate['_engine_key']??'')));
    $objectMatch=strcasecmp($wantedObject,$actualObject)===0;$classPackageMatch=$wantedClassPackage===''||strcasecmp($wantedClassPackage,$actualClassPackage)===0;$classNameMatch=$wantedClassName===''||strcasecmp($wantedClassName,$actualClassName)===0;$outerMatch=$actualOuter!==''&&strcasecmp($wantedOuter,$actualOuter)===0;
    $rfPublic=$engine==='UE3'?0x0000000400000000:($engine==='UE4'?0x00000001:0x00000004);$public=($flags&$rfPublic)!==0;$width=$engine==='UE3'?16:8;$differences=[];if(!$objectMatch)$differences[]='object';if(!$classPackageMatch||!$classNameMatch)$differences[]='class';if(!$outerMatch)$differences[]='outer';if(!$public)$differences[]='private export';
    return['wanted_object'=>$wantedObject,'actual_object'=>$actualObject,'object_match'=>$objectMatch,'wanted_class_package'=>$wantedClassPackage,'actual_class_package'=>$actualClassPackage,'class_package_match'=>$classPackageMatch,'wanted_class_name'=>$wantedClassName,'actual_class_name'=>$actualClassName,'class_name_match'=>$classNameMatch,'wanted_outer_path'=>$wantedOuter,'actual_outer_path'=>$actualOuter,'outer_match'=>$outerMatch,'actual_outer'=>(int)($candidate['outer_index']??0),'actual_outer_index'=>(int)($candidate['outer_index']??0),'flags_hex'=>'0x'.strtoupper(str_pad(dechex($flags),$width,'0',STR_PAD_LEFT)),'public'=>$public,'differences'=>$differences];
}
