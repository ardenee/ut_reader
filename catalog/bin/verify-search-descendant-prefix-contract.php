#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Current V5 descendant-search acceptance; supersedes obsolete ue_terms SQL
 * assumptions removed when metadata search switched to compact V5 candidates.
 */
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$checks=[];$failed=[];
$check=static function(string $name,bool $ok,string $detail='')use(&$checks,&$failed):void{
 $checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];
 if(!$ok)$failed[]=$name.($detail!==''?': '.$detail:'');
};
$read=static fn(string $p):string=>(string)@file_get_contents($root.'/'.str_replace('\\','/',$p));
$repo=$read('src/Infrastructure/Search/PdoCatalogSearchRepository.php');
$v5=$read('src/Infrastructure/Search/Uedb5CatalogMetadataSearch.php');
$install=$read('install.sql');
$page=$read('index.php');
$check('live_repository_dispatches_v5_compact_search',str_contains($repo,'new Uedb5CatalogMetadataSearch($this->db)')&&str_contains($repo,'->findMatches('));
$check('v5_uses_compact_index_candidates',str_contains($v5,'ue_uedb5_compact_name_dictionary')&&str_contains($v5,'ue_uedb5_compact_name_candidates'));
$check('v5_confirms_authoritative_snapshot',str_contains($v5,'$this->reader->snapshot(')&&str_contains($v5,'$this->matchSnapshot('));
$check('v5_uses_dot_delimited_path_boundary',str_contains($v5,'$descendantPrefix = ')&&str_contains($v5,'str_starts_with($localKey, $descendantPrefix)'));
$check('v5_qualified_provider_lookup',str_contains($v5,'collectQualifiedProviderCandidates'));
$check('v5_source_schema',str_contains($install,'ue_uedb5_compact_name_dictionary')&&str_contains($install,'ue_uedb5_compact_name_candidates'));
$check('ui_explains_descendant_queries',str_contains($page,'Package.Class also finds Package.Class.Member'));
foreach(['verify-ue5-583-synthetic-nested-search.php','verify-ue5-583-synthetic-nested-sql-search.php'] as $file){
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$file);
 $lines=[];$exit=-1;exec($cmd.' 2>&1',$lines,$exit);
 $json=json_decode(implode("\n",$lines),true);
 $check('functional:'.$file,$exit===0&&is_array($json)&&($json['ok']??false)===true,$exit===0?'':implode("\n",array_slice($lines,0,5)));
}
foreach(['src/Infrastructure/Search/PdoCatalogSearchRepository.php','src/Infrastructure/Search/Uedb5CatalogMetadataSearch.php','index.php'] as $rel){
 $lines=[];$exit=-1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root.'/'.$rel).' 2>&1',$lines,$exit);
 $check('syntax:'.$rel,$exit===0);
}
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed,'modern_v5_search'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:2);
