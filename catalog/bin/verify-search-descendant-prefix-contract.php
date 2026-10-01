#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=="cli"){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);$checks=[];$fail=[];
$read=static fn(string $rel):string=>(string)@file_get_contents($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
$record=static function(string $name,bool $ok,string $detail)use(&$checks,&$fail):void{$checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];if(!$ok)$fail[]=$name;};
$repo=$read('src/Infrastructure/Search/PdoCatalogSearchRepository.php');
$page=$read('index.php');
$install=$read('install.sql');
$exactPos=strpos($repo,'collectExactMetadataMatches');$descPos=strpos($repo,'collectDescendantMetadataMatches');
$exactQualifiedPos=strpos($repo,'collectExactQualifiedExportMatches');$descQualifiedPos=strpos($repo,'collectQualifiedExportDescendantMatches');
$record('exact_matches_are_collected_first',$exactPos!==false&&$descPos!==false&&$exactPos<$descPos&&$exactQualifiedPos!==false&&$descQualifiedPos!==false&&$exactQualifiedPos<$descQualifiedPos,'Exact matches must retain priority ahead of descendant-path matches.');
$record('descendant_prefix_is_dot_delimited',str_contains($repo,'self::escapeLike(')&&substr_count($repo," . '.') . '%'")>=2,'Searching Package.Class must match Package.Class.Member without matching unrelated textual prefixes.');
$record('descendant_search_has_no_leading_wildcard',str_contains($repo,"t.value_prefix LIKE ? ESCAPE '='")&&!str_contains($repo,"'%' . self::escapeLike("),'Descendant path lookup must stay indexable and never use a leading wildcard.');
$record('descendant_search_uses_path_terms_only',str_contains($repo,"'ue_export_lookup', 'local_path_term_id'")&&str_contains($repo,"'ue_dependency_links', 'required_object_term_id'"),'Prefix expansion should apply to hierarchical Import/Export paths, not arbitrary leaf-name terms.');
$record('term_prefix_index_exists',str_contains($install,'KEY idx_ue_terms_value_prefix (value_prefix(100))'),'The term dictionary must retain its prefix index for descendant search.');
$record('search_ui_describes_descendants',str_contains($page,'Package.Class also finds Package.Class.Member'),'The public search page should explain the descendant-path behavior.');
foreach(['src/Infrastructure/Search/PdoCatalogSearchRepository.php','index.php'] as $rel){$path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path),$out,$code);$record('syntax:'.$rel,$code===0,'');$out=[];}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:2);
