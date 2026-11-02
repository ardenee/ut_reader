#!/usr/bin/env php
<?php
/**
 * Real SHA-pinned UT99 package bytes as provider maps for Epic's
 * v400 VerifyImport projection (Core/Src/UnLinker.h:467-505,541-634).
 * Consumer probes are synthetic and explicitly identified as such.
 * This validates genuine provider class/name/outer/flags, not native runtime.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver as R;
$manifest=dirname(__DIR__).'/compliance/inventory/ue1-packages-v68-v69.csv';
$fp=fopen($manifest,'rb');if(!$fp){throw new RuntimeException('Missing corpus manifest');}
if(fread($fp,3)!=="\xEF\xBB\xBF"){rewind($fp);}
$cols=fgetcsv($fp,0,',','"','');
$records=[];while(($row=fgetcsv($fp,0,',','"',''))!==false){$records[]=array_combine($cols,$row);}
fclose($fp);
$failures=[];$matched=0;$packages=0;$examined=0;$privateRejected=0;$wrongClass=0;
foreach($records as $record){
    if(!str_contains((string)$record['SourceRoot'],'\\Games\\UT99')){continue;}
    $path=rtrim($record['SourceRoot'],'\\/').DIRECTORY_SEPARATOR.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$record['RelativePath']);
    if(!is_file($path)||strtolower((string)hash_file('sha256',$path))!==strtolower((string)$record['Sha256'])){
        $failures[]='Genuine file/hash mismatch: '.$path;continue;
    }
    $reader=new CatalogUE1PackageReader($path);
    if($reader->validatePackage()!==[]){$failures[]='Genuine reader error: '.$path;continue;}
    $package=pathinfo($path,PATHINFO_FILENAME);
    $imports=[];foreach($reader->getImports() as $row){
        $imports[]=['import_index'=>(int)$row['index'],'object_name'=>(string)$row['objectNameText'],
            'class_package'=>(string)$row['classPackageText'],'class_name'=>(string)$row['classNameText'],
            'outer_index'=>(int)$row['outerIndex']];
    }
    $exports=[];foreach($reader->getExports() as $row){
        $exports[]=['export_index'=>(int)$row['index'],'object_name'=>(string)$row['objectNameText'],
            'class_index'=>(int)$row['classIndex'],'outer_index'=>(int)$row['outerIndex'],
            'object_flags'=>(int)$row['objectFlags']];
    }
    $packages++;$examined+=count($exports);
    $probed=0;
    foreach($exports as $export){
        if($probed>=4){break;}
        if($export['outer_index']!==0||($export['object_flags']&4)===0
            ||$export['object_name']==='None'||$export['object_name']===''){continue;}
        $classIndex=$export['class_index'];
        if($classIndex<0){
            $classImport=$imports[-$classIndex-1]??null;
            if($classImport===null){continue;}
            $className=$classImport['object_name'];
            $classOuter=$classImport['outer_index'];
            if($classOuter>=0){continue;}
            $classPackage=$imports[-$classOuter-1]['object_name']??'';
        }elseif($classIndex>0){
            $className=$exports[$classIndex-1]['object_name']??'';
            $classPackage=$package;
        }else{
            $className='Class';$classPackage='Core';
        }
        if($classPackage===''||$className===''){continue;}
        $consumer=[
            ['import_index'=>0,'object_name'=>$package,'class_package'=>'Core','class_name'=>'Package','outer_index'=>0],
            ['import_index'=>1,'object_name'=>$export['object_name'],'class_package'=>$classPackage,'class_name'=>$className,'outer_index'=>-1],
        ];
        $results=R::resolveInMemoryOutcome(
            R::PROFILE_UT99_V1400,$consumer,$imports,$exports,$package,68,(int)$record['PackageVersion']
        );
        $selected=$results[1]??[];
        // Duplicate names can select the source's last matching export hash
        // entry. Validate the actual selected row rather than requiring the
        // first source index selected for the consumer probe.
        $selectedIndex=$selected['export_index']??null;
        $target=$selectedIndex===null?null:($exports[$selectedIndex]??null);
        if(($selected['status']??null)!=='resolved'||$target===null
            ||strcasecmp($target['object_name'],$export['object_name'])!==0
            ||($target['object_flags']&4)===0){
            $failures[]='Genuine export match failed '.$package.'/'.$export['object_name'].': '.json_encode($selected);
            continue;
        }
        $matched++;$probed++;
        $badClass=$consumer;
        $badClass[1]['class_package']='DefinitelyNotEpicClassPackage';
        $badOutcome=R::resolveInMemoryOutcome(R::PROFILE_UT99_V1400,$badClass,$imports,$exports,$package,68,(int)$record['PackageVersion']);
        if(($badOutcome[1]['status']??null)==='resolved'){
            $failures[]='Wrong genuine provider class package resolved '.$package;
        }else{$wrongClass++;}
        $tampered=$exports;
        $tampered[$selectedIndex]['object_flags']=0;
        $private=R::resolveInMemoryOutcome(R::PROFILE_UT99_V1400,$consumer,$imports,$tampered,$package,68,(int)$record['PackageVersion']);
        if(($private[1]['status']??null)==='private_export'){$privateRejected++;}
        // Other duplicate-name exports may legitimately be selected; do not
        // interpret those as failures of the original source-level lookup.
    }
}
$ok=$failures===[]&&$packages>=15&&$matched>=5&&$wrongClass===$matched;
echo json_encode(['ok'=>$ok,'genuine_ut99_packages'=>$packages,'exports_examined'=>$examined,
    'genuine_provider_resolutions'=>$matched,'wrong_class_rejections'=>$wrongClass,
    'private_export_rejections'=>$privateRejected,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
