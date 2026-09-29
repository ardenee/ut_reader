<?php
/** Admin package-first view of unresolved dependency objects for one game. */
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';
require_once __DIR__.'/lib/BaseGameProtection.php';
require_once __DIR__.'/lib/CatalogDependencyDiagnostics.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameMissingDependencyQuery;

catalog_start_session();

function game_missing_int(string $key,int $default=0):int{$v=filter_input(INPUT_GET,$key,FILTER_VALIDATE_INT);return$v===false||$v===null?$default:max(0,(int)$v);}
function game_missing_type():string{$v=strtolower(trim((string)($_GET['dependency_type']??'all')));return$v==='base_game'?'base_game':'all';}
function game_missing_text(string $key,int $limit=500):string{return substr(trim((string)($_GET[$key]??'')),0,$limit);}
function game_missing_url(int $gameId,string $type,array $params=[]):string{$q=array_merge(['game_id'=>$gameId,'dependency_type'=>$type],$params);$q=array_filter($q,static fn(mixed $v):bool=>$v!==null&&$v!==''&&$v!==0);return'game-missing.php?'.http_build_query($q);}
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

try{
    $config=catalog_config();$db=catalog_db($config);if(!catalog_require_admin_page('Game Missing Dependencies'))exit;base_game_ensure($db);
    $games=catalog_all($db,'SELECT id,name,slug FROM ue_games ORDER BY name');$gameId=game_missing_int('game_id');
    $game=$gameId>0?catalog_one($db,'SELECT id,name,slug FROM ue_games WHERE id=?',[$gameId]):null;if(!$game)throw new RuntimeException('Choose a valid game from the Games page.');
    $type=game_missing_type();$baseGameOnly=$type==='base_game';$packageSearch=game_missing_text('q',255);$objectSearch=game_missing_text('object_q',500);
    $selectedPackage=game_missing_text('package',255);$selectedObject=game_missing_text('object',1000);$objectPage=max(1,game_missing_int('object_page',1));$objectLimit=200;
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $missingQuery=new PdoGameMissingDependencyQuery($db);$scope=$baseGameOnly?$missingQuery->officialBaseGamePackageNames($gameId):null;
    $totals=$missingQuery->totals($gameId,$scope);$packageRows=$missingQuery->packageRows($gameId,$scope,500,0,$packageSearch);
    $objectRows=[];$objectTotal=0;$objectOffset=0;$packageProviders=[];$objectFiles=[];$diagnostic=null;
    if($selectedPackage!==''&&($scope===null||in_array(strtolower($selectedPackage),array_map('strtolower',$scope),true))){
        $objectTotal=$missingQuery->objectTotal($gameId,$selectedPackage,$scope,$objectSearch);$objectOffset=($objectPage-1)*$objectLimit;
        $objectRows=$missingQuery->objectRows($gameId,$selectedPackage,$scope,$objectLimit,$objectOffset,$objectSearch);
        $packageProviders=catalog_dependency_provider_candidates($db,$gameId,0,$selectedPackage);
        if($selectedObject!==''){$objectFiles=$missingQuery->objectFileRows($gameId,$selectedPackage,$selectedObject,$scope,500,0);if($objectFiles!==[]){$sample=$objectFiles[0];$sample['required_package']=$selectedPackage;$sample['required_object_path']=$selectedObject;$diagnostic=gm_object_diagnostic($db,$gameId,$sample);}}
    }
    $typeLabel=$baseGameOnly?'Official base-game missing dependencies':'All missing dependencies';
    catalog_head('Missing Dependencies — '.(string)$game['name']);
    echo <<<'CSS'
<style>
.gm-filter{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.gm-filter label{display:grid;gap:5px}.gm-summary,.gm-table{width:100%;border-collapse:collapse}.gm-summary{max-width:760px}.gm-summary th,.gm-summary td,.gm-table th,.gm-table td{padding:9px 11px;border-bottom:1px solid var(--border,#2b3950);text-align:left;vertical-align:top}.gm-summary th,.gm-table th{font-size:.82em}.gm-table td.num,.gm-table th.num{text-align:right;white-space:nowrap}.gm-package-selected{background:rgba(80,140,220,.09)}.gm-package-detail td{padding:0 10px 18px}.gm-package-detail details{border:1px solid var(--border,#2b3950);border-radius:8px;padding:10px}.gm-package-detail summary{cursor:pointer;font-weight:700}.gm-missing-path{overflow-wrap:anywhere;font-family:var(--mono,monospace)}.gm-missing-part{background:rgba(235,90,80,.22);color:inherit;border-bottom:2px solid #ef6d63;padding:0 1px}.gm-dot{opacity:.65}.gm-reason{font-size:.9em;font-weight:700}.gm-actions{white-space:nowrap}.gm-note{padding:10px 12px;border-left:3px solid #d5a03d;background:rgba(213,160,61,.08);margin:10px 0}.gm-pager{display:flex;gap:8px;align-items:center;margin:10px 0}.gm-files td:first-child{white-space:nowrap}@media(max-width:850px){.gm-table{font-size:.9em}.gm-actions{white-space:normal}}
</style>
CSS;
    echo CatalogUi::pageHeader('Missing Dependencies — '.(string)$game['name'],$typeLabel.'. Only dependency rows currently classified as missing are shown.',['Games'=>'games.php','Global Missing Files'=>'missing.php','Game Files'=>'game-files.php?id='.$gameId]);
    echo '<section class="ui-section"><div class="ui-section__body"><form class="gm-filter" method="get">';
    echo '<label>Game<select name="game_id">';foreach($games as $g)echo'<option value="'.(int)$g['id'].'"'.((int)$g['id']===$gameId?' selected':'').'>'.catalog_h((string)$g['name']).'</option>';echo'</select></label>';
    echo '<label>Dependency type<select name="dependency_type"><option value="all"'.(!$baseGameOnly?' selected':'').'>All missing dependencies</option><option value="base_game"'.($baseGameOnly?' selected':'').'>Official base-game dependencies only</option></select></label>';
    echo '<label>Package search<input name="q" value="'.catalog_h($packageSearch).'" placeholder="package name"></label><button type="submit">Apply filters</button></form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Summary</h2><p>Current rows classified as missing. Source-unresolved UT3 cooked export-outer imports are excluded.</p></div></div><div class="ui-section__body"><table class="gm-summary"><tbody>';
    echo '<tr><th>Missing objects</th><td class="num"><strong>'.(int)$totals['missing_objects'].'</strong></td><td>Requested object identities that did not resolve.</td></tr>';
    echo '<tr><th>Required packages</th><td class="num"><strong>'.(int)$totals['missing_packages'].'</strong></td><td>Logical packages containing those missing/rejected objects.</td></tr>';
    echo '<tr><th>Affected files</th><td class="num"><strong>'.(int)$totals['files_with_missing'].'</strong></td><td>Verified files containing at least one missing dependency row.</td></tr>';
    echo '</tbody></table></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Required packages</h2><p>Start here. Open a package to inspect its missing object paths and the files that require them.</p></div></div><div class="ui-section__body">';
    if($packageRows===[])echo CatalogUi::emptyState('No missing packages','Nothing currently matches the selected scope/search.');
    else{echo'<table class="gm-table"><thead><tr><th>Package</th><th class="num">Missing objects</th><th class="num">Affected files</th><th></th></tr></thead><tbody>';
        foreach($packageRows as $packageRow){$name=(string)$packageRow['required_package'];$selected=strcasecmp($name,$selectedPackage)===0;$url=game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name]);
            echo'<tr'.($selected?' class="gm-package-selected"':'').'><td><strong class="mono">'.catalog_h($name).'</strong></td><td class="num">'.(int)$packageRow['missing_object_rows'].'</td><td class="num">'.(int)$packageRow['requiring_file_count'].'</td><td class="gm-actions"><a class="button secondary" href="'.catalog_h($url).'">'.($selected?'Objects open':'Show objects').'</a></td></tr>';
            if(!$selected)continue;
            echo'<tr class="gm-package-detail"><td colspan="4"><details open><summary>'.catalog_h($name).' — missing object paths</summary>';
            echo'<form class="gm-filter" method="get"><input type="hidden" name="game_id" value="'.$gameId.'"><input type="hidden" name="dependency_type" value="'.catalog_h($type).'"><input type="hidden" name="package" value="'.catalog_h($name).'"><label>Object/path search<input name="object_q" value="'.catalog_h($objectSearch).'" placeholder="object or path"></label><button type="submit">Search objects</button></form>';
            if($objectRows===[])echo CatalogUi::emptyState('No missing object paths','Nothing currently matches this package/search.');
            else{echo'<table class="gm-table"><thead><tr><th>Required path</th><th>Reason</th><th class="num">Missing imports</th><th class="num">Affected files</th><th></th></tr></thead><tbody>';
                foreach($objectRows as $objectRow){$path=(string)$objectRow['required_object_path'];$isObject=strcasecmp($path,$selectedObject)===0;$highlight=$packageProviders===[]?0:gm_leaf_index($path);$reason=$packageProviders===[]?'Package not present':'Missing/rejected object';if($isObject&&is_array($diagnostic)){$highlight=(int)$diagnostic['highlight_from'];$reason=(string)$diagnostic['reason'];}
                    $objectUrl=game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage,'object'=>$path]);
                    echo'<tr'.($isObject?' class="gm-package-selected"':'').'><td class="gm-missing-path">'.gm_path_html($path,$highlight).'</td><td class="gm-reason">'.catalog_h($reason).'</td><td class="num">'.(int)$objectRow['missing_import_count'].'</td><td class="num">'.(int)$objectRow['affected_file_count'].'</td><td class="gm-actions"><a class="button secondary" href="'.catalog_h($objectUrl).'">'.($isObject?'Files open':'Show files').'</a></td></tr>';
                }
                echo'</tbody></table>';
            }
            if($objectTotal>$objectLimit){$pages=(int)ceil($objectTotal/$objectLimit);echo'<div class="gm-pager"><span>Page '.$objectPage.' of '.$pages.' · '.$objectTotal.' object paths</span>';if($objectPage>1)echo'<a class="button secondary" href="'.catalog_h(game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage-1])).'">Previous</a>';if($objectPage<$pages)echo'<a class="button secondary" href="'.catalog_h(game_missing_url($gameId,$type,['q'=>$packageSearch,'package'=>$name,'object_q'=>$objectSearch,'object_page'=>$objectPage+1])).'">Next</a>';echo'</div>';}
            if($selectedObject!==''&&$objectFiles!==[]){$h=is_array($diagnostic)?(int)$diagnostic['highlight_from']:gm_leaf_index($selectedObject);$reason=is_array($diagnostic)?(string)$diagnostic['reason']:'Missing/rejected object';
                echo'<div class="gm-note"><strong>'.catalog_h($reason).'</strong><div class="gm-missing-path">'.gm_path_html($selectedObject,$h).'</div></div>';
                echo'<h3>Affected files</h3><table class="gm-table gm-files"><thead><tr><th>File</th><th>Import</th><th>Expected class</th><th>Required path</th></tr></thead><tbody>';
                foreach($objectFiles as $fileRow){$class=implode('.',array_values(array_filter([(string)$fileRow['class_package'],(string)$fileRow['class_name']],static fn(string $v):bool=>$v!=='')));echo'<tr><td><a href="file-info.php?id='.(int)$fileRow['file_id'].'"><strong>'.catalog_h((string)$fileRow['owner_package_name']).'</strong></a><br><a class="muted" href="file-examine.php?id='.(int)$fileRow['file_id'].'">'.catalog_h((string)$fileRow['owner_original_name']).'</a></td><td>#'.(int)$fileRow['import_index'].'</td><td class="mono">'.catalog_h($class!==''?$class:'(not recorded)').'</td><td class="gm-missing-path">'.gm_path_html((string)$fileRow['required_object_path'],$h).'</td></tr>';}
                echo'</tbody></table>';
            }
            echo'</details></td></tr>';
        }
        echo'</tbody></table>';
    }
    echo'</div></section>';
    catalog_foot();
}catch(Throwable $error){
    if(!headers_sent())catalog_head('Game missing dependencies error');
    echo CatalogUi::alert('danger',$error->getMessage(),'The filtered missing-dependency page could not be loaded.');
    catalog_foot();
}
