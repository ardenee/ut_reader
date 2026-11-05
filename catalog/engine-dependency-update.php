<?php
/** Administrator source-policy v14 game updater. Durable jobs, no HTTP file scanning. */
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

try{
    $config=catalog_config();
    $db=catalog_db($config);
    if(!catalog_require_admin_page('Engine Dependency Update'))exit;

    // Always derive the configured games from current DB; new games require no
    // hard-coded IDs, manual list maintenance or a separate registration step.
    $games=catalog_all($db,
        'SELECT g.id,g.name,g.slug,p.engine_key,COUNT(f.id) AS file_count,'
        .'COALESCE(SUM(f.scan_status="verified"),0) AS verified_count '
        .'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
        .'AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id '
        .'GROUP BY g.id,g.name,g.slug,p.engine_key ORDER BY g.name');
    foreach($games as &$game){
        $id=(int)$game['id'];$engine=(string)($game['engine_key']??'');
        $game['supported']=in_array(strtoupper($engine),['UE1','UE2','UE3','UE4','UE5'],true);
        // The statistics gate already verifies the full source-policy gate.
        // Avoid rescanning current games a second time for the same hashes.
        $game['stats_current']=$game['supported']
            && Uedb5DependencyPublicationScope::isGameStatsCurrent($db,$id,$engine);
        $game['policy_current']=$game['supported']
            && ($game['stats_current']
                || Uedb5DependencyPublicationScope::isGameCurrent($db,$id,$engine));
        $game['policy']=$game['supported']
            ? Uedb5GameDependencyPassService::policyForEngine($engine):'unsupported';
    }
    unset($game);

    $success=[];$errors=[];$workerState=null;
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        catalog_check_csrf('engine-dependency-update');
        if(($_POST['backup_confirmed']??'')!=='yes'
            || ($_POST['operator_confirmation']??'')!=='UPDATE'){
            throw new RuntimeException(
                'Confirm a verified production DB + metadata backup and enter UPDATE before queuing source publication.'
            );
        }
        $target=(int)($_POST['game_id']??0);
        if($target<0)throw new RuntimeException('Invalid game selection.');
        $queue=new PdoJobQueue($db);
        $queueName=(string)($config['queue']['name']??'catalog');
        $userId=(int)($_SESSION['user']['id']??0);
        // The all-games choice is one administrative decision: persist every
        // requested durable job together or none. On retry active dedupe keys
        // still prevent duplicate work.
        $db->beginTransaction();
        try{
        foreach($games as $game){
            $id=(int)$game['id'];
            if($target>0 && $id!==$target)continue;
            if(!$game['supported']){
                $errors[]='Skipped game #'.$id.' (no supported active engine profile).';
                continue;
            }
            if((int)$game['verified_count']<1){
                $errors[]='Skipped '.(string)$game['name'].' (no verified source files).';
                continue;
            }
            if($game['policy_current'] && $game['stats_current']){
                // Do not repeatedly rebuild known-current data.
                continue;
            }
            $jobId=$queue->enqueue(
                $queueName,JobType::UPDATE_ENGINE_GAME_DEPENDENCIES,
                ['game_id'=>$id,'requested_policy'=>(string)$game['policy']],
                30,null,'ue-v14-policy-game-'.$id,
                $userId>0?$userId:null,3
            );
            $success[]=['id'=>$id,'name'=>(string)$game['name'],'job_id'=>$jobId];
        }
        if($target>0 && !array_filter($games,static fn(array $g):bool=>(int)$g['id']===$target)){
            throw new RuntimeException('Selected game does not exist.');
        }
        $db->commit();
        }catch(Throwable $queueError){
            if($db->inTransaction())$db->rollBack();
            throw $queueError;
        }
        if($success!==[]){
            $workerState=(new CatalogQueueWorkerStarter($db,$config))->start(
                $queueName,true,$userId>0?$userId:null
            );
        }
    }

    catalog_head('Engine Dependency Update');
    echo CatalogUi::pageHeader('Engine Dependency Update',
        'Publish each game using its active engine-specific dependency policy. UE2 v15 no longer treats configured common package names as automatically satisfied.',
        ['Background Jobs'=>'background-jobs.php','Games'=>'games.php']);
    if($success!==[]){
        echo '<div class="card"><h2>Durable jobs queued</h2><p>'
            .count($success).' game(s) scheduled on the PRD worker.</p><ul>';
        foreach($success as $item){
            echo '<li>'.catalog_h($item['name']).': job #'.(int)$item['job_id'].'</li>';
        }
        echo '</ul><p><a href="background-jobs.php">Monitor or resume jobs</a></p></div>';
    } elseif(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && $errors===[]){
        echo '<div class="card"><p>All selected games already have current dependencies and cached statistics. No work was queued.</p></div>';
    }
    foreach($errors as $message){
        echo '<div class="card"><p>'.catalog_h($message).'</p></div>';
    }
    if(is_array($workerState) && (string)($workerState['worker_error']??'')!==''){
        echo '<div class="card"><p>Jobs are saved but the worker needs attention: '
            .catalog_h((string)$workerState['worker_error']).'</p></div>';
    }
    echo '<div class="card"><h2>Current source-policy state</h2>'
        .'<p>“Current” requires every verified file to have the exact active engine policy and source payload hash. '
        .'Updating statistics alone does not publish stale UE2–UE4 dependencies.</p>'
        .'<div class="ui-table-region"><table><thead><tr>'
        .'<th>Game</th><th>Engine</th><th>Verified files</th>'
        .'<th>Dependency publication</th><th>Statistics</th></tr></thead><tbody>';
    foreach($games as $game){
        $policyState=$game['policy_current']?'Current':'Needs current policy publication';
        $statsState=$game['stats_current']?'Current':'Unavailable until updated';
        echo '<tr><td>'.catalog_h((string)$game['name']).'</td><td>'
            .catalog_h((string)($game['engine_key']??'No active profile')).'</td><td>'
            .number_format((int)$game['verified_count']).'</td><td>'
            .catalog_h($game['supported']?$policyState:'Unsupported profile')
            .'</td><td>'.catalog_h($statsState).'</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="card"><h2>Queue current-policy dependency updates</h2>'
        .'<p>These are source-pinned, resumable Pass 2 jobs, not the legacy generic '
        .'“Dependency Refresh”. Only stale games are queued. A game with current Pass 2 but '
        .'stale cached totals receives a statistics refresh. Each game is isolated.</p>'
        .'<form method="post" action="engine-dependency-update.php">'
        .'<input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('engine-dependency-update')).'">'
        .'<label>Games <select name="game_id"><option value="0">All configured games needing an update</option>';
    foreach($games as $game){
        if(!$game['supported'])continue;
        echo '<option value="'.(int)$game['id'].'">'.catalog_h((string)$game['name']).'</option>';
    }
    echo '</select></label>'
        .'<p><label><input type="checkbox" name="backup_confirmed" value="yes" required> '
        .'I have verified a recoverable backup of the production database and UEDB5 metadata.</label></p>'
        .'<p><label>Type UPDATE to confirm <input type="text" name="operator_confirmation" '
        .'required pattern="UPDATE" autocomplete="off"></label></p>'
        .'<button type="submit" onclick="return confirm(\'Queue the selected game(s) for their current engine-specific dependency publication?\')">'
        .'Queue updates</button></form></div>';
    catalog_foot();
}catch(Throwable $error){
    catalog_head('Engine Dependency Update');
    // An authenticated administrator needs the actionable failed precondition;
    // do not expose internal maintenance details on an anonymous request.
    $message=catalog_support_is_admin()
        ? $error->getMessage()
        : catalog_exception_display_message($error);
    echo CatalogUi::alert('danger',$message,
        'No new engine dependency updates were confirmed.');
    catalog_foot();
}
