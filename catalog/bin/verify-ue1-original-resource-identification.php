#!/usr/bin/env php
<?php
/**
 * Original Unreal v0.82/v0.83 resource v9 and v0.84a resource v10.
 * Native UnRes.cpp write/read, UnResTyp.h FResourceFileTrailer at EOF.
 * Uses synthetic native-layout file bytes and the real classifier/inspector.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/GameProfiles.php';
require_once dirname(__DIR__) . '/lib/CatalogParser.php';
require_once dirname(__DIR__) . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
use UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceFormat;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_game_profiles (id INTEGER PRIMARY KEY,game_id INTEGER,profile_name TEXT,engine_key TEXT,is_active INTEGER,compatibility_rules_json TEXT,package_version_min INTEGER,package_version_max INTEGER)');
$db->exec('CREATE TABLE ue_games (id INTEGER PRIMARY KEY,name TEXT,slug TEXT,profile_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES (1,12,'Original Unreal','UE1',1,'[]',9,69)");
$db->exec("INSERT INTO ue_games VALUES(12,'Unreal','unrealgold',1)");
$checks = [];$failed = [];
$check = static function(string $name, bool $ok) use (&$checks,&$failed):void {
    $checks[$name]=$ok;if(!$ok){$failed[]=$name;}
};
$resource = static function(int $version):string {
    $tag="Unreal Resource\x1A";
    $start=$tag."\0";
    $namesOffset=strlen($start)+strlen('[REFERENCED NAMES]')+24;
    $body=$start.'[REFERENCED NAMES]'.str_repeat('N',24)
        .'[IMPORTED RESOURCES]'.str_repeat('I',12)
        .'[EXPORTED RESOURCES]'.str_repeat('E',12)
        .'[EXPORTED RESOURCE HEADERS]'.str_repeat('H',20);
    $trailer=pack('V9',$version,2,1,3,$namesOffset,30,60,90,20)
        .str_repeat("\0",16).str_repeat("\0",80)
        .pack('a32',$tag);
    if(strlen($trailer)!==164){throw new RuntimeException('Source-defined resource trailer changed width');}
    return $body.'[SUMMARY]'.$trailer;
};
$path = tempnam(sys_get_temp_dir(),'ue1_resource_');
if(!is_string($path)){throw new RuntimeException('Unable to allocate temp fixture');}
try {
    foreach ([9,10,11] as $version){
        file_put_contents($path,$resource($version));
        $direct=CatalogUE1OriginalResourceFormat::identify($path);
        $prelim=gp_read_legacy_summary($path);
        $classification=gp_classify_file($db,12,$path,'Source.unr');
        $inspect=CatalogPackageHeaderInspector::inspect($path,['engine_key'=>'UE1','extension'=>'unr']);
        [$stagingEngine,$stagingSummary]=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex::detectEngine($path,'Source.unr');
        $queueIdentity=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage::identity([], $path, 'Source.unr', $prelim);
        $headerRejected=false;
        try { catalog_try_read_package_header([], 'UE1', $path); }
        catch (Throwable $e) { $headerRejected=str_contains($e->getMessage(),'Original Unreal resource format version '.$version); }
        $stagingParseRejected=false;
        try {
            (new \UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex($db, []))
                ->parse($path,'Source.unr',12,'Source.unr');
        } catch (RuntimeException $e) {
            $stagingParseRejected=str_contains($e->getMessage(),'Original Unreal resource format version '.$version);
        }
        $check("v{$version}_probe_format",($direct['format']??null)==='early_unreal_resource' && ($direct['version']??null)===$version);
        $check("v{$version}_source_offsets",($direct['counts']??null)===['names'=>3,'imports'=>1,'exports'=>2]
            && ($direct['table_offsets']['headers_length']??null)===20);
        $check("v{$version}_original_not_promoted",($prelim['ok']??null)===false
            && ($prelim['error_code']??null)==='unreal.early_resource_unsupported');
        $check("v{$version}_classifier_preserves_version",($classification['package_version']??null)===$version
            && ($classification['detected_engine']??null)==='UE1'
            && ($classification['ok_for_selected_game']??null)===false
            && ($classification['header_ok']??null)===false);
        $check("v{$version}_inspector_not_modern",($inspect['ok']??null)===false
            && str_contains((string)($inspect['error']??''),'Original Unreal resource format version '.$version));
        $check("v{$version}_staging_identity_not_readable",$stagingEngine==='UE1'
            && ($stagingSummary['version']??null)===$version && ($stagingSummary['ok']??null)===false);
        $check("v{$version}_queue_no_fabricated_guid",($queueIdentity['package_guid']??null)===''
            && ($queueIdentity['md5']??null)===md5_file($path));
        $check("v{$version}_source_http_queue_reader_rejects",$headerRejected);
        $check("v{$version}_unverified_staging_preserves_reason",$stagingParseRejected);
        $verifiedRejected=false;
        try {
            (new \UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector($db, []))
                ->inspect(12, $path, 'Source.unr', true, 'Source.unr');
        } catch (\UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException $e) {
            $verifiedRejected=$e->getMessage()!=='';
        }
        $check("v{$version}_verified_upload_explains_unsupported",$verifiedRejected);
    }
    $valid=$resource(9);
    file_put_contents($path,substr_replace($valid,'[BADSUMM]',strlen($valid)-164-9,9));
    $missingMarker=gp_read_legacy_summary($path);
    $markerProbe=CatalogUE1OriginalResourceFormat::identify($path);
    // Source explicitly marks the marker informational; AddFile does not read it.
    $check('marker_not_required_by_epic_loader',($missingMarker['error_code']??null)==='unreal.early_resource_unsupported'
        && ($markerProbe['writer_marker_present']??null)===false && ($markerProbe['version']??null)===9);
    file_put_contents($path,substr_replace($valid,'BAD TAG',strlen($valid)-32,7));
    $tagProbe=CatalogUE1OriginalResourceFormat::identify($path);
    $check('trailer_tag_not_required_by_epic_loader',(gp_read_legacy_summary($path)['error_code']??null)==='unreal.early_resource_unsupported'
        && ($tagProbe['writer_trailer_tag_present']??null)===false && ($tagProbe['version']??null)===9);
    file_put_contents($path,substr($valid,0,45));
    $check('truncated_resource_rejected',(gp_read_legacy_summary($path)['error_code']??null)==='unreal.early_resource_malformed');
    file_put_contents($path,"Not Unreal\0".substr($valid,11));
    $check('near_miss_not_resource',(gp_read_legacy_summary($path)['error_code']??null)==='unreal.magic_not_found');
    file_put_contents($path,pack('Vvv',0x9E2A83C1,68,0).str_repeat("\0",56));
    $check('standard_v68_still_detected',(gp_read_legacy_summary($path)['format']??null)==='legacy_package');
    file_put_contents($path,pack('a32','Unrealfile'."\x1A").pack('V6',23,0,0,0,0,0));
    $check('different_unrealfile_v23_unchanged',(gp_read_legacy_summary($path)['error_code']??null)==='unreal.early_unrealfile_unsupported');
} finally { @unlink($path); }
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),'total'=>count($checks),'failures'=>$failed,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
