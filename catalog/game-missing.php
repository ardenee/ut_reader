<?php
/** Admin package-first view of unresolved dependency objects for one game. */
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';
require_once __DIR__.'/lib/BaseGameProtection.php';
require_once __DIR__.'/lib/CatalogDependencyDiagnostics.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameMissingDependencyQuery;

catalog_start_session();

function game_missing_int(string $key,int $default=0):int{$v=filter_input(INPUT_GET,$key,FILTER_VALIDATE_INT);return$v===false||$v===null?$default:max(0,(int)$v);}
function game_missing_type():string{$v=strtolower(trim((string)($_GET['dependency_type']??'all')));return$v==='base_game'?'base_game':'all';}
function game_missing_text(string $key,int $limit=500):string{return substr(trim((string)($_GET[$key]??'')),0,$limit);}
function game_missing_url(int $gameId,string $type,array $params=[]):string{$q=array_merge(['game_id'=>$gameId,'dependency_type'=>$type],$params);$q=array_filter($q,static fn(mixed $v):bool=>$v!==null&&$v!==''&&$v!==0);return'game-missing.php?'.http_build_query($q);}
function gm_anchor_id(string $kind,string $value):string{return'gm-'.$kind.'-'.substr(hash('sha256',strtolower(trim($value))),0,16);}
function gm_path_parts(string $path):array{return array_values(array_filter(explode('.',trim($path)),static fn(string $v):bool=>$v!==''));}
function gm_path_html(string $path,int $highlightFrom):string{$parts=gm_path_parts($path);$out=[];foreach($parts as $i=>$part){$text=catalog_h($part);$out[]=$i>=$highlightFrom?'<mark class="gm-missing-part">'.$text.'</mark>':$text;}return implode('<span class="gm-dot">.</span>',$out);}
function gm_first_path_difference(string $wanted,string $actual):int{$w=gm_path_parts($wanted);$a=gm_path_parts($actual);$n=min(count($w),count($a));for($i=0;$i<$n;$i++)if(strcasecmp($w[$i],$a[$i])!==0)return$i;if(count($w)!==count($a))return$n;return max(0,count($w)-1);}
function gm_leaf_index(string $path):int{return max(0,count(gm_path_parts($path))-1);}
function gm_outer_path(string $path):string{$parts=gm_path_parts($path);array_pop($parts);return implode('.',$parts);}
function gm_object_diagnostic(PDO $db,int $gameId,array $row):array{
    $path=(string)($row['required_object_path']??'');$package=(string)($row['required_package']??'');$leaf=gm_leaf_index($path);
    $parts=gm_path_parts($path);$row['import_object_name']=$parts!==[]?(string)$parts[count($parts)-1]:'';
    $row['import_class_package']=(string)($row['class_package']??'');$row['import_class_name']=(string)($row['class_name']??'');
    $providers=catalog_dependency_provider_candidates($db,$gameId,(int)($row['file_id']??0),$package);
    if($providers===[])return['reason'=>'Package not present','highlight_from'=>0,'providers'=>[],'best'=>null];
    $best=null;$bestScore=-1;
    foreach($providers as &$provider){$provider['candidate_rows']=catalog_dependency_export_candidates($db,$row,(int)$provider['file_id']);
        foreach($provider['candidate_rows'] as &$candidate){$candidate['outer_path']=catalog_dependency_export_outer_path($db,(int)$provider['file_id'],(int)$candidate['outer_index'],$package);$s=catalog_dependency_candidate_summary($row,$candidate,$candidate['outer_path']);$score=(int)$s['object_match']+(int)$s['class_package_match']+(int)$s['class_name_match']+(int)$s['outer_match']+(int)$s['public'];if($score>$bestScore){$bestScore=$score;$best=['summary'=>$s,'provider'=>$provider,'candidate'=>$candidate];}}unset($candidate);
    }unset($provider);
    if($best===null)return['reason'=>'Object not present','highlight_from'=>$leaf,'providers'=>$providers,'best'=>null];
    $s=$best['summary'];
    if(!$s['outer_match'])return['reason'=>'Outer path mismatch','highlight_from'=>gm_first_path_difference(gm_outer_path($path),(string)$s['actual_outer_path']),'providers'=>$providers,'best'=>$best];
    if(!$s['class_package_match']||!$s['class_name_match'])return['reason'=>'Class mismatch','highlight_from'=>$leaf,'providers'=>$providers,'best'=>$best];
    if(!$s['public'])return['reason'=>'Private export','highlight_from'=>$leaf,'providers'=>$providers,'best'=>$best];
    if(!$s['object_match'])return['reason'=>'Object mismatch','highlight_from'=>$leaf,'providers'=>$providers,'best'=>$best];
    return['reason'=>'Stored missing row matches current candidate','highlight_from'=>$leaf,'providers'=>$providers,'best'=>$best];
}

