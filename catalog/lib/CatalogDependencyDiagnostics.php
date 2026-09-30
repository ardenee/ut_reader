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
    if($engine==='UE3'||$engine==='UE4'){
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
        if($engine==='UE3'||$engine==='UE4')$s=$db->prepare('SELECT l.outer_index,ot.value_prefix object_name FROM ue_export_lookup e JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index JOIN ue_terms ot ON ot.id=e.object_term_id WHERE e.file_id=? AND e.export_index=? LIMIT 1');
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


/** Human-readable labels for source-backed dependency evidence. */
function catalog_dependency_evidence_reason_label(string $reason):string
{
    return match($reason){
        'no_package_provider'=>'No verified package provider',
        'object_name_not_found'=>'Object name not present',
        'class_name_mismatch'=>'Class name mismatch',
        'class_package_mismatch'=>'Class package mismatch',
        'outer_mismatch'=>'Serialized outer mismatch',
        'private_export_rejected'=>'Matching export is private',
        'outer_import_unresolved'=>'Serialized outer Import did not resolve',
        'object_redirector_target_unavailable'=>'ObjectRedirector destination payload unavailable',
        'object_redirector_ancestor_target_unavailable'=>'Outer ObjectRedirector destination payload unavailable',
        'v4_package_context_unavailable'=>'UEDB4 PackageName context unavailable',
        'exact_match'=>'Current resolver finds an exact match',
        'compact_not_missing'=>'UEDB4 does not classify this Import as missing',
        'compact_dependency_missing'=>'UEDB4 dependency row is unavailable',
        'sql_dependency_missing'=>'SQL dependency row is unavailable',
        'unclassified'=>'Unclassified VerifyImport rejection',
        default=>$reason!==''?$reason:'Unknown rejection',
    };
}

/** Explain what the evidence means without inventing a fallback not present in UE4 source. */
function catalog_dependency_evidence_reason_explanation(string $reason,array $detail,array $target):string
{
    $object=(string)($target['object_name']??'');$class=(string)($target['class_name']??'');
    return match($reason){
        'no_package_provider'=>'No verified game-local physical package provider exists for the serialized package identity.',
        'object_name_not_found'=>'The provider export table contains no export named '.$object.'. UE4 VerifyImport cannot resolve this serialized Import from that provider.',
        'class_name_mismatch'=>'An export named '.$object.' exists, but none has the serialized Import.ClassName '.$class.'.',
        'class_package_mismatch'=>'ObjectName/ClassName candidates exist, but they fail UE4 full-ClassPackage matching and its source-defined short-package fallback.',
        'outer_mismatch'=>'Object/class candidates exist, but none has the SourceIndex-derived serialized outer required by VerifyImportInner.',
        'private_export_rejected'=>'Matching export #'.(int)($detail['candidate_export_index']??-1).' exists, but RF_Public is not set and none of UE4 4.27.2 editor consumer-graph exceptions allows the private import.',
        'outer_import_unresolved'=>'UE4 verifies the serialized outer Import first; Import #'.(int)($detail['blocked_by_import_index']??-1).' did not resolve, so this child cannot be accepted.',
        'object_redirector_target_unavailable'=>'UE4 reaches ObjectRedirector export #'.(int)($detail['redirector_export_index']??-1).', but DestinationObject requires export payload state. This is payload-unresolved, not proven missing.',
        'object_redirector_ancestor_target_unavailable'=>'An outer Import reaches ObjectRedirector; UE4 needs DestinationObject before descendant resolution can continue. This is payload-unresolved, not proven missing.',
        'v4_package_context_unavailable'=>'The deterministic UE4 branch requires FObjectImport::PackageName, which UEDB4 does not retain. This is metadata-unresolved, not proven missing.',
        'exact_match'=>'The current UE4 resolver finds this Import at export #'.(int)($detail['export_index']??-1).' in the selected provider. A persisted missing row is therefore suspicious and should be rebuilt/investigated.',
        'compact_not_missing'=>'The SQL missing projection disagrees with the UEDB4 dependency row. The dependency is not proven missing until that projection mismatch is resolved.',
        'compact_dependency_missing'=>'The compact snapshot has no dependency row for this serialized Import. Treat this as metadata integrity work, not proof of a missing dependency.',
        'sql_dependency_missing'=>'The SQL dependency projection has no row for this serialized Import. Treat this as projection integrity work, not proof of a missing dependency.',
        default=>'The current UE4 resolver rejected this Import, but the diagnostic reason is not yet classified.',
    };
}

/** Follow the serialized UE4 outer chain for visible evidence. */
function catalog_ue4_import_outer_chain(array $imports,array $exports,int $importIndex):array
{
    $ib=[];$eb=[];foreach($imports as $fallback=>$row){if(!is_array($row))continue;$i=isset($row['import_index'])?(int)$row['import_index']:(int)$fallback;$ib[$i]=$row;}
    foreach($exports as $fallback=>$row){if(!is_array($row))continue;$i=isset($row['export_index'])?(int)$row['export_index']:(int)$fallback;$eb[$i]=$row;}
    $out=[];$kind='import';$index=$importIndex;$seen=[];
    for($guard=0;$guard<64;$guard++){
        $key=$kind.':'.$index;if(isset($seen[$key])){$out[]=['kind'=>'cycle','index'=>$index];break;}$seen[$key]=true;
        $row=$kind==='import'?($ib[$index]??null):($eb[$index]??null);if(!is_array($row)){$out[]=['kind'=>'missing','index'=>$index];break;}
        $out[]=['kind'=>$kind,'index'=>$index,'object_name'=>(string)($row['object_name']??''),'class_package'=>(string)($row['class_package']??''),'class_name'=>(string)($row['class_name']??''),'outer_index'=>(int)($row['outer_index']??0)];
        $outer=(int)($row['outer_index']??0);if($outer===0)break;
        if($outer<0){$kind='import';$index=-$outer-1;}else{$kind='export';$index=$outer-1;}
    }
    return$out;
}

/**
 * Replay authoritative UE4 VerifyImport evidence for one persisted missing Import.
 * @return array<string,mixed>
 */
function catalog_ue4_missing_import_evidence(PDO $db,string $storageRoot,int $gameId,int $fileId,int $importIndex,string $requiredPackage):array
{
    $requiredPackage=trim($requiredPackage);
    $loader=new \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader($db,$storageRoot);
    $snapshot=$loader->loadDependencySnapshot($fileId,true);$imports=array_values((array)($snapshot['imports']??[]));$exports=array_values((array)($snapshot['exports']??[]));
    $target=null;$required=[];foreach($imports as $fallback=>$row){if(!is_array($row))continue;$idx=isset($row['import_index'])?(int)$row['import_index']:(int)$fallback;if($idx===$importIndex)$target=$row;if(strcasecmp(trim((string)($row['root_package']??'')),$requiredPackage)===0&&trim((string)($row['relative_object_path']??''))!=='')$required[]=$idx;}
    if(!is_array($target))throw new RuntimeException('Selected UE4 Import is not present in compact metadata.');
    $sqlStatus=null;$s=$db->prepare('SELECT status FROM ue_dependency_links WHERE file_id=? AND import_index=? LIMIT 1');$s->execute([$fileId,$importIndex]);$v=$s->fetchColumn();if($v!==false)$sqlStatus=(int)$v;
    $compactDependency=null;foreach((array)($snapshot['dependencies']??[]) as $row){if(is_array($row)&&(int)($row['import_index']??-1)===$importIndex){$compactDependency=$row;break;}}
    $compactStatus=is_array($compactDependency)?trim((string)($compactDependency['status']??'')):'';
    $base=[
        'file_id'=>$fileId,'import_index'=>$importIndex,'required_package'=>$requiredPackage,
        'sql_status'=>$sqlStatus,'compact_dependency'=>$compactDependency,
        'serialized_import'=>$target,'serialized_outer_chain'=>catalog_ue4_import_outer_chain($imports,$exports,$importIndex),
        'required_import_indexes'=>array_values(array_unique(array_map('intval',$required))),
    ];
    if($sqlStatus===null){$reason='sql_dependency_missing';return$base+['verdict'=>'needs_investigation','reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),'explanation'=>catalog_dependency_evidence_reason_explanation($reason,[],$target),'providers'=>[]];}
    if($sqlStatus!==0)return$base+['verdict'=>'not_missing','reason'=>'exact_match','reason_label'=>'This row is not currently missing','explanation'=>'The SQL dependency row is no longer status 0 (missing).','providers'=>[]];
    if(!is_array($compactDependency)){$reason='compact_dependency_missing';return$base+['verdict'=>'needs_investigation','reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),'explanation'=>catalog_dependency_evidence_reason_explanation($reason,[],$target),'providers'=>[]];}
    if($compactStatus!=='missing'){
        $reason='compact_not_missing';
        return$base+['verdict'=>'needs_investigation','reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),'explanation'=>catalog_dependency_evidence_reason_explanation($reason,[],$target),'providers'=>[]];
    }
    $providers=catalog_dependency_provider_candidates($db,$gameId,$fileId,$requiredPackage);
    if($providers===[]){$reason='no_package_provider';return$base+['verdict'=>'proven_missing','reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),'explanation'=>catalog_dependency_evidence_reason_explanation($reason,[],$target),'providers'=>[]];}
    $dependency=[
        'required_package'=>$requiredPackage,'required_object_path'=>(string)($target['full_path']??''),
        'import_object_name'=>(string)($target['object_name']??''),'import_class_package'=>(string)($target['class_package']??''),'import_class_name'=>(string)($target['class_name']??''),
    ];
    $providerEvidence=[];$bestIndex=null;$bestMatch=-1;$bestRedirect=-1;$anyComplete=false;
    foreach($providers as $provider){
        $providerId=(int)($provider['file_id']??0);if($providerId<1)continue;
        $outcome=\UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver::diagnoseProviderOutcome($db,$providerId,$imports,$exports);
        $matches=(array)($outcome['matches']??[]);$redirectors=(array)($outcome['redirectors']??[]);$ancestry=(array)($outcome['redirector_ancestry']??[]);$rejections=(array)($outcome['rejections']??[]);
        $matchCount=0;$redirectCount=0;foreach($required as $idx){if(array_key_exists($idx,$matches))$matchCount++;elseif(array_key_exists($idx,$redirectors))$redirectCount++;}
        $complete=$required!==[]&&$matchCount===count($required);if($complete)$anyComplete=true;
        if(array_key_exists($importIndex,$matches))$detail=['reason'=>'exact_match','export_index'=>(int)$matches[$importIndex]];
        elseif(array_key_exists($importIndex,$redirectors))$detail=['reason'=>'object_redirector_target_unavailable','redirector_export_index'=>(int)$redirectors[$importIndex]];
        elseif(array_key_exists($importIndex,$ancestry))$detail=['reason'=>'object_redirector_ancestor_target_unavailable','blocked_by_import_index'=>(int)$ancestry[$importIndex]];
        else $detail=(array)($rejections[$importIndex]??['import_index'=>$importIndex,'reason'=>'unclassified']);
        $near=[];foreach(array_slice(catalog_dependency_export_candidates($db,$dependency,$providerId),0,10) as $candidate){$outerPath=catalog_dependency_export_outer_path($db,$providerId,(int)($candidate['outer_index']??0),$requiredPackage);$candidate['outer_path']=$outerPath;$candidate['comparison']=catalog_dependency_candidate_summary($dependency,$candidate,$outerPath);$near[]=$candidate;}
        $providerEvidence[]=[
            'file_id'=>$providerId,'source_kind'=>(string)($provider['source_kind']??''),'package_name'=>(string)($provider['package_name']??''),'original_name'=>(string)($provider['original_name']??''),
            'matched'=>$matchCount,'required'=>count($required),'redirector_count'=>$redirectCount,'complete'=>$complete,
            'target_reason'=>(string)($detail['reason']??'unclassified'),'target_detail'=>$detail,'nearby_candidates'=>$near,'selected'=>false,
        ];
        $i=count($providerEvidence)-1;if($matchCount>$bestMatch||($matchCount===$bestMatch&&$redirectCount>$bestRedirect)){$bestIndex=$i;$bestMatch=$matchCount;$bestRedirect=$redirectCount;}
    }
    if($bestIndex===null){$reason='no_package_provider';return$base+['verdict'=>'proven_missing','reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),'explanation'=>catalog_dependency_evidence_reason_explanation($reason,[],$target),'providers'=>[]];}
    $providerEvidence[$bestIndex]['selected']=true;$best=$providerEvidence[$bestIndex];$reason=(string)$best['target_reason'];$detail=(array)$best['target_detail'];
    $unresolvedReasons=['object_redirector_target_unavailable','object_redirector_ancestor_target_unavailable','v4_package_context_unavailable'];
    $provenReasons=['object_name_not_found','class_name_mismatch','class_package_mismatch','outer_mismatch','private_export_rejected','outer_import_unresolved'];
    if($anyComplete||$reason==='exact_match')$verdict='needs_investigation';elseif(in_array($reason,$unresolvedReasons,true))$verdict='not_proven_missing';elseif(in_array($reason,$provenReasons,true))$verdict='proven_missing';else $verdict='needs_investigation';
    return$base+[
        'verdict'=>$verdict,'reason'=>$reason,'reason_label'=>catalog_dependency_evidence_reason_label($reason),
        'explanation'=>catalog_dependency_evidence_reason_explanation($reason,$detail,$target),
        'selected_provider_index'=>$bestIndex,'any_complete_provider'=>$anyComplete,'providers'=>$providerEvidence,
        'provider_policy'=>'One physical provider is selected for the logical package; greatest exact VerifyImport match count wins and redirector count breaks exact-count ties.',
    ];
}
