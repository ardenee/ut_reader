<?php
/** UE2 actual-reader differential against independent Python original-byte source schema. READ ONLY. */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$options=getopt('',['prd-root::','help']);
if(isset($options['help'])){echo "Usage: php tests/tools/verify_ue2_reader_against_independent_wire_evidence.php --prd-root=PRD_CATALOG_ROOT\n";exit(0);}
$root=(string)($options['prd-root']??'');
if($root===''){fwrite(STDERR,"Pass --prd-root (original read-only PRD catalog, mounted).\n");exit(2);}
$source=json_decode(file_get_contents(dirname(__DIR__).'/evidence/ue2/ue2-binary-format-15-originals-and-negative-probes-20261106.json'),true,512,JSON_THROW_ON_ERROR);
if(($source['ok']??false)!==true || count((array)($source['new_cases']??[]))!==5)throw new RuntimeException('Independent source evidence missing or not verified.');
$paths=[
1277117=>'storage/games/ut2003/verified/83a28f2edfccabc75ad0adf98ca3648d.usx',
1282344=>'storage/games/ut2004/verified/51831195ef2ddde67dbfc42105b26c17.u',
1289524=>'storage/games/ut2004/verified/f6f3367ed00fe2d3952ba8092c6b6a8a.u',
111284=>'storage/games/unreal2/verified/c9bd2cc9787d1cbd064219d02f96aa5d.u',
111218=>'storage/games/unreal2/verified/21c1c321bfdaba97bf8bcfef2b96f4ae.u',
];
$result=['ok'=>true,'mode'=>'READ_ONLY','independent_cases'=>0,'errors'=>[],'sampled_originals'=>[]];
foreach($source['new_cases'] as $row){
    $id=(int)$row['id'];$relative=$paths[$id]??null;
    if($relative===null){$result['errors'][]="Unknown file $id";continue;}
    $path=rtrim($root,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if(!is_file($path)){$result['errors'][]="File unavailable $id";continue;}
    $sha=strtolower(hash_file('sha256',$path));
    if($sha!==strtolower((string)$row['sha256'])){$result['errors'][]="Original SHA mismatch $id";continue;}
    $reader=new CatalogUE2PackageReader($path);
    $issues=$reader->validatePackage();
    if($issues!==[]){$result['errors'][]="Reader rejected $id: ".implode(';',$issues);continue;}
    $h=$reader->getHeader();
    $checks=[
        'version'=>(int)($h['version']??-1),
        'licensee'=>(int)($h['licenseeVersion']??-1),
        'name_count'=>count($reader->getNames()),
        'import_count'=>count($reader->getImports()),
        'export_count'=>count($reader->getExports()),
    ];
    foreach($checks as $field=>$value){
        if($value!==(int)$row[$field]){
            $result['errors'][]="Reader original $id $field mismatch $value versus independent ".$row[$field];
        }
    }
    $result['sampled_originals'][]=['id'=>$id,'game_id'=>(int)$row['game'],
        'engine_version'=>(int)$row['version'],'licensee'=>(int)$row['licensee'],
        'names'=>$checks['name_count'],'imports'=>$checks['import_count'],'exports'=>$checks['export_count'],
        'file_sha256'=>$sha];
    $result['independent_cases']++;
}
$result['ok']=$result['errors']===[]&&$result['independent_cases']===5;
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($result['ok']?0:2);