function gm_evidence_verdict_label(string $verdict):string
{
    return match($verdict){
        'proven_missing'=>'Proven missing',
        'needs_investigation'=>'Needs investigation',
        'not_proven_missing'=>'Not proven missing',
        'not_missing'=>'Not currently missing',
        default=>'Evidence unavailable',
    };
}
function gm_ue4_evidence_html(array $e):string
{
    $verdict=(string)($e['verdict']??'');$reason=(string)($e['reason']??'');$target=(array)($e['serialized_import']??[]);$providers=(array)($e['providers']??[]);
    $out='<div class="gm-evidence gm-evidence--'.catalog_h($verdict).'">';
    $out.='<div class="gm-evidence__headline"><strong>'.catalog_h(gm_evidence_verdict_label($verdict)).'</strong><span>'.catalog_h((string)($e['reason_label']??$reason)).'</span></div>';
    $out.='<p>'.catalog_h((string)($e['explanation']??'')).'</p>';
    $compact=(array)($e['compact_dependency']??[]);$compactStatus=(string)($compact['outcome']??'(not recorded)');$compactSource=(string)($compact['reason_code']??'');$compactConfidence=(string)($compact['source_policy']??'');$sqlStatus=$e['sql_status']??null;$sqlLabel=$sqlStatus===0?'missing (0)':($sqlStatus===null?'not recorded':(string)$sqlStatus);
    $compactDetail=implode(' · ',array_values(array_filter([$compactStatus,$compactSource,$compactConfidence],static fn(string $v):bool=>$v!=='')));
    $out.='<div class="gm-evidence-grid"><div><b>Persistence</b><br>SQL '.catalog_h($sqlLabel).' · UEDB5 '.catalog_h($compactDetail).'</div>';
    $class=implode('.',array_values(array_filter([(string)($target['class_package']??''),(string)($target['class_name']??'')],static fn(string $v):bool=>$v!=='')));
    $out.='<div><b>Serialized Import #'.(int)($e['import_index']??-1).'</b><br>'.catalog_h((string)($target['object_name']??'')).' · '.catalog_h($class).' · OuterIndex '.(int)($target['outer_index']??0).'</div></div>';
    $chain=[];foreach((array)($e['serialized_outer_chain']??[]) as $node){if(!is_array($node))continue;$chain[]=ucfirst((string)($node['kind']??'resource')).' #'.(int)($node['index']??-1).' '.(string)($node['object_name']??'').' [outer '.(int)($node['outer_index']??0).']';}
    if($chain!==[])$out.='<div class="gm-evidence-chain"><b>Serialized outer chain:</b> '.catalog_h(implode(' → ',$chain)).'</div>';
    if($providers===[]){$out.='<div class="gm-note"><strong>No provider rows exist.</strong> The required package identity is absent from the verified game-local provider set.</div>';$out.='</div>';return$out;}
    $out.='<p class="muted">'.catalog_h((string)($e['provider_policy']??'')).'</p>';
    $out.='<table class="gm-table gm-evidence-table"><thead><tr><th>Provider</th><th>Source</th><th class="num">Exact matches</th><th class="num">Redirectors</th><th>This Import</th><th>Catalogue choice</th></tr></thead><tbody>';
    $selectedProvider=null;foreach($providers as $provider){if(!is_array($provider))continue;$selected=!empty($provider['selected']);if($selected)$selectedProvider=$provider;
        $label=(string)($provider['original_name']??'');if($label==='')$label=(string)($provider['package_name']??'');
        $targetReason=(string)($provider['target_reason']??'unclassified');
        $out.='<tr'.($selected?' class="gm-provider-selected"':'').'><td><a href="file-info.php?id='.(int)($provider['file_id']??0).'"><strong>'.catalog_h($label).'</strong></a><br><span class="muted">#'.(int)($provider['file_id']??0).'</span></td>';
        $out.='<td>'.catalog_h((string)($provider['source_kind']??'')).'</td><td class="num">'.(int)($provider['matched']??0).' / '.(int)($provider['required']??0).'</td><td class="num">'.(int)($provider['redirector_count']??0).'</td>';
        $out.='<td>'.catalog_h(catalog_dependency_evidence_reason_label($targetReason)).'</td><td>'.($selected?'<strong>Selected</strong>':'—').'</td></tr>';
    }$out.='</tbody></table>';
    if(empty($e['any_complete_provider']))$out.='<div class="gm-note"><strong>No single physical provider satisfies the complete serialized Import set.</strong> Provider matches are not combined across different files.</div>';
    else $out.='<div class="gm-note"><strong>A complete physical provider exists.</strong> A persisted missing row is suspicious and should be rebuilt or investigated.</div>';
    if(is_array($selectedProvider)){$near=(array)($selectedProvider['nearby_candidates']??[]);if($near===[]){$out.='<div class="gm-note"><strong>No same-name export candidate exists in the selected provider.</strong></div>';}
        else{$out.='<details class="gm-candidates"><summary>Same-name export candidates in selected provider ('.count($near).')</summary><table class="gm-table"><thead><tr><th>Export</th><th>Object</th><th>Class</th><th>Outer</th><th>Flags</th><th>Differences</th></tr></thead><tbody>';
            foreach($near as $candidate){if(!is_array($candidate))continue;$cmp=(array)($candidate['comparison']??[]);$actualClass=implode('.',array_values(array_filter([(string)($cmp['actual_class_package']??''),(string)($cmp['actual_class_name']??'')],static fn(string $v):bool=>$v!=='')));
                $diff=(array)($cmp['differences']??[]);$out.='<tr><td>#'.(int)($candidate['export_index']??-1).'</td><td>'.catalog_h((string)($candidate['object_name']??'')).'</td><td class="mono">'.catalog_h($actualClass).'</td><td class="mono">'.catalog_h((string)($candidate['outer_path']??'')).'</td><td class="mono">'.catalog_h((string)($cmp['flags_hex']??'')).'</td><td>'.catalog_h($diff===[]?'none':implode(', ',$diff)).'</td></tr>';
            }$out.='</tbody></table></details>';}
    }
    $out.='</div>';return$out;
}

