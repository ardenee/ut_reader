<?php
/**
 * Original-file UE2 full-field reader differential against an independent
 * source-derived Python decoder. No PRD writes, no duplicate native tests.
 * Compares EVERY table entry's byte offset and serialized fields, not counts.
 */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$options=getopt('',['prd-root:','help']);
if(isset($options['help'])){
    echo "Usage: php tests/tools/verify_ue2_original_full_table_fields.php --prd-root=PRD_CATALOG_ROOT\n";
    exit(0);
}
$root=(string)($options['prd-root']??'');
if($root===''){fwrite(STDERR,"A read-only PRD catalogue root is required.\n");exit(2);}
$evidence=dirname(__DIR__).'/evidence/ue2/ue2-original-version-cohorts-full-field-differential-20261106.json';
$source=json_decode((string)file_get_contents($evidence),true,512,JSON_THROW_ON_ERROR);
$paths=[
    1277117=>'storage/games/ut2003/verified/83a28f2edfccabc75ad0adf98ca3648d.usx',
    1282344=>'storage/games/ut2004/verified/51831195ef2ddde67dbfc42105b26c17.u',
    1289524=>'storage/games/ut2004/verified/f6f3367ed00fe2d3952ba8092c6b6a8a.u',
    111284=>'storage/games/unreal2/verified/c9bd2cc9787d1cbd064219d02f96aa5d.u',
    111218=>'storage/games/unreal2/verified/21c1c321bfdaba97bf8bcfef2b96f4ae.u',
];
$canonicalHash=static function(array $rows):string {
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
};
$errors=[];$compared=0;$totals=['names'=>0,'imports'=>0,'exports'=>0];
foreach((array)$source['cases'] as $case){
    $id=(int)$case['file_id'];
    if(!isset($paths[$id])){$errors[]="unexpected original ID $id";continue;}
    $path=rtrim($root,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$paths[$id]);
    if(!is_file($path)){$errors[]="original $id inaccessible";continue;}
    if(!hash_equals(strtolower((string)$case['original_sha256']),hash_file('sha256',$path))){
        $errors[]="original SHA256 changed: $id";continue;
    }
    $reader=new CatalogUE2PackageReader($path);
    if(($issues=$reader->validatePackage())!==[]){
        $errors[]="reader rejected source original $id: ".implode(';',$issues);continue;
    }
    $header=$reader->getHeader();
    if((int)($header['version']??-1)!==(int)$case['version']
        ||(int)($header['licenseeVersion']??-1)!==(int)$case['licensee']){
        $errors[]="package version/licensee changed $id";continue;
    }
    $names=[];foreach($reader->getNames() as $r){
        $names[]=[(int)$r['offset'],(string)$r['name'],(int)$r['flags']];
    }
    $imports=[];foreach($reader->getImports() as $r){
        $imports[]=[(int)$r['offset'],(int)$r['classPackage'],
            (int)$r['className'],(int)$r['outerIndex'],(int)$r['objectName']];
    }
    $exports=[];foreach($reader->getExports() as $r){
        $exports[]=[(int)$r['offset'],(int)$r['classIndex'],(int)$r['superIndex'],
            (int)$r['outerIndex'],(int)$r['objectName'],(int)$r['objectFlags'],
            (int)$r['serialSize'],(int)$r['serialOffset']];
    }
    foreach(['names'=>$names,'imports'=>$imports,'exports'=>$exports] as $kind=>$rows){
        $expectedCount=(int)$case[match($kind){'names'=>'name_rows','imports'=>'import_rows','exports'=>'export_rows'}];
        if(count($rows)!==$expectedCount){$errors[]="mismatched $kind row count for $id";continue;}
        $digest=$canonicalHash($rows);
        $expectedDigest=(string)$case[$kind.'_all_fields_sha256'];
        if(!hash_equals($expectedDigest,$digest)){
            $errors[]="mismatched EVERY-FIELD $kind digest for $id: expected=$expectedDigest observed=$digest";
            continue;
        }
        $totals[$kind]+=count($rows);
    }
    $compared++;
}
if($compared!==5){$errors[]="expected exactly five original files; got $compared";}
foreach(['names'=>'total_name_rows','imports'=>'total_import_rows','exports'=>'total_export_rows'] as $kind=>$field){
    if($totals[$kind] !== (int)$source[$field]){
        $errors[]="expected total $kind was not matched";
    }
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'mode'=>'ORIGINAL_BYTES_READ_ONLY','source'=>'independent source-derived Python full serialized row decodes',
    'files_compared'=>$compared,'row_fields_comparison'=>'all entries, all serialized fields and exact byte entry offsets',
    'total_rows'=>$totals,'errors'=>$errors],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
