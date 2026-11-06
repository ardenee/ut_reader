<?php
/**
 * Document contract: UE2 file-format specifications describe only serialized
 * data and source-derived decoding, never UnrealDB/paths/UI/SQL operations.
 * Historical mixed notes are archived, not silently destroyed.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$formats=[
    'base'=>'specs/ue2-package-binary-format.md',
    'unreal2'=>'specs/unreal2-package-format-addendum.md',
    'ut2003'=>'specs/ut2003-package-format-addendum.md',
    'ut2004'=>'specs/ut2004-package-format-addendum.md',
    'warfare'=>'specs/ue2-warfare-package-format-addendum.md',
];
$legacy=[
 'specs/ue2.5-unreal-warfare-package-format.md',
 'specs/unreal2-ue2-package-format.md',
 'specs/ut2003-v2107-package-format.md',
 'specs/ut2004-package-format.md'
];
$errors=[];
foreach($formats as $kind=>$path){
    $full=$root.'/'.$path;
    $text=is_file($full)?(string)file_get_contents($full):'';
    if($text===''){$errors[]='Missing document '.$path;continue;}
    foreach(['/\bUnrealDB\b/i','/\bUEDB5\b/i','/\bPHP\b/i','/\bSQL\b/i','/catalog\//i','/\\b(?:admin|worker|webpage)\\b/i','/[A-Z]:\\\\(?:Source|Users|Apache24)\\\\/i'] as $pattern){
        if(preg_match($pattern,$text))$errors[]=$path.' contains an implementation-specific reference: '.$pattern;
    }
    if(!str_contains($text,'package') && !str_contains($text,'Package'))$errors[]='Not a package spec '.$path;
}
$master=is_file($root.'/'.$formats['base'])?file_get_contents($root.'/'.$formats['base']):'';
foreach(['0x9E2A83C1','ImportCount','ExportCount','HeritageCount','GenerationCount',
         'FCompactIndex','PackageIndex','SerialSize != 0','UTF-16'] as $required){
    if(!str_contains($master,$required))$errors[]='Missing core binary rule: '.$required;
}
foreach($legacy as $path){
    $full=$root.'/'.$path;
    $archive=$root.'/archive/ue2-mixed-format-implementation-notes-20261106/'.basename($path);
    if(!is_file($full)||!is_file($archive)){$errors[]='Historical redirect/archive missing '.$path;continue;}
    $current=(string)file_get_contents($full);
    if(!str_contains($current,'ue2-package-binary-format.md')||!str_contains($current,'archiv')){
        $errors[]='Historical redirect no longer documents canonical source or archive: '.$path;
    }
}
echo json_encode(['ok'=>$errors===[],'source_only_format_documents'=>array_values($formats),
    'archived_historical_documents'=>count($legacy),'errors'=>$errors],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:2);
