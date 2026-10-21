#!/usr/bin/env php
<?php
/**
 * Real application classification -> UE1 source factory -> V5 snapshot builder.
 * Uses an isolated SQLite profile fixture (NO production MySQL writes).
 * Genuine SHA-pinned bytes: versions 35-61 and 68/69; 92 file records.
 * Does not exercise durable MySQL persistence or native engine runtime.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/GameProfiles.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SourceSnapshotFactory;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_game_profiles (
    id INTEGER PRIMARY KEY, game_id INTEGER, profile_name TEXT,
    engine_key TEXT, is_active INTEGER, allowed_extensions_json TEXT,
    package_version_min INTEGER, package_version_max INTEGER,
    compatibility_rules_json TEXT)');
$db->exec('CREATE TABLE ue_games (id INTEGER PRIMARY KEY,
    name TEXT, slug TEXT, profile_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES
    (1,3,'UT99 UE1','UE1',1,'[\"utx\",\"uax\",\"unr\"]',60,69,'[]'),
    (2,12,'Unreal UE1','UE1',1,'[\"utx\",\"uax\",\"unr\"]',34,69,'[]'),
    (3,2,'UE2 tag compatibility','UE2',1,'[\"utx\"]',34,180,'[]')");
$db->exec("INSERT INTO ue_games VALUES (3,'UT99','ut99',1),(12,'Unreal','unrealgold',2),(2,'Unreal II','unreal2',3)");
$factory = new Uedb5SourceSnapshotFactory($db, []);
$isolatedStorage = sys_get_temp_dir() . '/unrealdb-ue1-b05-' . bin2hex(random_bytes(6));
if (!mkdir($isolatedStorage, 0775, true)) { throw new RuntimeException('Cannot create isolated V5 storage'); }
$writer = new Uedb5MetadataSnapshotWriter($isolatedStorage);
$v5reader = new Uedb5MetadataReader($isolatedStorage);
$readManifest = static function(string $manifest): array{
    $fp=fopen($manifest,'rb');if(!$fp){throw new RuntimeException('Missing '.$manifest);}
    if(fread($fp,3)!=="\xEF\xBB\xBF"){rewind($fp);}
    $columns=fgetcsv($fp,0,',','"','');
    $rows=[];
    while(($line=fgetcsv($fp,0,',','"',''))!==false){
        $r=array_combine($columns,$line);
        if(!is_array($r)){throw new RuntimeException('Invalid corpus row');}
        $rows[]=$r;
    }
    fclose($fp);
    return $rows;
};
$base=dirname(__DIR__).'/compliance/inventory/';
$old=$readManifest($base.'ue1-textures-v35-v61.csv');
$new=$readManifest($base.'ue1-packages-v68-v69.csv');
$errors=[];$completed=0;$sourceCount=['unrealgold'=>0,'ut99'=>0];
foreach(array_merge($old,$new) as $num=>$r){
    $root=$r['SourceRoot']??'L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures';
    $gameId=str_contains($root,'\\Games\\UT99')?3:12;
    $source=$gameId===3?'ut99':'unrealgold';
    $path=rtrim($root,'\\/').DIRECTORY_SEPARATOR.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$r['RelativePath']);
    try{
        if(!is_file($path)||strtolower((string)hash_file('sha256',$path))!==strtolower((string)$r['Sha256'])){
            throw new RuntimeException('Source package hash mismatch');
        }
        $classification=gp_classify_file($db,$gameId,$path,basename($path));
        foreach(['header_ok','ok_for_selected_game'] as $field){
            if(!($classification[$field]??false)){throw new RuntimeException('Classifier '.$field.' false '.json_encode($classification));}
        }
        if(($classification['reader_engine']??null)!=='UE1'
            ||(int)($classification['package_version']??0)!==(int)$r['PackageVersion']
            ||(int)($classification['licensee_version']??-1)!==(int)$r['LicenseeVersion']){
            throw new RuntimeException('Classified identity mismatch');
        }
        $file=['id'=>$num+100,'game_id'=>$gameId,'package_name'=>pathinfo($path,PATHINFO_FILENAME),
            'original_name'=>basename($path),'package_version'=>(int)$r['PackageVersion'],
            'licensee_version'=>(int)$r['LicenseeVersion']];
        $snapshot=$factory->buildForGameId($gameId,$path,$file);
        if(($snapshot['package_family']??null)!=='classic-linkerload'
            ||count($snapshot['sections']['summary']??[])!==1
            ||(int)($snapshot['sections']['summary'][0]['package_version']??-1)!==(int)$r['PackageVersion']
            ||(int)($snapshot['sections']['summary'][0]['licensee_version']??-1)!==(int)$r['LicenseeVersion']){
            throw new RuntimeException('V5 snapshot summary mismatch');
        }
        foreach(['names'=>'name_count','imports'=>'import_count','exports'=>'export_count'] as $section=>$countField){
            if(count($snapshot['sections'][$section]??[])!==(int)($snapshot['sections']['summary'][0][$countField]??-1)){
                throw new RuntimeException('V5 '.$section.' row count mismatch');
            }
        }
        // Exercise the production UEDB5 block writer/reader, isolated from
        // catalog storage. A generated snapshot is insufficient without
        // successful on-disk serialization and section read-back.
        $written = $writer->write($snapshot, 1024);
        if (!is_file($written['path']) || strlen((string)($written['payload_sha256'] ?? '')) !== 32) {
            throw new RuntimeException('V5 writer/manifest failed');
        }
        $roundTrip = $v5reader->snapshot($gameId, $num + 100);
        if (($roundTrip['package_family'] ?? null) !== $snapshot['package_family']
            || ($roundTrip['source_policy'] ?? null) !== $snapshot['source_policy']
            || ($roundTrip['sections']['summary'][0] ?? null) !== $snapshot['sections']['summary'][0]) {
            throw new RuntimeException('V5 roundtrip source summary changed');
        }
        foreach (['names','imports','exports'] as $section) {
            if (($roundTrip['sections'][$section] ?? null) !== $snapshot['sections'][$section]) {
                throw new RuntimeException('V5 roundtrip ' . $section . ' differs from snapshot');
            }
        }
        $sourceCount[$source]++;$completed++;
    }catch(Throwable $ex){
        $errors[]=['file'=>$path,'error'=>$ex->getMessage()];
    }
}
// Source loader rejects invalid input even if the cheap classifier
// provisionally admits its first 8 bytes. Never turn fallback blank headers
// into durable V5 metadata; synthetic cases are NOT part of the 92 corpus.
$negativeChecks=[];
$genuineSample = rtrim('L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures','\\/')
    . DIRECTORY_SEPARATOR . str_replace(['\\','/'],DIRECTORY_SEPARATOR,$old[0]['RelativePath']);
$original = file_get_contents($genuineSample);
if (!is_string($original) || strlen($original) < 44) { throw new RuntimeException('No negative test template'); }
$negativePath = $isolatedStorage . '/invalid-package.utx';
foreach ([
    'ue2_only_magic' => substr_replace($original,pack('V',0x9E2A83C2),0,4),
    'out_of_file_name_offset' => substr_replace($original,pack('V',0x7FFFFFFF),16,4),
] as $case=>$bytes) {
    file_put_contents($negativePath,$bytes);
    $classified = gp_classify_file($db,12,$negativePath,'invalid-package.utx');
    $headerOutcome=($classified['header_ok']??false);
    $rejected=false;
    try {
        $factory->buildForGameId(12,$negativePath,[
            'id'=>5000,'game_id'=>12,'package_name'=>'invalid-package',
            'original_name'=>'invalid-package.utx','package_version'=>(int)$old[0]['PackageVersion'],
            'licensee_version'=>0]);
    } catch (Throwable $e) { $rejected=true; }
    $expectedHeader=$case==='out_of_file_name_offset';
    $ue2Ok=true;
    if($case==='ue2_only_magic') {
        // The original UE2/Killing Floor package-tag exception must survive.
        $ue2=gp_classify_file($db,2,$negativePath,'invalid-package.utx');
        $ue2Ok=($ue2['header_ok']??false)===true
            && ($ue2['ok_for_selected_game']??false)===true
            && ($ue2['reader_engine']??null)==='UE2';
    }
    $negativeChecks[$case] = $rejected && $headerOutcome===$expectedHeader && $ue2Ok;
    if(!$negativeChecks[$case]){$errors[]=['file'=>$case,'error'=>'Invalid package not rejected with correct stage semantics'];}
}
// Delete only the test's unique temporary V5 staging directory.
$it=new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($isolatedStorage,FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
@rmdir($isolatedStorage);
echo json_encode(['ok'=>$errors===[]&&$completed===92,'total'=>count($old)+count($new),
    'passed'=>$completed,'failed'=>count($errors),'by_source'=>$sourceCount,
    'negative_checks'=>$negativeChecks,'failures'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($errors===[]&&$completed===92?0:1);
