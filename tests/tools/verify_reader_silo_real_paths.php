<?php
declare(strict_types=1);
/** Regression of real UnrealDB game snapshot paths after separating UE1/UE2 parser implementations. */
require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
$cases = [
 'unreal_v61' => ['UE1', 'L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Textures/Female2Skins.utx', 12, 'unreal'],
 'ut99_v432' => ['UE1', 'C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u', 3, 'ut99'],
 'ut99_469f' => ['UE1', 'C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u', 3, 'ut99'],
 'ue2_v126' => ['UE2', 'H:/Epic Games/UnrealEngine2Runtime/System/Engine.u', 2, 'unreal2'],
];
$manifestPath=dirname(__DIR__).'/evidence/reader-isolation-20261102.json';
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
$results = [];
foreach ($cases as $id => [$engine, $path, $gameId, $game]) {
 if (!is_file($path)) throw new RuntimeException('Required original fixture missing: '.$path);
 $class = CatalogReaderResolver::resolve([], $engine, 'Missing reader', 'Missing class ', ['UE4','UE5']);
 $pkg = new $class($path);
 $issues = $pkg->validatePackage();
 if ($issues !== []) throw new RuntimeException($id.' parse issues: '.implode('; ',$issues));
 $data = ['header'=>$pkg->getHeader(), 'names'=>$pkg->getNames(), 'imports'=>$pkg->getImports(), 'exports'=>$pkg->getExports(), 'issues'=>$issues];
 $file = ['id'=>1, 'game_id'=>$gameId, 'package_name'=>pathinfo($path, PATHINFO_FILENAME), 'original_name'=>basename($path)];
 $snapshot = match ($game) {
  'unreal'=>Uedb5UnrealSnapshotBuilder::build($pkg,$file),
  'ut99'=>Uedb5Ut99SnapshotBuilder::build($pkg,$file),
  'unreal2'=>Uedb5Unreal2SnapshotBuilder::build($pkg,$file),
 };
 // Frozen reader-isolation evidence is immutable. A later source-policy
 // correction may change metadata provenance without changing one parsed byte.
 // Normalize ONLY the known Unreal v61 source-policy correction when comparing
 // the old snapshot hash. Other snapshot changes must still fail.
 $expectedPolicy=(string)($manifest['genuine_game_specific_parser_proof'][$id]['source_policy']??'');
 $historicSnapshot=$snapshot;
 if ((string)$snapshot['source_policy']!==$expectedPolicy) {
  if ($id!=='unreal_v61'
      || (int)$data['header']['version']!==61
      || (int)($data['header']['licenseeVersion']??-1)!==0
      || (string)$snapshot['source_policy']!==Uedb5UnrealSnapshotBuilder::POLICY_V120_V60_61_FORMAT) {
   throw new RuntimeException('Unexpected source policy revision for genuine game path '.$id);
  }
  $historicSnapshot['source_policy']=$expectedPolicy;
 }
 $results[$id] = [
  'file_sha256'=>hash_file('sha256',$path), 'engine'=>$engine,
  'game'=>$game, 'package_version'=>$data['header']['version'],
  'name_count'=>count($data['names']), 'import_count'=>count($data['imports']), 'export_count'=>count($data['exports']),
  'decoded_tables_sha256'=>hash('sha256',serialize($data)),
  'game_specific_uedb5_snapshot_sha256'=>hash('sha256',serialize($historicSnapshot)),
  'source_policy'=>$historicSnapshot['source_policy']??null,
 ];
}
if (($argv[1]??'') === '--print-candidates') {
 echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit(0);
}
foreach ($results as $key=>$result) {
 $expected=$manifest['genuine_game_specific_parser_proof'][$key]??null;
 if (!is_array($expected)||$result!==$expected) throw new RuntimeException('Genuine package or game-specific snapshot changed: '.$key);
 echo $key.' exact reader/game-snapshot regression PASS'.PHP_EOL;
}
echo 'UE1_UE2_INDEPENDENT_PARSERS_GENUINE_GAME_SPECIFIC_SNAPSHOT_4_OF_4_PASS'.PHP_EOL;
