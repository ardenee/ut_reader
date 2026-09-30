#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);$checks=[];$fail=[];
$read=static function(string $rel)use($root):string{$p=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$s=@file_get_contents($p);return is_string($s)?$s:'';};
$record=static function(string $name,bool $ok,string $detail)use(&$checks,&$fail):void{$checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];if(!$ok)$fail[]=$name;};
$page=$read('game-missing.php');$query=$read('src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php');$diag=$read('lib/CatalogDependencyDiagnostics.php');
$record('package_first_hierarchy',str_contains($page,'<h2>Required packages</h2>')&&str_contains($page,'missing object paths')&&str_contains($page,'<h3>Affected files</h3>'),'Missing UI should drill package -> object path -> affected files.');
$record('missing_path_segment_highlight',str_contains($page,'gm_path_html')&&str_contains($page,'gm-missing-part')&&str_contains($page,'gm_first_path_difference'),'Required paths must remain whole while the missing/mismatching portion is highlighted.');
$record('grouped_object_projection',str_contains($query,'COUNT(DISTINCT l.required_object_term_id)')&&str_contains($query,'GROUP BY l.required_object_term_id')&&str_contains($query,'COUNT(DISTINCT l.file_id) affected_file_count'),'Object drilldown should aggregate compact dependency rows before rendering.');
$record('missing_scope_is_status_zero_only',substr_count($query,'l.status=0')>=4&&!str_contains($query,'l.status IN (0,4)'),'Source-unresolved status 4 must never leak into the missing page.');
$record('searchable_package_and_object_tables',str_contains($page,'name="q"')&&str_contains($page,'name="object_q"')&&str_contains($query,"LIKE ?"),'Package and object-path tables should support bounded search filters.');
$record('compact_summary_table_replaces_cards',str_contains($page,'<h2>Summary</h2>')&&str_contains($page,'gm-summary')&&!str_contains($page,"catalog_stat_card('Missing objects'"),'Top-level counts should be a compact table rather than three large cards.');
$record('ue4_missing_evidence_uses_authoritative_resolver',str_contains($page,'catalog_ue4_missing_import_evidence')&&str_contains($diag,'PdoUe4VerifyImportProjectionResolver::diagnoseProviderOutcome'),'UE4 missing evidence must replay the same VerifyImport resolver used by dependency resolution.');
$record('ue4_evidence_cross_checks_persistence',str_contains($diag,'SELECT status FROM ue_dependency_links')&&str_contains($diag,"'compact_not_missing'")&&str_contains($page,'SQL '),'Visible proof must cross-check SQL missing state against the authoritative compact dependency row.');
$record('ue4_evidence_shows_complete_provider_rule',str_contains($page,'No single physical provider satisfies the complete serialized Import set')&&str_contains($diag,"'provider_policy'")&&str_contains($page,'Exact matches'),'Duplicate package variants must be shown as one-provider evidence, never combined.');
$record('ue4_evidence_distinguishes_unresolved_from_missing',str_contains($diag,"'object_redirector_target_unavailable'")&&str_contains($diag,"'not_proven_missing'")&&str_contains($page,'Needs investigation'),'Redirector/metadata uncertainty must never be presented as proven missing.');
$record('ue4_evidence_only_audited_reasons_are_proven',str_contains($diag,"\$provenReasons=['object_name_not_found','class_name_mismatch','class_package_mismatch','outer_mismatch','private_export_rejected','outer_import_unresolved']")&&str_contains($diag,"else \$verdict='needs_investigation'"),'Only deterministic rejection reasons from the completed UE4 audit may be labelled proven missing.');
$record('affected_file_evidence_is_on_demand',str_contains($page,"'evidence'=>\$rowEvidenceKey")&&str_contains($page,'Show evidence'),'Authoritative provider replay should be opened for a selected affected Import rather than every list row.');
foreach(['game-missing.php','lib/CatalogDependencyDiagnostics.php','src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php'] as $rel){$p=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($p),$out,$code);$record('syntax:'.$rel,$code===0,'');$out=[];}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:2);
