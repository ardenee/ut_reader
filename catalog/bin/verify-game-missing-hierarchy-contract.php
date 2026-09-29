#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);$checks=[];$fail=[];
$read=static function(string $rel)use($root):string{$p=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);$s=@file_get_contents($p);return is_string($s)?$s:'';};
$record=static function(string $name,bool $ok,string $detail)use(&$checks,&$fail):void{$checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];if(!$ok)$fail[]=$name;};
$page=$read('game-missing.php');$query=$read('src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php');
$record('package_first_hierarchy',str_contains($page,'<h2>Required packages</h2>')&&str_contains($page,'missing object paths')&&str_contains($page,'<h3>Affected files</h3>'),'Missing UI should drill package -> object path -> affected files.');
$record('missing_path_segment_highlight',str_contains($page,'gm_path_html')&&str_contains($page,'gm-missing-part')&&str_contains($page,'gm_first_path_difference'),'Required paths must remain whole while the missing/mismatching portion is highlighted.');
$record('grouped_object_projection',str_contains($query,'COUNT(DISTINCT l.required_object_term_id)')&&str_contains($query,'GROUP BY l.required_object_term_id')&&str_contains($query,'COUNT(DISTINCT l.file_id) affected_file_count'),'Object drilldown should aggregate compact dependency rows before rendering.');
$record('missing_scope_is_status_zero_only',substr_count($query,'l.status=0')>=4&&!str_contains($query,'l.status IN (0,4)'),'Source-unresolved status 4 must never leak into the missing page.');
$record('searchable_package_and_object_tables',str_contains($page,'name="q"')&&str_contains($page,'name="object_q"')&&str_contains($query,"LIKE ?"),'Package and object-path tables should support bounded search filters.');
$record('compact_summary_table_replaces_cards',str_contains($page,'<h2>Summary</h2>')&&str_contains($page,'gm-summary')&&!str_contains($page,"catalog_stat_card('Missing objects'"),'Top-level counts should be a compact table rather than three large cards.');
foreach(['game-missing.php','src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php'] as $rel){$p=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($p),$out,$code);$record('syntax:'.$rel,$code===0,'');$out=[];}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:2);
