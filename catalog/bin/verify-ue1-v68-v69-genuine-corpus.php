#!/usr/bin/env php
<?php
/** UE1 original UT99 v400 and Unreal v227 genuine v68/v69 corpus test.
 * Serialized source: UT99 v400 Core/Src/UnLinker.h:144-204;
 * Unreal v227 Core/Inc/UnLinker.h:97-170.
 * This is byte-level summary/reader/inspector agreement, not native execution.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/GameProfiles.php';
require_once dirname(__DIR__) . '/lib/CatalogParser.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
require_once dirname(__DIR__) . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
$manifest=dirname(__DIR__).'/compliance/inventory/ue1-packages-v68-v69.csv';
$fp=fopen($manifest,'rb');
if(!$fp){throw new RuntimeException('Missing SHA-pinned v68/v69 manifest');}
if(fread($fp,3)!=="\xEF\xBB\xBF"){rewind($fp);}
$fields=fgetcsv($fp,0,',','"','');
$expected=['RelativePath','SourceRoot','SizeBytes','PackageVersion','LicenseeVersion','Sha256'];
if($fields!==$expected){throw new RuntimeException('Manifest columns unexpected: '.json_encode($fields));}
$results=[];
while(($line=fgetcsv($fp,0,',','"',''))!==false){
    $r=array_combine($fields,$line);
    if(!is_array($r)){throw new RuntimeException('Invalid manifest row');}
    $path=rtrim($r['SourceRoot'],'\\/').DIRECTORY_SEPARATOR.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$r['RelativePath']);
    $issues=[];
    if(!is_file($path)){$issues[]='File missing';}
    else{
        if(filesize($path)!==(int)$r['SizeBytes']){$issues[]='Size mismatch';}
        if(strtolower((string)hash_file('sha256',$path))!==strtolower($r['Sha256'])){$issues[]='SHA256 mismatch';}
    }
    if($issues!==[]){
        $results[]=['file'=>$r['RelativePath'],'version'=>(int)$r['PackageVersion'],'licensee'=>(int)$r['LicenseeVersion'],'issues'=>$issues];
        continue;
    }
    $data=file_get_contents($path,false,null,0,96);
    if(!is_string($data)||strlen($data)<56){$issues[]='Summary truncated';}
    else{
        // Epic 68+ FPackageFileSummary: 9 dwords, inline GUID (4), gen count (1).
        $x=unpack('Vtag/Vpacked/Vflags/VnameCount/VnameOffset/VexportCount/VexportOffset/VimportCount/VimportOffset/Vg1/Vg2/Vg3/Vg4/VgenCount',substr($data,0,56));
        $nativeGuid=sprintf('%08X-%08X-%08X-%08X',$x['g1'],$x['g2'],$x['g3'],$x['g4']);
        $packedVersion=$x['packed'] & 0xffff;
        $packedLicensee=($x['packed'] >> 16) & 0xffff;
        if($x['tag']!==0x9E2A83C1){$issues[]='Unexpected Epic tag';}
        if($packedVersion!==(int)$r['PackageVersion']||$packedLicensee!==(int)$r['LicenseeVersion']){$issues[]='Independent version/licensee mismatch';}
        $early=gp_read_legacy_summary($path);
        if(!($early['ok']??false)||($early['format']??null)!=='legacy_package'||($early['engine_hint']??null)!=='UE1'
           ||(int)($early['version']??0)!==$packedVersion||(int)($early['licensee']??-1)!==$packedLicensee){
            $issues[]='Production summary identification mismatch '.json_encode($early);
        }
        $unreal=str_contains($path,'Unreal [v1.227]');
        $reader=new CatalogUE1PackageReader($path,$unreal);
        foreach($reader->validatePackage() as $issue){$issues[]='Reader '.$issue;}
        $h=$reader->getHeader();
        if($reader->validatePackage()===[]){
            $map=['tag'=>'signature','packed'=>'packedVersion','flags'=>'pkgFlags',
                'nameCount'=>'nameCount','nameOffset'=>'nameOffset','exportCount'=>'exportCount',
                'exportOffset'=>'exportOffset','importCount'=>'importCount','importOffset'=>'importOffset'];
            foreach($map as $source=>$dest){
                if((int)$x[$source] !== (int)($h[$dest]??-1)){$issues[]='Direct source field '.$source;}
            }
            if((string)($h['guid']??'')!==$nativeGuid){$issues[]='Inline GUID mismatch';}
            if((int)($h['genCount']??-1)!==(int)$x['genCount']){$issues[]='Generation count mismatch';}
            if(count($reader->getNames())!==(int)$x['nameCount']){$issues[]='Name count mismatch';}
            if(count($reader->getImports())!==(int)$x['importCount']){$issues[]='Import count mismatch';}
            if(count($reader->getExports())!==(int)$x['exportCount']){$issues[]='Export count mismatch';}
            try{$route=catalog_try_read_package_header([],'UE1',$path);
                if(($route['guid']??null)!==$nativeGuid){$issues[]='Header helper GUID mismatch';}
            }catch(Throwable $e){$issues[]='Header helper '.$e->getMessage();}
        }
        $view=CatalogPackageHeaderInspector::inspect($path,['engine_key'=>'UE1','extension'=>pathinfo($path,PATHINFO_EXTENSION)]);
        if(!($view['ok']??false) || ($view['summary']['GUID']??null)!==$nativeGuid){$issues[]='File-examine GUID mismatch '.json_encode($view['error']??null);}
    }
    $results[]=['file'=>$r['RelativePath'],'version'=>(int)$r['PackageVersion'],'licensee'=>(int)$r['LicenseeVersion'],'issues'=>$issues];
}
fclose($fp);
$failed=array_values(array_filter($results,static fn($r)=>$r['issues']!==[]));
echo json_encode(['ok'=>count($results)===31&&$failed===[],'total'=>count($results),'passed'=>count($results)-count($failed),'failed'=>count($failed),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($failed===[]&&count($results)===31?0:1);