try{
    $config=catalog_config();$db=catalog_db($config);$storageRoot=trim((string)($config['storage_path']??''));if(!catalog_require_admin_page('Game Missing Dependencies'))exit;base_game_ensure($db);
    $isAdmin=catalog_support_is_admin();
    $gameId=$_SERVER['REQUEST_METHOD']==='POST'?max(0,(int)($_POST['game_id']??0)):game_missing_int('game_id');
    $games=catalog_all($db,'SELECT id,name,slug FROM ue_games ORDER BY name');
    $game=$gameId>0?catalog_one($db,'SELECT g.id,g.name,g.slug,UPPER(TRIM(p.engine_key)) engine_key FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',[$gameId]):null;if(!$game)throw new RuntimeException('Choose a valid game from the Games page.');
    $type=$_SERVER['REQUEST_METHOD']==='POST'?(strtolower(trim((string)($_POST['dependency_type']??'all')))==='base_game'?'base_game':'all'):game_missing_type();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{
            if(!$isAdmin)throw new RuntimeException('Administrator access is required.');
            catalog_check_csrf('game_missing_summary_cleanup');
            if((string)($_POST['action']??'')!=='cleanup_stale_summary')throw new RuntimeException('Unknown missing-dependency action.');
            $cleanupFileId=max(0,(int)($_POST['file_id']??0));if($cleanupFileId<1)throw new RuntimeException('Choose a valid stale summary owner.');
            $owner=catalog_one($db,'SELECT s.file_id,MAX(COALESCE(f.scan_status,"missing")) scan_status,COUNT(*) summary_rows FROM ue_dependency_package_summaries s LEFT JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id WHERE s.game_id=? AND s.file_id=? GROUP BY s.file_id',[$gameId,$cleanupFileId]);
            if(!is_array($owner)){
                $_SESSION['game_missing_flash']='Summary owner #'.$cleanupFileId.' is already clean.';
            }else{
                if((string)($owner['scan_status']??'')==='verified')throw new RuntimeException('Refusing cleanup: summary owner #'.$cleanupFileId.' is currently verified.');
                (new PdoDependencyPackageSummary($db))->rebuildFile($cleanupFileId);
                $remaining=(int)(catalog_one($db,'SELECT COUNT(*) remaining FROM ue_dependency_package_summaries WHERE game_id=? AND file_id=?',[$gameId,$cleanupFileId])['remaining']??0);
                if($remaining>0)throw new RuntimeException('Cleanup did not remove all stale summary rows for owner #'.$cleanupFileId.'.');
                $_SESSION['game_missing_flash']='Stale dependency summaries cleaned for owner #'.$cleanupFileId.'.';
            }
        }catch(Throwable $cleanupError){$_SESSION['game_missing_flash']='Cleanup failed: '.$cleanupError->getMessage();}
        header('Location: '.game_missing_url($gameId,$type));exit;
    }
    $cleanupCsrf=$isAdmin?catalog_csrf('game_missing_summary_cleanup'):'';
    $flash=$_SESSION['game_missing_flash']??null;unset($_SESSION['game_missing_flash']);
    $baseGameOnly=$type==='base_game';$packageSearch=game_missing_text('q',255);$objectSearch=game_missing_text('object_q',500);
    $selectedPackage=game_missing_text('package',255);$selectedObject=game_missing_text('object',1000);$objectPage=max(1,game_missing_int('object_page',1));$objectLimit=200;$engineKey=strtoupper(trim((string)($game['engine_key']??'')));$evidenceKey=game_missing_text('evidence',64);$evidenceFileId=0;$evidenceImportIndex=-1;if(preg_match('/^(\d+):(\d+)$/',$evidenceKey,$m)===1){$evidenceFileId=(int)$m[1];$evidenceImportIndex=(int)$m[2];}
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $missingQuery=new PdoGameMissingDependencyQuery($db);$scope=$baseGameOnly?$missingQuery->officialBaseGamePackageNames($gameId):null;
    $projectionHealth=$isAdmin?$missingQuery->projectionHealth($gameId,20):['stale_files'=>0,'stale_rows'=>0,'stale_missing_rows'=>0,'owners'=>[]];$totals=$missingQuery->totals($gameId,$scope);$packageRows=$missingQuery->packageRows($gameId,$scope,500,0,$packageSearch);
    $objectRows=[];$objectTotal=0;$objectOffset=0;$packageProviders=[];$objectFiles=[];$diagnostic=null;$evidence=null;$sampleEvidence=null;
    if($selectedPackage!==''&&($scope===null||in_array(strtolower($selectedPackage),array_map('strtolower',$scope),true))){
        $objectTotal=$missingQuery->objectTotal($gameId,$selectedPackage,$scope,$objectSearch);$objectOffset=($objectPage-1)*$objectLimit;
        $objectRows=$missingQuery->objectRows($gameId,$selectedPackage,$scope,$objectLimit,$objectOffset,$objectSearch);
        $packageProviders=catalog_dependency_provider_candidates($db,$gameId,0,$selectedPackage);
        if($selectedObject!==''){
            $objectFiles=$missingQuery->objectFileRows($gameId,$selectedPackage,$selectedObject,$scope,500,0);
            if($objectFiles!==[]){
                if($engineKey==='UE4'){
                    $evidenceTarget=null;foreach($objectFiles as $candidateRow){if((int)$candidateRow['file_id']===$evidenceFileId&&(int)$candidateRow['import_index']===$evidenceImportIndex){$evidenceTarget=$candidateRow;break;}}
                    if(is_array($evidenceTarget))$evidence=catalog_ue4_missing_import_evidence($db,$storageRoot,$gameId,(int)$evidenceTarget['file_id'],(int)$evidenceTarget['import_index'],$selectedPackage);
                    $sample=$evidenceTarget??$objectFiles[0];$sampleEvidence=is_array($evidence)&&$evidenceTarget===$sample?$evidence:catalog_ue4_missing_import_evidence($db,$storageRoot,$gameId,(int)$sample['file_id'],(int)$sample['import_index'],$selectedPackage);
                    $diagnostic=['reason'=>'Sample UE4 evidence: '.(string)($sampleEvidence['reason_label']??'Unknown'),'highlight_from'=>gm_leaf_index($selectedObject)];
                }else{$sample=$objectFiles[0];$sample['required_package']=$selectedPackage;$sample['required_object_path']=$selectedObject;$diagnostic=gm_object_diagnostic($db,$gameId,$sample);}
            }
        }
    }
    $typeLabel=$baseGameOnly?'Official base-game missing dependencies':'All missing dependencies';
    catalog_head('Missing Dependencies — '.(string)$game['name']);
    echo <<<'CSS'
