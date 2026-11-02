#!/usr/bin/env php
<?php
/** Focused, read-only contract for Access Matrix card navigation/views. */
declare(strict_types=1);
$root=dirname(__DIR__);
$src=(string)file_get_contents($root.'/access-matrix.php');
$bad=[];$n=0;
$check=static function(string $label,bool $pass)use(&$bad,&$n):void{$n++;if(!$pass)$bad[]=$label;};
$headings=[
    'pages'=>'Busiest pages',
    'links'=>'Busiest links',
    'sections'=>'Busiest sections',
    'interactions'=>'Top interactions',
    'navigation'=>'Navigation matrix',
];
$positions=[];
foreach($headings as $view=>$title){
    $snippet="access_matrix_summary_heading('$title', '$view')";
    $pos=strpos($src,$snippet);
    $positions[$view]=$pos;
    $check('linked_heading_'.$view,$pos!==false);
    $check('full_report_registered_'.$view,
        str_contains($src,"'$view' => [")&&str_contains($src,"'title' => '$title'"));
}
$check('all_five_expanded_by_default',
    substr_count($src,'access-matrix-collapsible" open><summary>')===4
    &&substr_count($src,'access-matrix-collapsible access-matrix-wide" open><summary>')===1);
$check('all_five_close_with_details',
    substr_count($src,"echo '</div></details>';")>=7);
$check('navigation_after_top_interactions',
    $positions['interactions']!==false &&$positions['navigation']!==false
    &&$positions['navigation']>$positions['interactions']);
$check('navigation_spans_entire_css_grid',
    str_contains($src,'.access-matrix-grid .access-matrix-wide{grid-column:1/-1}'));
$check('summary_header_link_does_not_toggle',
    str_contains($src,'onclick="event.stopPropagation()"'));
$check('admin_auth_before_all_details',
    strpos($src,"catalog_require_admin_page('Access Matrix')")<
    strpos($src,'$detailView = access_matrix_choice('));
$check('full_view_whitelist_no_dynamic_user_sql',
    str_contains($src,"['pages', 'links', 'sections', 'interactions', 'navigation']")
    &&str_contains($src,'$report = $reports[$detailView];'));
$check('server_side_filter_uses_bound_params',
    str_contains($src,"'detail_q'")&&str_contains($src,"' HAVING ('")
    &&str_contains($src,'$detailArgs[] = $like;')
    &&str_contains($src,'$statement->execute($detailArgs);'));
$check('full_group_pagination',
    str_contains($src,"'SELECT COUNT(*) FROM (' . " . '$sql' . " . ') grouped_events'")
    &&str_contains($src,"' LIMIT 100 OFFSET '")
    &&str_contains($src,'$detailPage = min($detailPage, $lastPage);')
    &&str_contains($src,'$detailPage + 1'));
$check('original_filters_and_dashboard_link_preserved',
    str_contains($src,'$conditions = $where;')
    &&str_contains($src,'$detailArgs = $args;')
    &&str_contains($src,"'view' => null")
    &&str_contains($src,'Back to Site Activity Logs'));
$check('all_five_full_reports_have_exact_activity_drilldowns',
    str_contains($src, '$rowFilters = match ($detailView)')
    && str_contains($src, "'pages' => ['event' => 'page_activity', 'page_exact'")
    && str_contains($src, "'links' => ['event' => 'page_activity', 'path_exact'")
    && str_contains($src, "'event' => 'section', 'page_exact'")
    && str_contains($src, "'section_exact' => (string)\$row['section_key']")
    && str_contains($src, "'event' => 'interaction', 'page_exact'")
    && str_contains($src, "'action_exact' => (string)\$row['action_key']")
    && str_contains($src, "'target_exact' => (string)(\$row['target_path'] ?? '')")
    && str_contains($src, "'referrer_exact' => (string)\$row['source_path']")
    && str_contains($src, "'destination_exact' => (string)\$row['destination_path']"));
$check('full_report_hits_link_to_raw_activity',
    str_contains($src, "\$field === 'hits' || \$field === 'unique_ips'")
    && str_contains($src, 'access_matrix_query(array_merge($rowFilters, [')
    && str_contains($src, "'view' => null, 'detail_q' => null, 'detail_p' => null")
    && str_contains($src, "'#matching-ips' : '#raw-events'")
    && str_contains($src, 'class="access-matrix-metric-link" href="access-matrix.php?'));
$check('full_report_ips_link_to_contributing_addresses',
    str_contains($src, "'show_ips' => \$field === 'unique_ips' ? 1 : null")
    && str_contains($src, "'event' => 'page_activity'")
    && str_contains($src, "'event' => 'section'")
    && str_contains($src, "'event' => 'interaction'"));
$check('no_change_to_existing_top_ten',
    preg_match_all('/LIMIT 10(?!0)/',$src)===5);
echo json_encode(['ok'=>$bad===[],'passed'=>$n-count($bad),'checks'=>$n,'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
