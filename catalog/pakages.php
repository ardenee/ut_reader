<?php
/**
 * Consolidated retained package-container directory.
 *
 * .pak: retained archive record (UE4/UE5)
 * .upk: original verified UE3 package file, with serialised UObject exports
 * .utoc: retained UE5 IoStore container index
 * .ucas: individually retained and SHA-256-verified IoStore data partitions
 *
 * Per-game PAK/UPK and IoStore detail pages remain authoritative.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $pakInstalled = CatalogPakArchiveStore::schemaInstalled($db);
    $ioInstalled = CatalogIoStoreContainerIndexer::schemaInstalled($db);

    catalog_head('Package archives');
    catalog_page_header(
        'Package archives',
        'Browse retained original .pak, .upk, .utoc and .ucas files. IoStore data partitions belong to their .utoc container; a UPK holds UObject exports rather than independent child packages.',
        [
            'Games' => 'games.php',
            'Upload files' => 'profiled-upload.php',
            'Import PAK' => 'pak-import.php',
            'Import IoStore' => 'iostore-import.php',
            'Background jobs' => 'background-jobs.php',
        ]
    );
    if (!$pakInstalled && !$ioInstalled) {
        echo CatalogUi::alert('warning', 'Package-container management is not installed. Run php catalog/bin/migrate.php migrate.');
        catalog_foot();
        exit;
    }

    $games = catalog_all($db,
        'SELECT g.id,g.name,g.slug,p.engine_key FROM ue_games g '
        . 'JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key) LIKE "UE3%" '
        . 'OR UPPER(p.engine_key) LIKE "UE4%" '
        . 'OR UPPER(p.engine_key) LIKE "UE5%" '
        . 'ORDER BY g.name', []
    );
    $summary = [];
    foreach ($games as $game) {
        $id = (int)$game['id'];
        $summary[$id] = [
            'game'=>$game, 'pak'=>0, 'upk'=>0, 'utoc'=>0, 'ucas'=>0,
            'pak_bytes'=>0, 'upk_bytes'=>0, 'ucas_bytes'=>0,
            'pak_entries'=>0, 'upk_exports'=>0, 'zen_packages'=>0,
        ];
    }

    // One grouped query per physical format, not N correlated subqueries for
    // every displayed game. UPK identity remains the existing verified ue_files
    // record; IoStore partitions are physical retention records, not ue_files.
    $totalsSql = [
        'upk' => 'SELECT f.game_id,COUNT(*) file_count,COALESCE(SUM(f.file_size),0) bytes,'
            . 'COALESCE(SUM(f.export_count),0) child_count FROM ue_files f '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE f.scan_status="verified" AND LOWER(f.extension)="upk" '
            . 'AND UPPER(p.engine_key) LIKE "UE3%" GROUP BY f.game_id',
    ];
    if ($pakInstalled) {
        $totalsSql['pak'] = 'SELECT game_id,COUNT(*) file_count,COALESCE(SUM(file_size),0) bytes,'
            . 'COALESCE(SUM(entry_count),0) child_count FROM ue_pak_archives GROUP BY game_id';
    }
    if ($ioInstalled) {
        $totalsSql['utoc'] = 'SELECT game_id,COUNT(*) file_count,0 bytes,'
            . 'COALESCE(SUM(package_count),0) child_count FROM ue_iostore_containers GROUP BY game_id';
        $totalsSql['ucas'] = 'SELECT c.game_id,COUNT(*) file_count,'
            . 'COALESCE(SUM(p.file_size),0) bytes,0 child_count '
            . 'FROM ue_iostore_container_parts p JOIN ue_iostore_containers c '
            . 'ON c.id=p.container_id GROUP BY c.game_id';
    }
    foreach ($totalsSql as $format => $query) {
        foreach (catalog_all($db, $query, []) as $record) {
            $id = (int)$record['game_id'];
            if (!isset($summary[$id])) {
                continue;
            }
            $summary[$id][$format] = (int)$record['file_count'];
            $summary[$id][$format . '_bytes'] = (int)$record['bytes'];
            $childKey = match ($format) {
                'pak'=>'pak_entries', 'upk'=>'upk_exports', 'utoc'=>'zen_packages', default=>null,
            };
            if ($childKey !== null) {
                $summary[$id][$childKey] = (int)$record['child_count'];
            }
        }
    }

    $selectedGame = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);
    $selectedGame = is_int($selectedGame) && isset($summary[$selectedGame]) ? $selectedGame : 0;
    $format = strtolower(trim((string)($_GET['format'] ?? 'all')));
    $format = in_array($format, ['all','pak','upk','utoc','ucas'], true) ? $format : 'all';
    $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Retained archive collections</h2>'
        . '<p>Each game links to its full PAK, UPK or IoStore package browser. The file directory below lists the original containers and their individual data partitions.</p>'
        . '</div></div><div class="ui-section__body">';
    if ($summary === []) {
        echo CatalogUi::emptyState('No UE3–UE5 games configured', 'Assign an engine profile before importing package archives.', null, '');
    } else {
        echo '<div class="grid">';
        foreach ($summary as $gameId=>$info) {
            $game = $info['game'];
            $engine = strtoupper((string)$game['engine_key']);
            $isUe3 = str_starts_with($engine, 'UE3');
            $isUe5 = str_starts_with($engine, 'UE5');
            echo '<div class="card"><h3>'.catalog_h((string)$game['name']).'</h3>'
                . '<p class="mono small">'.catalog_h((string)$game['engine_key']).'</p>'
                . '<p><strong>'.number_format($info['pak']).'</strong> .pak'
                . ' &nbsp; <strong>'.number_format($info['upk']).'</strong> .upk'
                . ' &nbsp; <strong>'.number_format($info['utoc']).'</strong> .utoc'
                . ' &nbsp; <strong>'.number_format($info['ucas']).'</strong> .ucas</p>';
            if ($isUe3) {
                echo '<p>'.number_format($info['upk_exports']).' indexed UPK exports, '
                    .catalog_h(catalog_bytes($info['upk_bytes'])).' retained</p>'
                    . '<a class="button primary" href="game-upks.php?id='.$gameId.'">Browse UPK packages</a>';
            } else {
                echo '<p>'.number_format($info['pak_entries']).' PAK entries, '
                    .number_format($info['zen_packages']).' Zen packages</p>'
                    . '<a class="button primary" href="game-paks.php?id='.$gameId.'">Browse PAK / IoStore</a>';
                if ($isUe5) {
                    echo ' <a class="button" href="iostore-import.php?game_id='.$gameId.'">Import IoStore</a>';
                }
            }
            echo ' <a class="button" href="pakages.php?game_id='.$gameId.'">View retained files</a></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';

    $enabled = ['pak'=>$pakInstalled,'upk'=>true,'utoc'=>$ioInstalled,'ucas'=>$ioInstalled];
    $selected = $selectedGame === 0 ? $summary : [$selectedGame=>$summary[$selectedGame]];
    $counts = ['pak'=>0,'upk'=>0,'utoc'=>0,'ucas'=>0];
    foreach ($selected as $info) {
        foreach ($counts as $key => $_) {
            $counts[$key] += (int)$info[$key];
        }
    }
    $viewFormats = $format === 'all' ? array_keys($counts) : [$format];

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Original retained files</h2>'
        . '<p>PAK and IoStore containers are retained byte-for-byte. UPK is a verified original UE3 package with indexed exports. '
        . 'IoStore TOCs and UCAS partitions are separate files belonging to one container family.</p>'
        . '</div></div><div class="ui-section__body">';
    echo '<form class="table-controls" method="get"><label>Game <select name="game_id">';
    echo '<option value="0">All UE3–UE5 games</option>';
    foreach ($summary as $gameId=>$info) {
        echo '<option value="'.$gameId.'"'.($selectedGame===$gameId?' selected':'').'>'
            .catalog_h((string)$info['game']['name']).'</option>';
    }
    echo '</select></label> <label>File type <select name="format">';
    foreach (['all'=>'All formats','pak'=>'.pak','upk'=>'.upk','utoc'=>'.utoc','ucas'=>'.ucas'] as $value=>$label) {
        echo '<option value="'.$value.'"'.($format===$value?' selected':'').'>'.$label.'</option>';
    }
    echo '</select></label> <button type="submit">Filter files</button>'
        . ' <a class="button" href="pakages.php">Clear</a></form>';

    foreach ($viewFormats as $kind) {
        $total = $counts[$kind];
        $limit = $format === 'all' ? 15 : 100;
        $pages = max(1, (int)ceil($total/$limit));
        $currentPage = $format === 'all' ? 1 : min($page, $pages);
        $offset = ($currentPage-1)*$limit;
        $args = [];
        $gameWhere = '';
        if ($selectedGame > 0) {
            $gameWhere = ' AND g.id=?';
            $args[] = $selectedGame;
        }
        $sql = match ($kind) {
            'pak' => 'SELECT a.id record_id,a.original_name file_name,a.file_size byte_size,'
                . 'a.status item_status,a.sha256 hash_value,a.entry_count child_count,'
                . 'g.id game_id,g.name game_name,0 part_index '
                . 'FROM ue_pak_archives a JOIN ue_games g ON g.id=a.game_id '
                . 'WHERE 1=1'.$gameWhere.' ORDER BY a.created_at DESC,a.id DESC',
            'upk' => 'SELECT f.id record_id,f.original_name file_name,f.file_size byte_size,'
                . 'f.scan_status item_status,f.sha1 hash_value,f.export_count child_count,'
                . 'g.id game_id,g.name game_name,0 part_index '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'JOIN ue_game_profiles profile ON profile.id=g.profile_id '
                . 'WHERE f.scan_status="verified" AND LOWER(f.extension)="upk" '
                . 'AND UPPER(profile.engine_key) LIKE "UE3%"'
                . $gameWhere.' ORDER BY f.id DESC',
            'utoc' => 'SELECT c.id record_id,c.utoc_relative_path file_name,'
                . 'NULL byte_size,c.status item_status,c.utoc_sha256 hash_value,'
                . 'c.package_count child_count,g.id game_id,g.name game_name,0 part_index '
                . 'FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id '
                . 'WHERE 1=1'.$gameWhere.' ORDER BY c.created_at DESC,c.id DESC',
            'ucas' => 'SELECT c.id record_id,p.relative_path file_name,'
                . 'p.file_size byte_size,c.status item_status,p.sha256 hash_value,'
                . '0 child_count,g.id game_id,g.name game_name,p.part_index part_index '
                . 'FROM ue_iostore_container_parts p '
                . 'JOIN ue_iostore_containers c ON c.id=p.container_id '
                . 'JOIN ue_games g ON g.id=c.game_id '
                . 'WHERE 1=1'.$gameWhere.' ORDER BY c.id DESC,p.part_index ASC',
        };
        $rows = $enabled[$kind] && $total > 0
            ? catalog_all($db, $sql.' LIMIT '.$limit.' OFFSET '.$offset, $args) : [];
        $linkArgs = ['format'=>$kind];
        if ($selectedGame > 0) $linkArgs['game_id'] = $selectedGame;
        echo '<div class="card"><h3>'.strtoupper($kind).' files — '.number_format($total).'</h3>';
        if (!$enabled[$kind]) {
            echo '<p class="muted">The required container schema is not installed.</p>';
        } elseif ($rows === []) {
            echo '<p class="muted">No retained .'.$kind.' files match this selection.</p>';
        } else {
            echo '<div class="ui-table-region"><table><thead><tr><th>File</th><th>Game</th>'
                . '<th>Retained size</th><th>Identity hash</th><th>State / contents</th></tr></thead><tbody>';
            foreach ($rows as $item) {
                $id = (int)$item['record_id'];
                $name = basename(str_replace('\\','/',(string)$item['file_name']));
                $link = match ($kind) {
                    'pak'=>'pak-info.php?id='.$id,
                    'upk'=>'upk-info.php?id='.$id,
                    default=>'iostore-info.php?id='.$id,
                };
                $bytes = $item['byte_size']===null ? null : (int)$item['byte_size'];
                if ($kind === 'utoc') {
                    // A TOC size is not stored in the SQL index. Only stat a
                    // retained relative path after excluding traversal/links.
                    $rel = str_replace('\\','/',(string)$item['file_name']);
                    if ($rel !== '' && !str_starts_with($rel,'/')
                        && preg_match('#(^|/)\.\.?(/|$)#',$rel)!==1
                        && !str_contains($rel,':')) {
                        $physical = rtrim((string)$config['storage_path'],'/\\').'/'.$rel;
                        if (is_file($physical) && !is_link($physical)) {
                            $size = filesize($physical);
                            $bytes = $size!==false ? (int)$size : null;
                        }
                    }
                }
                $hash = (string)$item['hash_value'];
                echo '<tr><td><a href="'.catalog_h($link).'"><strong>'
                    .catalog_h($name).'</strong></a>';
                if ($kind==='ucas') echo '<br><span class="muted small">Data partition #'
                    .(int)$item['part_index'].'</span>';
                echo '</td><td><a href="pakages.php?game_id='.(int)$item['game_id'].'">'
                    .catalog_h((string)$item['game_name']).'</a></td>'
                    .'<td>'.($bytes===null?'—':catalog_h(catalog_bytes($bytes))).'</td>'
                    .'<td class="mono small">'.catalog_h($hash).'</td>'
                    .'<td>'.catalog_h((string)$item['item_status']);
                if ($kind==='pak') echo '<br>'.number_format((int)$item['child_count']).' PAK entries';
                if ($kind==='upk') echo '<br>'.number_format((int)$item['child_count']).' UE3 exports';
                if ($kind==='utoc') echo '<br>'.number_format((int)$item['child_count']).' Zen packages';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($format === 'all' && $total > $limit) {
                echo '<p><a class="button" href="pakages.php?'
                    .catalog_h(http_build_query($linkArgs)).'">Browse all '.strtoupper($kind)
                    .' files</a></p>';
            }
            if ($format !== 'all' && $pages > 1) {
                $pageUrl = static fn(int $n): string => 'pakages.php?'
                    . http_build_query($linkArgs + ['page' => $n]);
                echo CatalogUi::pagination($currentPage, $pages, [
                    'label' => strtoupper($kind) . ' file pagination',
                    'first' => $currentPage > 1 ? $pageUrl(1) : '',
                    'previous' => $currentPage > 1 ? $pageUrl($currentPage - 1) : '',
                    'next' => $currentPage < $pages ? $pageUrl($currentPage + 1) : '',
                    'last' => $currentPage < $pages ? $pageUrl($pages) : '',
                    'page_url' => $pageUrl,
                    'jump' => ['action' => 'pakages.php', 'name' => 'page',
                        'fields' => $linkArgs],
                ]);
            }
        }
        echo '</div>';
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) catalog_head('Package archive browser error');
    echo CatalogUi::alert('danger', catalog_exception_display_message($error),
        'Package archive browser could not be loaded.');
    catalog_foot();
}
