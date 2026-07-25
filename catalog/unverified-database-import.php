<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedMetadataRepair.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Repair Missing Unverified Metadata')) {
        exit;
    }
    catalog_unverified_schema_ensure($db);

    $sourceGameId = filter_input(INPUT_GET, 'source_game_id', FILTER_VALIDATE_INT);
    $sourceGameId = $sourceGameId === false || $sourceGameId === null ? -1 : (int)$sourceGameId;
    if ($sourceGameId < -1) {
        $sourceGameId = -1;
    }

    $games = catalog_all($db, 'SELECT id,name,slug FROM ue_games ORDER BY name');
    if ($sourceGameId > 0 && !array_filter($games, static fn(array $game): bool => (int)$game['id'] === $sourceGameId)) {
        $sourceGameId = -1;
    }

    $inventory = catalog_unverified_metadata_inventory($db, $config, $sourceGameId);
    $missing = array_values(array_filter(
        $inventory,
        static fn(array $item): bool => !empty($item['needs_repair'])
    ));
    $complete = count($inventory) - count($missing);
    $bytes = array_sum(array_map(static fn(array $item): int => (int)$item['size'], $inventory));

    catalog_head('Repair Missing Unverified Metadata');
    echo <<<'CSS'
<style>
.uvmr-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
.uvmr-toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px}
.uvmr-toolbar label{display:flex;flex-direction:column;gap:4px;min-width:250px}
.uvmr-status{padding:12px;border:1px solid var(--line2);border-radius:9px;background:rgba(255,255,255,.025);margin-bottom:14px}
.uvmr-list{margin:0;padding-left:22px}.uvmr-list li{margin:8px 0}.uvmr-list small{display:block;color:var(--muted)}
.uvmr-reasons{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px}.uvmr-reasons span{padding:2px 6px;border:1px solid var(--line2);border-radius:999px;font-size:11px;color:#ffd989}
@media(max-width:900px){.uvmr-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Repair Missing Unverified Metadata',
        'Scans only physical queue files whose database identity or Names/Imports/Exports inventory is incomplete. Complete files are not opened or reprocessed.',
        [
            'Unverified Files' => 'unverified-files.php',
            'Background Jobs' => 'background-jobs.php?queue=catalog%3Abucket-processing',
        ]
    );

    echo '<div class="uvmr-grid">'
        . '<div class="stat"><h2>' . count($inventory) . '</h2><p>Physical files in scope</p></div>'
        . '<div class="stat"><h2>' . $complete . '</h2><p>Metadata complete</p></div>'
        . '<div class="stat"><h2>' . count($missing) . '</h2><p>Need targeted repair</p></div>'
        . '<div class="stat"><h2>' . catalog_h(catalog_bytes($bytes)) . '</h2><p>Physical storage in scope</p></div>'
        . '</div>';

    echo '<form class="uvmr-toolbar" method="get">'
        . '<label>Source queue<select name="source_game_id">'
        . '<option value="-1"' . ($sourceGameId === -1 ? ' selected' : '') . '>Upload Bucket</option>'
        . '<option value="0"' . ($sourceGameId === 0 ? ' selected' : '') . '>All unverified queues</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label><button type="submit" class="secondary">Show queue</button></form>';

    echo '<input id="uvmr-csrf" type="hidden" value="' . catalog_h(catalog_csrf('unverified-database-import')) . '">';
    echo '<input id="uvmr-source" type="hidden" value="' . $sourceGameId . '">';

    if ($missing === []) {
        echo CatalogUi::alert(
            'success',
            'No missing metadata',
            'Every physical file in this queue has hashes, detected package information and matching Names/Imports/Exports row counts.'
        );
    } else {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Targeted repair queue</h2><p>'
            . 'Only the ' . count($missing) . ' incomplete file(s) below will be queued. Each file runs as a separate background job so one bad package cannot block the remaining repairs.'
            . '</p></div><button id="uvmr-start" type="button">Queue ' . count($missing) . ' repair job(s)</button></div>'
            . '<div class="ui-section__body"><div id="uvmr-status" class="uvmr-status" hidden></div><ol class="uvmr-list">';

        foreach (array_slice($missing, 0, 500) as $item) {
            echo '<li><strong>' . catalog_h((string)$item['original_name']) . '</strong> '
                . '<span class="muted">' . catalog_h((string)$item['queue_label']) . ' · '
                . catalog_h(catalog_bytes((int)$item['size'])) . '</span>'
                . '<small>' . ((int)$item['file_id'] > 0 ? 'DB file #' . (int)$item['file_id'] : 'No database row') . '</small>'
                . '<div class="uvmr-reasons">';
            foreach ((array)$item['missing_reasons'] as $reason) {
                echo '<span>' . catalog_h((string)$reason) . '</span>';
            }
            echo '</div></li>';
        }
        if (count($missing) > 500) {
            echo '<li class="muted">+' . (count($missing) - 500) . ' additional incomplete files will also be queued.</li>';
        }
        echo '</ol></div></section>';
    }

    echo <<<'JS'
<script>
(function(){
'use strict';
var button=document.getElementById('uvmr-start');if(!button)return;
var status=document.getElementById('uvmr-status');
button.addEventListener('click',async function(){
    if(button.dataset.complete==='1'){window.location.reload();return;}
    if(!window.confirm('Queue background repair jobs only for files with missing metadata?'))return;
    button.disabled=true;button.textContent='Queueing repairs…';status.hidden=false;status.textContent='Finding incomplete physical files and creating repair jobs…';
    var data=new FormData();
    data.append('csrf',document.getElementById('uvmr-csrf').value);
    data.append('source_game_id',document.getElementById('uvmr-source').value);
    try{
        var response=await fetch('unverified-database-import-action.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
        var text=await response.text(),payload;
        try{payload=JSON.parse(text);}catch(e){throw new Error('The server returned an invalid response: '+text.slice(0,400));}
        if(!response.ok||!payload.ok)throw new Error((payload.error||'Metadata repairs could not be queued.')+(payload.request_id?' Reference: '+payload.request_id:''));
        status.innerHTML='<strong>'+payload.queued+' repair job(s) queued or already active.</strong><br>'
            +(payload.worker_started?'The worker was started.':'The worker was already active or no new work was required.')
            +' <a href="background-jobs.php?queue='+encodeURIComponent(payload.queue)+'">Open Background Jobs</a>';
        button.dataset.complete='1';button.textContent='Refresh candidate list';button.disabled=false;
    }catch(error){status.textContent=error.message||'Metadata repairs could not be queued.';button.disabled=false;button.textContent='Queue repairs';}
});
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Metadata Repair Error');
    echo CatalogUi::alert('danger', 'The metadata repair page could not be loaded.', $error->getMessage());
    catalog_foot();
}
