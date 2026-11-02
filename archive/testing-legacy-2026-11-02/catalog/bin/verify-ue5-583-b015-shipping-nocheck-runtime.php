<?php
declare(strict_types=1);
/**
 * UE5.8.3 genuine Shipping UnrealPak / B015 noneditor no-check acceptance.
 * Checks retained native log, build macros, typed object counts and negative
 * boundaries; does not attest VM ScriptCells or generated Verse provenance.
 */
$dir=dirname(__DIR__).'/tests/fixtures/';
$files=[
 'log'=>['ue5-b015-nocheck-shipping-original.log.gz','cf51c337a356a6b407150a654861c67ec84c7edc82a3cd9ec4e56fbadbdd9f74','82754f24e42fbfbeac62f26b97b7007f2d5f7d73991a5217a772b121e12ce2fd'],
 'definitions'=>['ue5-b015-nocheck-shipping-definitions.h.gz','ea5144b87433af554294f3048383c7403c7cdd2771786008acf94b1e93586317','5f8214848a71a5950671b6cda9825555c6374d9aa3eecb11e322fcb490a131c6'],
];
$checks=[];
$text=[];
foreach($files as $key=>[$filename,$ziphash,$rawhash]){
 $zip=is_file($dir.$filename)?file_get_contents($dir.$filename):false;
 $raw=is_string($zip)?gzdecode($zip):false;
 $checks[$key.'_gzip_sha256']=is_string($zip)&&hash('sha256',$zip)===$ziphash;
 $checks[$key.'_raw_sha256']=is_string($raw)&&hash('sha256',$raw)===$rawhash;
 $text[$key]=$key==='log' && is_string($raw)&&str_starts_with($raw,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):(is_string($raw)?$raw:'');
}
$log=$text['log'];$defines=$text['definitions'];
$checks['compiled_with_editor_0']=preg_match('/^#define WITH_EDITOR 0\s*$/m',$defines)===1;
$checks['compiled_with_verse_vm_0']=preg_match('/^#define WITH_VERSE_VM 0\s*$/m',$defines)===1;
$checks['compiled_shipping_target']=preg_match('/^#define UE_BUILD_SHIPPING 1\s*$/m',$defines)===1;
$checks['canonical_epic_source_identity']=str_contains($log,'epic_sha=43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$checks['actual_no_check_registration_complete']=preg_match('/UEDB_RT\|event=registration_complete\|[^\r\n]*\|editor=0\|check=0\|debug=0\|count=467(?:\r?\n|$)/',$log)===1;
$checks['single_registration_complete']=substr_count($log,'UEDB_RT|event=registration_complete|')===1;
$checks['initial_object_records_415']=substr_count($log,'|kind=initial_object|')===415;
$checks['nested_public_records_52']=substr_count($log,'|kind=nested_public|')===52;
$checks['script_entries_sum_matches_final_registry']=415+52===467;
$checks['no_editor_packages']=!str_contains($log,'kind=editor_package');
$checks['no_editor_enumeration']=!str_contains($log,'kind=enumerated_public');
$checks['no_verse_vni_enumeration']=!str_contains($log,'kind=verse_vni');
$checks['actual_unrealpak_execution']=str_contains($log,'UnrealPak executed in ');
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','subgate'=>'noneditor-no-check','checks'=>$checks,
'registered_total'=>467,'initial_objects'=>415,'nested_public_objects'=>52,
'no_check_runtime_verified'=>$ok,'verse_provenance_and_scriptcells_verified'=>false,'operation_disposition'=>'partial'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
