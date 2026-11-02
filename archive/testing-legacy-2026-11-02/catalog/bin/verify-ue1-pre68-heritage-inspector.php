#!/usr/bin/env php
<?php
/**
 * Source-linked UE1 inspector parity for pre-68 FPackageFileSummary heritage.
 * Epic UT99 v400 Core/Src/UnLinker.h:173-200; Unreal v1.200
 * Core/Src/UnLinker.h:303-338. Only v68+ stores inline Guid.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
require_once dirname(__DIR__) . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;

$checks=[]; $failed=[];
$check=static function(string $id,bool $ok)use(&$checks,&$failed):void{
    $checks[$id]=$ok;
    if(!$ok){$failed[]=$id;}
};
$path=tempnam(sys_get_temp_dir(),'ue1_heritage_');
if(!is_string($path)){throw new RuntimeException('Cannot create fixture');}
$make=static function(int $count,int $offset,array $guids,string $after=''):string{
    // 44-byte pre68 FPackageFileSummary, zero names/imports/exports.
    $summary=pack('V',0x9E2A83C1).pack('V',61).pack('V',0)
        .pack('V6',0,0,0,0,0,0).pack('V2',$count,$offset);
    $bytes=$summary.$after;
    if($count>0 && $offset>=strlen($bytes)){
        $bytes.=str_repeat("\0",$offset-strlen($bytes));
    }
    foreach($guids as $part){$bytes.=pack('V4',...$part);}
    return $bytes;
};
$inspect=static function(string $file):array{
    $canonical=new CatalogUE1PackageReader($file);
    $view=CatalogPackageHeaderInspector::inspect($file,['engine_key'=>'UE1','extension'=>'utx']);
    return [
        'canonical'=>$canonical->getHeader()['guid']??'',
        'canonicalIssues'=>$canonical->validatePackage(),
        'view'=>$view['summary']['GUID']??'',
        'viewOk'=>$view['ok']??false,
        'rows'=>$view['rows']??[],
    ];
};
try{
    $one=[0x11,0x22,0x33,0x44];
    $two=[0xAA,0xBB,0xCC,0xDD];
    $expected='000000AA-000000BB-000000CC-000000DD';

    file_put_contents($path,$make(2,128,[$one,$two]));
    $r=$inspect($path);
    $check('two_heritage_guids_use_last_at_offset_144',
        $r['canonicalIssues']===[] && $r['canonical']===$expected
        && $r['viewOk']===true && $r['view']===$expected
        && count(array_filter($r['rows'],static fn($row)=>($row['field']??'')==='guid' && ($row['offset']??null)===144))===1);

    file_put_contents($path,$make(0,0,[],str_repeat("\xCC",32)));
    $r=$inspect($path);
    $check('zero_heritage_entries_do_not_fabricate_guid',
        $r['canonicalIssues']===[] && $r['canonical']===''
        && $r['viewOk']===true && $r['view']==='');

    // Inspector initially reads first MiB; the heritage record can be later.
    $late=1048640;
    file_put_contents($path,$make(1,$late,[$two]));
    $r=$inspect($path);
    $check('heritage_guid_beyond_inspection_prefix',
        $r['canonicalIssues']===[] && $r['canonical']===$expected
        && $r['viewOk']===true && $r['view']===$expected);

    // Incorrect heritage offset/count must not be presented as valid.
    file_put_contents($path,$make(2,128,[$one]));
    $view=CatalogPackageHeaderInspector::inspect($path,['engine_key'=>'UE1','extension'=>'utx']);
    $check('invalid_heritage_table_is_reported',($view['ok']??true)===false);

    // v68 stores inline GUID; retain the modern format branch.
    $modern=pack('V',0x9E2A83C1).pack('V',68).pack('V',0)
        .pack('V6',0,0,0,0,0,0).pack('V4',...$two).pack('V',0);
    file_put_contents($path,$modern);
    $r=$inspect($path);
    $check('version68_inline_guid_unchanged',
        $r['canonicalIssues']===[] && $r['canonical']===$expected
        && $r['viewOk']===true && $r['view']===$expected);
}finally{@unlink($path);}
echo json_encode(['ok'=>$failed===[], 'checks'=>$checks,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
