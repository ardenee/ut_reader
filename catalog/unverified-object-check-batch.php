<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedObjectCheck.php';
require_once __DIR__ . '/lib/UploadProgress.php';

function uvb_tokens(): array {
    $raw = $_POST['tokens'] ?? [];
    if (is_string($raw)) $raw = [$raw];
    $out = [];
    if (is_array($raw)) foreach ($raw as $token) {
        if (is_string($token) && ($token = trim($token)) !== '') $out[$token] = true;
    }
    return array_slice(array_keys($out), 0, 1000);
}
function uvb_progress(string $token, array $state): void { if ($token !== '') upload_progress_write($token, $state); }
function uvb_marker(string $status, string $message = ''): void {
    echo '<div data-uvoc-result-status="' . catalog_h($status) . '" data-message="' . catalog_h($message) . '" hidden></div>';
}
function uvb_badge(string $text, string $tone): string {
    return '<span class="uvb-badge ' . catalog_h($tone) . '">' . catalog_h($text) . '</span>';
}
function uvb_note(array $classification): string {
    foreach ((array)($classification['notes'] ?? []) as $note) {
        $note = trim((string)$note);
        if ($note !== '' && !str_starts_with($note, 'Legacy package header') && !str_starts_with($note, 'UE4 package header')) return $note;
    }
    return '';
}
function uvb_game_rows(PDO $db, array $item, array $candidates, array $games): array {
    $byGame = [];
    foreach ($candidates as $candidate) $byGame[(int)$candidate['game_id']] = $candidate;
    $rows = [];
    foreach ($games as $game) {
        $id = (int)$game['id'];
        $candidate = $byGame[$id] ?? [];
        $refs = (int)($candidate['import_count'] ?? 0);
        $exact = (int)($candidate['exact_object_matches'] ?? 0);
        $owners = (int)($candidate['owner_count'] ?? 0);
        $unmatched = (int)($candidate['unmatched_object_count'] ?? max(0, $refs - $exact));
        $rate = $refs > 0 ? round($exact * 100 / $refs, 1) : null;
        try {
            $class = gp_classify_file($db, $id, (string)$item['path'], (string)$item['original_name']);
            $compatible = !empty($class['ok_for_selected_game']);
            $confidence = (string)($class['confidence'] ?? 'unknown');
            $reason = uvb_note($class);
        } catch (Throwable $e) {
            $compatible = false; $confidence = 'unknown'; $reason = $e->getMessage();
        }
        if ($compatible && $exact > 0) { $label = ($rate >= 75 || $exact === $refs) ? 'Likely usable' : 'Possible match'; $tone = $label === 'Likely usable' ? 'good' : 'warn'; $rank = 1; }
        elseif ($compatible && $refs > 0) { $label = 'Package-name match only'; $tone = 'warn'; $rank = 2; }
        elseif ($compatible) { $label = 'Profile compatible'; $tone = 'info'; $rank = 3; }
        elseif ($exact > 0 || $refs > 0) { $label = 'Evidence conflicts'; $tone = 'bad'; $rank = 4; }
        else { $label = 'Not compatible'; $tone = 'bad'; $rank = 5; }
        $rows[] = [
            'id'=>$id,'name'=>(string)$game['name'],'profile'=>(string)($game['profile_name'] ?? ''),'engine'=>(string)($game['engine_key'] ?? ''),
            'compatible'=>$compatible,'confidence'=>$confidence,'reason'=>$reason,'refs'=>$refs,'exact'=>$exact,'owners'=>$owners,'unmatched'=>$unmatched,
            'rate'=>$rate,'label'=>$label,'tone'=>$tone,'rank'=>$rank,
        ];
    }
    usort($rows, static fn($a,$b) => ($a['rank'] <=> $b['rank']) ?: ($b['exact'] <=> $a['exact']) ?: (($b['rate'] ?? -1) <=> ($a['rate'] ?? -1)) ?: ($b['owners'] <=> $a['owners']) ?: strcmp($a['name'],$b['name']));
    return $rows;
}
function uvb_best(array $rows): ?array {
    foreach ($rows as $row) if ($row['compatible'] && $row['exact'] > 0) return $row;
    foreach ($rows as $row) if ($row['compatible'] && $row['refs'] > 0) return $row;
    return null;
}
function uvb_match(array $row): string {
    if ($row['refs'] < 1) return 'No package references';
    $rate = rtrim(rtrim(number_format((float)$row['rate'], 1), '0'), '.');
    return $row['exact'] . ' / ' . $row['refs'] . ' exact (' . $rate . '%)';
}
function uvb_table(array $rows, bool $actions): void {
    echo '<div class="table-wrap"><table class="uvb-games"><thead><tr><th>Game</th><th>Assessment</th><th>Profile</th><th>Object matches</th><th>Requiring files</th><th>Action</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $profile = trim($row['engine'] . ($row['profile'] !== '' ? ' · ' . $row['profile'] : ''));
        echo '<tr class="' . catalog_h($row['tone']) . '"><td><strong><a target="_blank" rel="noopener" href="game-files.php?id=' . $row['id'] . '">' . catalog_h($row['name']) . '</a></strong></td>';
        echo '<td>' . uvb_badge($row['label'], $row['tone']) . ($row['reason'] !== '' ? '<small>' . catalog_h($row['reason']) . '</small>' : '') . '</td>';
        echo '<td><span class="mono">' . catalog_h($profile !== '' ? $profile : 'No active profile') . '</span><small>Confidence: ' . catalog_h($row['confidence']) . '</small></td>';
        echo '<td><strong>' . catalog_h(uvb_match($row)) . '</strong>' . ($row['unmatched'] > 0 ? '<small>' . $row['unmatched'] . ' required object(s) not exported</small>' : '') . '</td>';
        echo '<td>' . $row['owners'] . '</td><td>';
        if ($actions && $row['compatible']) echo '<button type="button" class="button secondary" data-game-id="' . $row['id'] . '" data-game-name="' . catalog_h($row['name']) . '">Set bulk import target</button>';
        else echo '<span class="muted">—</span>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

$progressToken = '';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST is required for a batch Object Check.');
    if (!catalog_support_is_admin()) throw new RuntimeException('Administrator login is required.');
    catalog_check_csrf('unverified-files');
    $progressToken = upload_progress_token(trim((string)($_POST['progress_token'] ?? '')));
    $tokens = uvb_tokens();
    if (!$tokens) throw new RuntimeException('Select at least one queued file before running Object Check.');
    $config = catalog_config(); $db = catalog_db($config); $total = count($tokens);
    $games = catalog_all($db, 'SELECT g.id,g.name,p.profile_name,p.engine_key FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name');
    uvb_progress($progressToken, ['stage'=>'starting','done'=>0,'total'=>$total,'percent'=>0,'current_index'=>0,'file_percent'=>0,'message'=>'Starting queued package Object Check']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $checks = [];
    foreach ($tokens as $index => $token) {
        $progress = $progressToken === '' ? null : static function(array $state) use($progressToken,$index,$total): void {
            $filePercent = max(0,min(100,(int)($state['percent'] ?? 0)));
            uvb_progress($progressToken, ['stage'=>(string)($state['stage'] ?? 'checking'),'done'=>$index,'total'=>$total,'percent'=>min(96,(int)floor((($index+$filePercent/100)/max(1,$total))*96)),'current_index'=>$index,'file_percent'=>$filePercent,'message'=>'File '.($index+1).' of '.$total.': '.(string)($state['message'] ?? 'Checking package')]);
        };
        try {
            $result = uvoc_check($db,$config,$token,$progress);
            $reader = is_array($result['reader'] ?? null) ? $result['reader'] : null;
            uvb_progress($progressToken, ['stage'=>'check_game_profiles','done'=>$index,'total'=>$total,'percent'=>min(98,(int)floor((($index+.98)/max(1,$total))*98)),'current_index'=>$index,'file_percent'=>98,'message'=>'File '.($index+1).' of '.$total.': Checking compatibility with every game profile']);
            $rows = uvb_game_rows($db,$result['item'],(array)($result['candidates'] ?? []),$games);
            $checks[] = ['token'=>$token,'item'=>$result['item'],'reader'=>$reader,'rows'=>$rows,'best'=>uvb_best($rows),'analysis_error'=>is_array($result['analysis_error'] ?? null)?$result['analysis_error']:null,'error'=>null];
            uvb_progress($progressToken, ['stage'=>'file_complete','done'=>$index+1,'total'=>$total,'percent'=>min(98,(int)floor((($index+1)/$total)*98)),'current_index'=>$index,'file_percent'=>100,'message'=>'Completed '.($index+1).' of '.$total.': '.(string)$result['item']['original_name']]);
        } catch (Throwable $e) {
            error_log('[UnrealDB object check batch] '.$e->getMessage());
            $checks[] = ['token'=>$token,'item'=>null,'reader'=>null,'rows'=>[],'best'=>null,'analysis_error'=>null,'error'=>$e->getMessage()];
        }
    }
    uvb_progress($progressToken, ['stage'=>'rendering','done'=>$total,'total'=>$total,'percent'=>99,'current_index'=>max(0,$total-1),'file_percent'=>100,'message'=>'Rendering ranked game compatibility results']);
    catalog_head('Queued Package Object Check');
    echo <<<'HTML'
<style>
.site-header{display:none!important}body{background:#08101d}main{max-width:none;padding:14px}.uvb-help{margin-bottom:12px}.uvb-toolbar{position:sticky;top:0;z-index:4;display:flex;gap:8px;padding:9px;margin-bottom:10px;border:1px solid var(--line2);border-radius:8px;background:#0d1728}.uvb-toolbar input{flex:1;min-width:240px}.uvb-card{margin-bottom:9px;border:1px solid var(--line2);border-radius:9px;overflow:hidden;background:rgba(255,255,255,.02)}.uvb-card>summary{display:grid;grid-template-columns:minmax(260px,1.3fr) minmax(220px,1fr) auto;gap:12px;align-items:center;padding:13px 15px;cursor:pointer;list-style:none}.uvb-card>summary::-webkit-details-marker{display:none}.uvb-card>summary:hover{background:rgba(255,255,255,.035)}.uvb-body{padding:13px 15px;border-top:1px solid var(--line2)}.uvb-meta,.uvb-sub,.uvb-games small{display:block;margin-top:3px;color:var(--muted);font-size:12px}.uvb-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:7px;margin:10px 0}.uvb-stat{padding:8px;border:1px solid var(--line2);border-radius:7px}.uvb-stat strong{display:block;font-size:16px}.uvb-stat span{font-size:11px;color:var(--muted)}.uvb-decision{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:11px 13px;margin:11px 0;border:1px solid var(--line2);border-radius:8px;background:rgba(72,132,255,.08)}.uvb-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}.uvb-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.uvb-badge.warn{color:#ffe1a0;background:rgba(246,196,83,.14)}.uvb-badge.info{color:#b8d7ff;background:rgba(72,132,255,.15)}.uvb-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.uvb-games{min-width:980px;width:100%}.uvb-games td{vertical-align:top}.uvb-games tr.good td:first-child{box-shadow:inset 4px 0 #43be6e}.uvb-games tr.warn td:first-child{box-shadow:inset 4px 0 #f6c453}.uvb-games tr.info td:first-child{box-shadow:inset 4px 0 #4884ff}.uvb-games tr.bad{opacity:.72}.uvb-games tr.bad td:first-child{box-shadow:inset 4px 0 #d85a5a}.uvb-other{margin-top:10px}.uvb-actions{display:flex;justify-content:flex-end;margin-top:10px}@media(max-width:900px){.uvb-card>summary{grid-template-columns:1fr}.uvb-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>
HTML;
    echo CatalogUi::pageHeader('Queued Package Object Check',$total.' selected file(s). Games are ranked using scanner-profile compatibility and exact catalogue dependency/object matches.');
    echo '<div class="uvb-help">'.CatalogUi::alert('info','How to read the result','Likely usable means the game profile accepts the file and files in that game require objects it actually exports. Profile compatible means it can pass that game profile, but UnrealDB has no exact catalogue evidence. Matching is exact package name plus exact required object paths; no fuzzy filename guessing is used.').'</div>';
    echo '<div class="uvb-toolbar"><input id="uvb-filter" type="search" placeholder="Filter by filename, package or possible game"><button id="uvb-expand" type="button" class="button secondary">Expand all</button><button id="uvb-collapse" type="button" class="button secondary">Collapse all</button></div>';
    foreach ($checks as $index => $check) {
        if ($check['item'] === null) {
            echo '<details class="uvb-card" data-card open><summary><strong>Selected file '.($index+1).'</strong><span></span>'.uvb_badge('Check failed','bad').'</summary><div class="uvb-body">'.CatalogUi::alert('danger','Object Check failed',(string)$check['error']).'</div></details>';
            continue;
        }
        $item=$check['item']; $reader=$check['reader']; $rows=$check['rows']; $best=$check['best'];
        $possible=array_values(array_filter($rows,static fn($r)=>$r['rank']<=4));
        $blocked=array_values(array_filter($rows,static fn($r)=>$r['rank']===5));
        $compatible=count(array_filter($rows,static fn($r)=>$r['compatible']));
        if ($best) { $bestName=$best['name']; $bestSub=uvb_match($best).' · '.$best['label']; $badge=uvb_badge($best['label'],$best['tone']); }
        else { $bestName=$compatible ? $compatible.' profile-compatible game(s)' : 'No compatible game'; $bestSub=$compatible?'No exact catalogue-backed recommendation':'Do not import without override'; $badge=uvb_badge($compatible?'Needs review':'No match',$compatible?'info':'bad'); }
        $engine=(string)($reader['engine'] ?? ($item['header']['engine'] ?? 'Unknown')); $version=$item['header']['version']??null; $licensee=$item['header']['licensee']??null;
        $search=strtolower($item['original_name'].' '.$item['package_name'].' '.implode(' ',array_column($possible,'name')));
        echo '<details class="uvb-card" data-card data-search="'.catalog_h($search).'"><summary><div><strong>'.catalog_h($item['original_name']).'</strong><div class="uvb-meta">Package: <span class="mono">'.catalog_h($item['package_name']).'</span> · queued in '.catalog_h($item['game']['name']).'</div></div><div><strong>'.catalog_h($bestName).'</strong><div class="uvb-sub">'.catalog_h($bestSub).'</div></div>'.$badge.'</summary><div class="uvb-body">';
        if ($check['analysis_error']) echo CatalogUi::alert('warning','Object tables could not be read',(string)($check['analysis_error']['message']??''));
        echo '<div class="uvb-stats"><div class="uvb-stat"><strong>'.catalog_h($engine).'</strong><span>Engine</span></div><div class="uvb-stat"><strong>'.catalog_h($version===null?'—':$version).'</strong><span>Package version</span></div><div class="uvb-stat"><strong>'.catalog_h($licensee===null?'—':$licensee).'</strong><span>Licensee</span></div><div class="uvb-stat"><strong>'.(int)($reader['name_count']??0).'</strong><span>Names</span></div><div class="uvb-stat"><strong>'.(int)($reader['import_count']??0).'</strong><span>Imports</span></div><div class="uvb-stat"><strong>'.(int)($reader['export_count']??0).'</strong><span>Exports</span></div></div>';
        if ($best) { $title=$best['exact']>0?'Recommended import target':'Tentative import target'; echo '<div class="uvb-decision"><div><strong>'.catalog_h($title.': '.$best['name']).'</strong><div class="uvb-sub">'.catalog_h(uvb_match($best)).'; '.$best['owners'].' catalogue file(s) require this package.</div></div>'.uvb_badge($best['label'],$best['tone']).'</div>'; }
        elseif ($compatible) echo CatalogUi::alert('warning','No catalogue-backed recommendation',$compatible.' game profile(s) accept this file, but no exact dependency/object matches identify the best game.');
        else echo CatalogUi::alert('danger','No compatible game profile','Do not import this file unless the profile mismatch is understood and the override is intentional.');
        if ($possible) { echo '<h3>Ranked game targets</h3>'; uvb_table($possible,true); }
        else echo CatalogUi::emptyState('No possible game targets','No profile or exact catalogue evidence supports a target game.');
        if ($blocked) { echo '<details class="uvb-other"><summary>Show '.count($blocked).' incompatible game(s)</summary>'; uvb_table($blocked,false); echo '</details>'; }
        echo '<div class="uvb-actions"><a class="button secondary" target="_blank" rel="noopener" href="unverified-object-check.php?token='.rawurlencode($check['token']).'">Open full package tables</a></div></div></details>';
    }
    echo <<<'JS'
<script>
(function(){'use strict';var cards=[].slice.call(document.querySelectorAll('[data-card]')),f=document.getElementById('uvb-filter');if(f)f.addEventListener('input',function(){var q=f.value.trim().toLowerCase();cards.forEach(function(c){c.hidden=q!==''&&(c.getAttribute('data-search')||c.textContent.toLowerCase()).indexOf(q)===-1;});});document.getElementById('uvb-expand').addEventListener('click',function(){cards.forEach(function(c){if(!c.hidden)c.open=true;});});document.getElementById('uvb-collapse').addEventListener('click',function(){cards.forEach(function(c){c.open=false;});});document.addEventListener('click',function(e){var b=e.target.closest('[data-game-id]');if(!b)return;try{var form=window.parent.document.getElementById('unverified-bulk-form'),target=form&&form.querySelector('[name="target_game_id"]');if(!target)throw new Error('The import target selector is unavailable.');target.value=b.getAttribute('data-game-id');target.dispatchEvent(new Event('change',{bubbles:true}));var status=window.parent.document.querySelector('.unverified-action-current');if(status)status.textContent='Import target set to '+b.getAttribute('data-game-name')+'. Close results, then press Import selected.';b.textContent='Target selected';b.disabled=true;}catch(err){window.alert(err.message||'The import target could not be selected.');}});})();
</script>
JS;
    uvb_marker('complete');
    uvb_progress($progressToken, ['stage'=>'done','done'=>$total,'total'=>$total,'percent'=>100,'current_index'=>max(0,$total-1),'file_percent'=>100,'message'=>'Object Check complete']);
    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB object check batch] '.$e->getMessage());
    uvb_progress($progressToken, ['stage'=>'failed','done'=>0,'total'=>0,'percent'=>100,'message'=>'Object Check failed: '.$e->getMessage()]);
    catalog_head('Queued Package Object Check Error');
    echo CatalogUi::alert('danger','Queued package Object Check could not be opened.',$e->getMessage());
    uvb_marker('error',$e->getMessage()); catalog_foot();
}
