<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Index Existing Unverified Files')) exit;
    catalog_unverified_schema_ensure($db);

    // Backfill creates staging rows only. Queue ownership is recorded separately
    // in unverified_queue_game_id; no unverified row is assigned to a game.
    $db->exec('UPDATE ue_files SET game_id=NULL WHERE scan_status="unverified" AND game_id IS NOT NULL');

    $items = uvf_list($db, $config, null);
    $missing = [];
    $indexed = 0;
    foreach ($items as $item) {
        if (catalog_unverified_find($db, (int)$item['game']['id'], (string)$item['queue_name'])) {
            $indexed++;
        } else {
            $missing[] = $item;
        }
    }

    catalog_head('Index Existing Unverified Files');
    echo <<<'CSS'
<style>
.uvbi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.uvbi-progress{padding:14px;border:1px solid var(--line2);border-radius:10px;background:rgba(255,255,255,.025)}.uvbi-progress progress{width:100%;height:18px}.uvbi-log{max-height:420px;overflow:auto;margin:10px 0 0;padding:10px 10px 10px 30px;border:1px solid var(--line2);border-radius:8px}.uvbi-log .good{color:#b8f3cb}.uvbi-log .bad{color:#ffb5b5}@media(max-width:800px){.uvbi-grid{grid-template-columns:1fr}}
</style>
CSS;
    echo CatalogUi::pageHeader('Index Existing Unverified Files', 'Backfill filesystem-only queue files into ue_files with scan_status=unverified and game_id=NULL. The physical source queue is remembered separately; files are not assigned to a game until Import selected.', ['Unverified Files' => 'unverified-files.php']);
    echo '<div class="uvbi-grid"><div class="stat"><h2>' . count($items) . '</h2><p>Physical queue files</p></div><div class="stat"><h2>' . $indexed . '</h2><p>Already indexed, no game assigned</p></div><div class="stat"><h2>' . count($missing) . '</h2><p>Need indexing</p></div></div>';

    if ($missing === []) {
        echo CatalogUi::alert('success', 'Backfill complete', 'Every physical unverified file already has a staging database row with no game assignment.');
    } else {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Backfill queue</h2><p>Processes one file per request and records readable Names, Imports and Exports.</p></div></div><div class="ui-section__body">';
        echo '<input id="uvbi-csrf" type="hidden" value="' . catalog_h(catalog_csrf('unverified-database-import')) . '">';
        echo '<button id="uvbi-start" type="button">Index ' . count($missing) . ' file(s)</button> <button id="uvbi-stop" type="button" class="secondary" disabled>Stop after current file</button>';
        echo '<div id="uvbi-progress" class="uvbi-progress" hidden><div id="uvbi-current">Waiting…</div><progress id="uvbi-bar" value="0" max="100"></progress><ol id="uvbi-log" class="uvbi-log"></ol></div>';
        echo '<script id="uvbi-data" type="application/json">' . json_encode(array_map(static fn(array $item): array => ['token' => $item['token'], 'name' => $item['original_name'], 'queue' => $item['game']['name']], $missing), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '</div></section>';
    }

    echo <<<'JS'
<script>
(function(){
'use strict';
var start=document.getElementById('uvbi-start');if(!start)return;
var stop=document.getElementById('uvbi-stop'),box=document.getElementById('uvbi-progress'),bar=document.getElementById('uvbi-bar'),current=document.getElementById('uvbi-current'),log=document.getElementById('uvbi-log'),csrf=document.getElementById('uvbi-csrf').value;
var items=JSON.parse(document.getElementById('uvbi-data').textContent||'[]'),stopping=false;
function add(text,ok){var li=document.createElement('li');li.className=ok?'good':'bad';li.textContent=text;log.appendChild(li);log.scrollTop=log.scrollHeight;}
stop.addEventListener('click',function(){stopping=true;stop.disabled=true;stop.textContent='Stopping after current file…';});
start.addEventListener('click',async function(){start.disabled=true;stop.disabled=false;box.hidden=false;var done=0,failed=0;for(var i=0;i<items.length;i++){if(stopping)break;var item=items[i];current.textContent=(i+1)+' of '+items.length+': '+item.name+' ('+item.queue+')';var data=new FormData();data.append('csrf',csrf);data.append('token',item.token);try{var response=await fetch('unverified-database-import-action.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});var payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.error||'Index failed');done++;add(item.name+': '+(payload.message||payload.status),true);}catch(error){failed++;add(item.name+': '+(error.message||'failed'),false);}bar.value=Math.round(((i+1)/items.length)*100);}current.textContent=stopping?'Stopped. Indexed '+done+', failed '+failed+'.':'Complete. Indexed '+done+', failed '+failed+'.';stop.hidden=true;start.textContent='Refresh page';start.disabled=false;start.onclick=function(){window.location.reload();};});
})();
</script>
JS;
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Unverified Backfill Error');
    echo CatalogUi::alert('danger', 'The unverified backfill page could not be loaded.', $error->getMessage());
    catalog_foot();
}
