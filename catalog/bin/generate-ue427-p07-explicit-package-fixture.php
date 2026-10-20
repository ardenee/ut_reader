<?php
/** UE4.27 P07 controlled non-outer PackageName fixture, real version-520 asset. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$source='H:/Epic Games/UE_4.27/Engine/Content/EngineMaterials/RandomVectors_WithXNeighbor.uasset';
$folder='C:/Temp/UE427Oracle/Saved/UE427P07Probe';
$reader=new UnrealPackageReader4($source);
if($reader->validatePackage()!==[])throw new RuntimeException('Source structural validation failed');
$header=$reader->getHeader();
if((int)$header['version']!==520)throw new RuntimeException('Unexpected UE4 version');
$imports=$reader->getImports();$names=$reader->getNames();
if(count($imports)!==5||count($names)<20)throw new RuntimeException('Unexpected real package table');
$target=$imports[1];
if($target['objectNameText']!=='AssetImportData'
 ||$target['classNameText']!=='Class'||$target['outerIndex']!==-5
 ||!$target['packageNamePresent']||$target['packageNameText']!=='None')
 throw new RuntimeException('Wrong import candidate for explicit PackageName fixture');
$indices=[];foreach($names as $item)$indices[$item['name']]=$item['index'];
foreach(['/Script/Engine','/Script/CoreUObject','None'] as $required)
 if(!isset($indices[$required]))throw new RuntimeException('Missing name '.$required);
$input=file_get_contents($source);
if(!is_string($input))throw new RuntimeException('Unable to load source');
$off=(int)$target['offset']+28; // Epic FObjectImport: ClassPackage(8), ClassName(8), OuterIndex(4), ObjectName(8)
$old=unpack('V',substr($input,$off,4))[1];
if($old!==$indices['None'])throw new RuntimeException('Wrong original wire FName index');
if(!is_dir($folder)&&!mkdir($folder,0777,true))throw new RuntimeException('No fixture dir');
$files=[];$errors=[];
foreach(['Original'=>'None','ExplicitEngine'=>'/Script/Engine','ExplicitCoreUObject'=>'/Script/CoreUObject'] as $label=>$name){
 $bytes=$input;
 if($label!=='Original')$bytes=substr_replace($bytes,pack('V',$indices[$name]),$off,4);
 $path=$folder.'/P07'.$label.'.uasset';
 if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('Failed to write '.$path);
 $r=new UnrealPackageReader4($path);
 $issues=$r->validatePackage();
 $actual=$r->getImports()[1]['packageNameText']??null;
 $deltas=0;for($i=0,$len=strlen($bytes);$i<$len;++$i)if($bytes[$i]!==$input[$i])++$deltas;
 $expectedDelta=$label==='Original'?0:1;
 if($issues!==[]||$actual!==$name||$deltas!==$expectedDelta||strlen($bytes)!==strlen($input))
  $errors[]=['case'=>$label,'issues'=>$issues,'name'=>$actual,'diff'=>$deltas];
 $files[$label]=['path'=>$path,'name'=>$name,'sha256'=>hash_file('sha256',$path),'different_bytes'=>$deltas];
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'source'=>$source,'source_sha256'=>hash_file('sha256',$source),
 'version'=>$header['version'],'file_size'=>strlen($input),'import_index'=>1,'wire_package_name_index_offset'=>$off,
 'name_indices'=>$indices,'cases'=>$files,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($ok?0:2);
