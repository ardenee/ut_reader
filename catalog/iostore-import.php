<?php
declare(strict_types=1);

require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreUploadStager;
use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicIoStoreReviewService;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;

catalog_start_session();
try {
    $config=catalog_config();
    $db=catalog_db($config);
    if(!catalog_require_admin_page('IoStore Container Import'))exit;

    $installed=CatalogIoStoreContainerIndexer::schemaInstalled($db);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        catalog_check_csrf('iostore-import');
        if(!$installed)throw new RuntimeException('IoStore index migration 202610150001 must be applied first.');
        $gameId=filter_input(INPUT_POST,'game_id',FILTER_VALIDATE_INT);
        if ((string)($_POST['action'] ?? '') === 'approve_public_iostore') {
            $reviewId=trim((string)($_POST['review_key']??''));
            $result=(new CatalogPublicIoStoreReviewService($db,$config))->approve(
                $reviewId,
                is_int($gameId)?$gameId:0,
                (int)($_SESSION['user']['id']??0)
            );
            $queueName=trim((string)($config['queue']['name']??'catalog'))?:'catalog';
            $worker=(new CatalogQueueWorkerStarter($db,$config))->start(
                $queueName,true,(int)($_SESSION['user']['id']??0)
            );
            $_SESSION['iostore_import_flash']='Approved complete quarantined IoStore contribution ('
                .$result['parts'].' partition(s)); retained container job #'.$result['job_id'].' queued.'
                .((string)($worker['worker_error']??'')!==''?' Worker start warning: '.(string)$worker['worker_error']:'');
            header('Location: iostore-import.php?game_id='.$result['game_id'].'&job_id='.$result['job_id']);
            exit;
        }
        $utoc=trim((string)($_POST['local_utoc_path']??''));
        if(!is_int($gameId)||$gameId<1) {
            throw new RuntimeException('Select an active UE5 game.');
        }
        $profile=$db->prepare('SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? AND UPPER(p.engine_key) LIKE "UE5%"');
        $profile->execute([$gameId]);
        if($profile->fetchColumn()===false)throw new RuntimeException('IoStore imports require an active UE5 game profile.');
        $browser=isset($_FILES['utoc_file'])&&is_array($_FILES['utoc_file'])
            &&(int)($_FILES['utoc_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($browser){
            (new CatalogPublicAccessGuard($config))->transferAllowedOrThrow($db,'Upload');
            $staged=(new CatalogIoStoreUploadStager((string)$config['storage_path']))
                ->stage($_FILES['utoc_file'],(array)($_FILES['ucas_files']??[]));
            $source=$staged['path'];
        }else{
            if(!str_ends_with(strtolower($utoc),'.utoc'))throw new RuntimeException('Provide a local .utoc path or upload a complete IoStore pair.');
            $source=realpath($utoc);
            if($source===false||!is_file($source)||is_link($utoc)||!is_readable($source)){
                throw new RuntimeException('Selected .utoc source is not a readable regular file.');
            }
        }
        // Always enqueue a fresh validation: identical TOC bytes can accompany
        // a changed/corrupt UCAS payload. Retention enforces final idempotency.
        $digest=hash_file('sha256',$source);
        if(!is_string($digest))throw new RuntimeException('Could not hash the local TOC.');
        $queueName=trim((string)($config['queue']['name']??'catalog'))?:'catalog';
        $jobId=(new PdoJobQueue($db))->enqueue(
            $queueName,
            JobType::IMPORT_IOSTORE_CONTAINER,
            ['game_id'=>$gameId,'local_utoc_path'=>$source,'toc_sha256'=>$digest],
            100,null,
            null,
            (int)($_SESSION['user']['id']??0)?:null,
            3
        );
        $worker=(new CatalogQueueWorkerStarter($db,$config))->start($queueName,true,
            (int)($_SESSION['user']['id']??0)?:null);
        $_SESSION['iostore_import_flash']='IoStore container retention/index job #'.$jobId.' queued.'
            .((string)($worker['worker_error']??'')!==''?' Worker start warning: '.(string)$worker['worker_error']:'');
        header('Location: iostore-import.php?game_id='.$gameId.'&job_id='.$jobId);
        exit;
    }
    $games=catalog_all($db,'SELECT g.id,g.name FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE UPPER(p.engine_key) LIKE "UE5%" ORDER BY g.name',[]);
    $selected=max(0,(int)($_GET['game_id']??0));
    catalog_head('Package Viewer: IoStore Import');
    catalog_flash($_SESSION['iostore_import_flash']??null);
    unset($_SESSION['iostore_import_flash']);
    catalog_page_header('IoStore container import',
        'Retain a local .utoc and all matching .ucas partitions, then index its Zen package IDs in a durable background job.',
        ['Package Viewer'=>'pakages.php','Background Jobs'=>'background-jobs.php']);
    if(!$installed){
        echo CatalogUi::alert('warning','IoStore schema migration 202610150001 is pending. Import is disabled until the migration is applied.');
    }elseif($games===[]){
        echo CatalogUi::alert('warning','Create/select an active UE5 game before importing IoStore containers.');
    }else{
        echo '<section class="ui-section"><div class="ui-section__header"><h2>Import an existing local container set</h2></div><div class="ui-section__body">';
        echo '<form method="post" enctype="multipart/form-data" data-ui-loading-form>';
        echo '<input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('iostore-import')).'">';
        echo '<label>UE5 game <select name="game_id" required>';
        foreach($games as $game){
            $id=(int)$game['id'];
            echo '<option value="'.$id.'"'.($id===$selected?' selected':'').'>'.catalog_h((string)$game['name']).'</option>';
        }
        echo '</select></label>';
        echo '<p><label>Local server .utoc path <input type="text" name="local_utoc_path" style="width:min(100%,760px)" placeholder="C:\\Games\\Example\\Content\\Paks\\pakchunk0.utoc"></label></p>';
        echo '<p><label>Or upload .utoc <input type="file" name="utoc_file" accept=".utoc"></label></p>';
        echo '<p><label>Matching .ucas file(s) <input type="file" name="ucas_files[]" accept=".ucas" multiple></label></p>';
        echo '<p>For large containers, use the local server path. Browser uploads must include all matching partitions and fit PHP upload limits; staged parts persist for durable job retries. This indexes the container but does not yet publish individual Zen packages as catalogue files.</p>';
        echo '<button type="submit">Queue IoStore container import</button></form></div></section>';
        $pending=(new CatalogPublicIoStoreReviewService($db,$config))->pending();
        if($pending!==[]){
            echo '<section class="ui-section"><div class="ui-section__header"><h2>Public IoStore contributions awaiting administrator review</h2></div><div class="ui-section__body">';
            echo '<p>These containers remain in public quarantine. Nothing is imported until an administrator approves a complete .utoc/.ucas family for an active UE5 game.</p>';
            foreach($pending as $candidate){
                echo '<div class="card"><strong>'.catalog_h((string)$candidate['name']).'</strong> — '
                    .(int)$candidate['toc_count'].' TOC, '.(int)$candidate['part_count']
                    .' data file(s), '.catalog_bytes((int)$candidate['total_bytes']).'.';
                if(!$candidate['ready']){
                    echo ' <span class="muted">Incomplete or duplicate file names — awaiting a valid pair.</span></div>';
                    continue;
                }
                echo '<form method="post" data-ui-loading-form><input type="hidden" name="csrf" value="'
                    .catalog_h(catalog_csrf('iostore-import')).'">'
                    .'<input type="hidden" name="action" value="approve_public_iostore">'
                    .'<input type="hidden" name="review_key" value="'.catalog_h((string)$candidate['key']).'">'
                    .'<label>Review for UE5 game <select name="game_id" required>';
                foreach($games as $game){
                    $id=(int)$game['id'];
                    echo '<option value="'.$id.'"'.($id===$selected?' selected':'').'>'
                        .catalog_h((string)$game['name']).'</option>';
                }
                echo '</select></label> <button type="submit">Approve and queue IoStore container</button></form></div>';
            }
            echo '</div></section>';
        }
    }
    catalog_foot();
}catch(Throwable $e){
    if(!headers_sent())catalog_head('IoStore import error');
    echo CatalogUi::alert('danger',catalog_exception_display_message($e));
    catalog_foot();
}
