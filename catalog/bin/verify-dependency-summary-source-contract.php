#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);$checks=[];$fail=[];
$read=static fn(string $rel):string=>(string)@file_get_contents($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
$record=static function(string $name,bool $ok,string $detail)use(&$checks,&$fail):void{$checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];if(!$ok)$fail[]=$name;};
$summary=$read('src/Infrastructure/Persistence/PdoDependencyPackageSummary.php');
$reconcile=$read('bin/reconcile-dependency-package-summaries.php');
$record('summary_reads_authoritative_links',str_contains($summary,'FROM ue_dependency_links l '),'Package summaries must derive from authoritative dependency links.');
$record('summary_has_no_metadata_format_gate',!str_contains($summary,'JOIN ue_file_metadata'),'Summary publication must work for verified files regardless of metadata container version.');
$record('reconciler_has_no_metadata_format_gate',!str_contains($reconcile,'JOIN ue_file_metadata'),'Reconciliation must cover every verified file, not only one metadata format.');
$record('reconciler_detects_no_link_orphans',str_contains($reconcile,'NOT EXISTS (')&&str_contains($reconcile,'FROM ue_dependency_links l WHERE l.file_id=s.file_id'),'Read-only reconciliation must expose summary owners that no longer have dependency rows.');
$record('reconciler_supports_exact_file_owner',str_contains($reconcile,"'file-id::'")&&str_contains($reconcile,'rebuildFiles([$fileId])')&&str_contains($reconcile,'\'file_id\'=>$fileId > 0 ? $fileId : null'),'Reconciliation must support targeting one exact current or stale summary owner.');
$record('reconciler_can_lookup_package_owners_read_only',str_contains($reconcile,"'package::'")&&str_contains($reconcile,"'read_only'=>true")&&str_contains($reconcile,'summary_owners'),'A package lookup must identify current/stale summary owners without writing so an exact file can be selected safely.');
foreach(['src/Infrastructure/Persistence/PdoDependencyPackageSummary.php','bin/reconcile-dependency-package-summaries.php'] as $rel){$p=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($p),$out,$code);$record('syntax:'.$rel,$code===0,'');$out=[];}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:2);