<style>
.gm-filter{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.gm-filter label{display:grid;gap:5px}.gm-summary,.gm-table{width:100%;border-collapse:collapse}.gm-summary{max-width:760px}.gm-summary th,.gm-summary td,.gm-table th,.gm-table td{padding:9px 11px;border-bottom:1px solid var(--border,#2b3950);text-align:left;vertical-align:top}.gm-summary th,.gm-table th{font-size:.82em}.gm-table td.num,.gm-table th.num{text-align:right;white-space:nowrap}.gm-package-selected{background:rgba(80,140,220,.09)}.gm-package-detail td{padding:0 10px 18px}.gm-package-detail details{border:1px solid var(--border,#2b3950);border-radius:8px;padding:10px}.gm-package-detail summary{cursor:pointer;font-weight:700}.gm-missing-path{overflow-wrap:anywhere;font-family:var(--mono,monospace)}.gm-missing-part{background:rgba(235,90,80,.22);color:inherit;border-bottom:2px solid #ef6d63;padding:0 1px}.gm-dot{opacity:.65}.gm-reason{font-size:.9em;font-weight:700}.gm-actions{white-space:nowrap}.gm-note{padding:10px 12px;border-left:3px solid #d5a03d;background:rgba(213,160,61,.08);margin:10px 0}.gm-pager{display:flex;gap:8px;align-items:center;margin:10px 0}.gm-files td:first-child{white-space:nowrap}.gm-evidence{margin:10px 0;padding:12px;border:1px solid var(--border,#2b3950);border-radius:8px;background:rgba(80,140,220,.05)}.gm-evidence__headline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.gm-evidence__headline strong{font-size:1.05em}.gm-evidence-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin:10px 0}.gm-evidence-chain{padding:8px 10px;background:rgba(255,255,255,.03);border-radius:6px;overflow-wrap:anywhere}.gm-provider-selected{background:rgba(80,140,220,.1)}.gm-candidates{margin-top:10px}.gm-candidates summary{cursor:pointer}.gm-evidence--proven_missing{border-left:4px solid #c94d43}.gm-evidence--needs_investigation,.gm-evidence--not_proven_missing{border-left:4px solid #d5a03d}.gm-evidence--not_missing{border-left:4px solid #6d9f5b}@media(max-width:850px){.gm-table{font-size:.9em}.gm-actions{white-space:normal}}
</style>
CSS;
    echo CatalogUi::pageHeader('Missing Dependencies — '.(string)$game['name'],$typeLabel.'. Only dependency rows currently classified as missing are shown.',['Games'=>'games.php','Global Missing Files'=>'missing.php','Game Files'=>'game-files.php?id='.$gameId]);
    echo '<section class="ui-section"><div class="ui-section__body"><form class="gm-filter" method="get">';
    echo '<label>Game<select name="game_id">';foreach($games as $g)echo'<option value="'.(int)$g['id'].'"'.((int)$g['id']===$gameId?' selected':'').'>'.catalog_h((string)$g['name']).'</option>';echo'</select></label>';
    echo '<label>Dependency type<select name="dependency_type"><option value="all"'.(!$baseGameOnly?' selected':'').'>All missing dependencies</option><option value="base_game"'.($baseGameOnly?' selected':'').'>Official base-game dependencies only</option></select></label>';
    echo '<label>Package search<input name="q" value="'.catalog_h($packageSearch).'" placeholder="package name"></label><button type="submit">Apply filters</button></form></div></section>';

    if($isAdmin&&(int)($projectionHealth['stale_files']??0)>0){
        $staleFiles=(int)$projectionHealth['stale_files'];$staleRows=(int)$projectionHealth['stale_rows'];$staleMissing=(int)$projectionHealth['stale_missing_rows'];$owners=(array)($projectionHealth['owners']??[]);
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Projection health</h2><p>Admin-only cleanup. Stale package-summary owners are excluded from the missing-dependency counts below.</p></div></div><div class="ui-section__body">';
        echo '<div class="gm-note"><strong>'.number_format($staleFiles).' stale summary owner'.($staleFiles===1?'':'s').' / '.number_format($staleRows).' stale summary rows / '.number_format($staleMissing).' stale missing counts.</strong><br>Stale here means the summary owner is no longer a verified/current file. These rows are projection drift, not current missing-dependency evidence.</div>';
        if($owners!==[]){echo '<table class="gm-table"><thead><tr><th>Owner file</th><th>Status</th><th class="num">Summary rows</th><th class="num">Stale missing</th><th class="num">Live dependency rows</th><th>Cleanup</th></tr></thead><tbody>';
            foreach($owners as $owner){$fid=(int)($owner['file_id']??0);echo '<tr><td class="mono">#'.$fid.'</td><td>'.catalog_h((string)($owner['scan_status']??'missing')).'</td><td class="num">'.(int)($owner['summary_rows']??0).'</td><td class="num">'.(int)($owner['stale_missing_rows']??0).'</td><td class="num">'.(int)($owner['live_dependency_rows']??0).'</td><td><form method="post" class="gm-cleanup-form"><input type="hidden" name="csrf" value="'.catalog_h($cleanupCsrf).'"><input type="hidden" name="action" value="cleanup_stale_summary"><input type="hidden" name="game_id" value="'.$gameId.'"><input type="hidden" name="dependency_type" value="'.catalog_h($type).'"><input type="hidden" name="file_id" value="'.$fid.'"><button type="submit" class="button secondary gm-cleanup-button">Run cleanup</button></form></td></tr>';}
            echo '</tbody></table>';}
        echo '<p class="muted small">Cleanup rebuilds only that summary owner. For a failed owner it removes the stale summaries and writes nothing back. The button disables as soon as it is submitted; after a successful cleanup the owner no longer appears on reload.</p></div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Summary</h2><p>Current rows classified as missing. Source-unresolved UT3 cooked export-outer imports are excluded.</p></div></div><div class="ui-section__body"><table class="gm-summary"><tbody>';
    echo '<tr><th>Missing objects</th><td class="num"><strong>'.(int)$totals['missing_objects'].'</strong></td><td>Requested object identities that did not resolve.</td></tr>';
    echo '<tr><th>Required packages</th><td class="num"><strong>'.(int)$totals['missing_packages'].'</strong></td><td>Logical packages containing those missing/rejected objects.</td></tr>';
    echo '<tr><th>Affected files</th><td class="num"><strong>'.(int)$totals['files_with_missing'].'</strong></td><td>Verified files containing at least one missing dependency row.</td></tr>';
    echo '</tbody></table></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Required packages</h2><p>Start here. Open a package to inspect its missing object paths and the files that require them.</p></div></div><div class="ui-section__body">';
    if($packageRows===[])echo CatalogUi::emptyState('No missing packages','Nothing currently matches the selected scope/search.');
    else{echo'<table class="gm-table"><thead><tr><th>Package</th><th class="num">Missing objects</th><th class="num">Affected files</th><th></th></tr></thead><tbody>';
        foreach($packageRows as $packageRow){$name=(string)$packageRow['required_package'];$selected=strcasecmp($name,$selectedPackage)===0;$packageAnchor=gm_anchor_id('package',$name);$url=game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name]).'#'.$packageAnchor;
            echo'<tr id="'.catalog_h($packageAnchor).'"'.($selected?' class="gm-package-selected"':'').'><td><strong class="mono">'.catalog_h($name).'</strong></td><td class="num">'.(int)$packageRow['missing_object_rows'].'</td><td class="num">'.(int)$packageRow['requiring_file_count'].'</td><td class="gm-actions"><a class="button secondary" href="'.catalog_h($url).'">'.($selected?'Objects open':'Show objects').'</a></td></tr>';
            if(!$selected)continue;
            echo'<tr class="gm-package-detail"><td colspan="4"><details open><summary>'.catalog_h($name).' — missing object paths</summary>';
            echo'<form class="gm-filter" method="get" action="game-missing.php#'.catalog_h($packageAnchor).'"><input type="hidden" name="game_id" value="'.$gameId.'"><input type="hidden" name="dependency_type" value="'.catalog_h($type).'"><input type="hidden" name="package" value="'.catalog_h($name).'"><label>Object/path search<input name="object_q" value="'.catalog_h($objectSearch).'" placeholder="object or path"></label><button type="submit">Search objects</button></form>';
            if($objectRows===[])echo CatalogUi::emptyState('No missing object paths','Nothing currently matches this package/search.');
            else{echo'<table class="gm-table"><thead><tr><th>Required path</th><th>Reason</th><th class="num">Missing imports</th><th class="num">Affected files</th><th></th></tr></thead><tbody>';
                foreach($objectRows as $objectRow){$path=(string)$objectRow['required_object_path'];$isObject=strcasecmp($path,$selectedObject)===0;$highlight=$packageProviders===[]?0:gm_leaf_index($path);$reason=$packageProviders===[]?'Package not present':'Missing/rejected object';if($isObject&&is_array($diagnostic)){$highlight=(int)$diagnostic['highlight_from'];$reason=(string)$diagnostic['reason'];}
                    $objectAnchor=gm_anchor_id('object',$path);$objectUrl=game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage,'object'=>$path]).'#'.$objectAnchor;
                    echo'<tr id="'.catalog_h($objectAnchor).'"'.($isObject?' class="gm-package-selected"':'').'><td class="gm-missing-path">'.gm_path_html($path,$highlight).'</td><td class="gm-reason">'.catalog_h($reason).'</td><td class="num">'.(int)$objectRow['missing_import_count'].'</td><td class="num">'.(int)$objectRow['affected_file_count'].'</td><td class="gm-actions"><a class="button secondary" href="'.catalog_h($objectUrl).'">'.($isObject?'Files open':'Show files').'</a></td></tr>';
                }
                echo'</tbody></table>';
            }
            if($objectTotal>$objectLimit){$pages=(int)ceil($objectTotal/$objectLimit);echo'<div class="gm-pager"><span>Page '.$objectPage.' of '.$pages.' · '.$objectTotal.' object paths</span>';if($objectPage>1)echo'<a class="button secondary" href="'.catalog_h(game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage-1]).'#'.$packageAnchor).'">Previous</a>';if($objectPage<$pages)echo'<a class="button secondary" href="'.catalog_h(game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage+1]).'#'.$packageAnchor).'">Next</a>';echo'</div>';}
            if($selectedObject!==''&&$objectFiles!==[]){$h=is_array($diagnostic)?(int)$diagnostic['highlight_from']:gm_leaf_index($selectedObject);$reason=is_array($diagnostic)?(string)$diagnostic['reason']:'Missing/rejected object';
                echo'<div class="gm-note"><strong>'.catalog_h($reason).'</strong><div class="gm-missing-path">'.gm_path_html($selectedObject,$h).'</div></div>';
                echo'<h3>Affected files</h3><table class="gm-table gm-files"><thead><tr><th>File</th><th>Import</th><th>Expected class</th><th>Required path</th><th>Evidence</th></tr></thead><tbody>';
                foreach($objectFiles as $fileRow){
                    $class=implode('.',array_values(array_filter([(string)$fileRow['class_package'],(string)$fileRow['class_name']],static fn(string $v):bool=>$v!=='')));
                    $rowEvidenceKey=(int)$fileRow['file_id'].':'.(int)$fileRow['import_index'];$isEvidence=$engineKey==='UE4'&&$evidenceKey===$rowEvidenceKey;$evidenceAnchor=gm_anchor_id('evidence',$rowEvidenceKey);
                    $evidenceUrl=game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage,'object'=>$selectedObject,'evidence'=>$rowEvidenceKey]).'#'.$evidenceAnchor;
                    echo'<tr id="'.catalog_h($evidenceAnchor).'"'.($isEvidence?' class="gm-package-selected"':'').'><td><a href="file-info.php?id='.(int)$fileRow['file_id'].'"><strong>'.catalog_h((string)$fileRow['owner_package_name']).'</strong></a><br><a class="muted" href="file-examine.php?id='.(int)$fileRow['file_id'].'">'.catalog_h((string)$fileRow['owner_original_name']).'</a></td><td>#'.(int)$fileRow['import_index'].'</td><td class="mono">'.catalog_h($class!==''?$class:'(not recorded)').'</td><td class="gm-missing-path">'.gm_path_html((string)$fileRow['required_object_path'],$h).'</td><td class="gm-actions">'.($engineKey==='UE4'?'<a class="button secondary" href="'.catalog_h($evidenceUrl).'">'.($isEvidence?'Evidence open':'Show evidence').'</a>':'<span class="muted">Generic check only</span>').'</td></tr>';
                    if($isEvidence&&is_array($evidence))echo'<tr class="gm-evidence-row"><td colspan="5">'.gm_ue4_evidence_html($evidence).'</td></tr>';
                }
                echo'</tbody></table>';
            }
            echo'</details></td></tr>';
        }
        echo'</tbody></table>';
    }
    echo'</div></section>';
    if($isAdmin)echo '<script>document.querySelectorAll(".gm-cleanup-form").forEach(function(form){form.addEventListener("submit",function(event){var button=form.querySelector(".gm-cleanup-button");if(!button||button.disabled){event.preventDefault();return;}if(!window.confirm("Clean stale dependency summaries for this owner?")){event.preventDefault();return;}button.disabled=true;button.textContent="Cleaning...";});});</script>';
    catalog_foot();
}catch(Throwable $error){
    if(!headers_sent())catalog_head('Game missing dependencies error');
    echo CatalogUi::alert('danger',$error->getMessage(),'The filtered missing-dependency page could not be loaded.');
    catalog_foot();
}
