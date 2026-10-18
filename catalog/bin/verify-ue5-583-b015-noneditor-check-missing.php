<?php
declare(strict_types=1);
/** B015 genuine non-editor DO_CHECK missing-script registration warning. */
$path=dirname(__DIR__).'/tests/fixtures/ue5-b015-noneditor-check-missing-original.log';
$hash='7b60cd877b43b61ae3c819b395fb3b4a502166edeff491bb31ba3ec02982ee09';
$bytes=is_file($path)?file_get_contents($path):false;
$log=is_string($bytes) && str_starts_with($bytes,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($bytes,2)):'';
$c=[];
$c['original_trace_sha256']=is_string($bytes)&&hash('sha256',$bytes)===$hash;
$c['injected_existing_index']=str_contains($log,'UEDB_B015_PROBE|before=1');
$c['actual_epic_missing_registration_warning']=str_contains($log,'Script object Class /Script/CoreUObject.Object (0x3F4F75B2E5B5C556) is missing a NotifyRegistrationEvent from the initial load phase.');
$c['index_restored']=str_contains($log,'UEDB_B015_PROBE|restored=1');
$c['actual_noneditor_do_check_registration_complete']=str_contains($log,'|editor=0|check=1|debug=0|count=851');
$c['correct_event_order']=strpos($log,'UEDB_B015_PROBE|before=1')<strpos($log,'is missing a NotifyRegistrationEvent')
 && strpos($log,'is missing a NotifyRegistrationEvent')<strpos($log,'UEDB_B015_PROBE|restored=1')
 && strpos($log,'UEDB_B015_PROBE|restored=1')<strpos($log,'|editor=0|check=1|debug=0|count=851');
$ok=!in_array(false,$c,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','checks'=>$c,'disposition'=>'partial','no_check_covered'=>false,'verse_vni_scriptcells_covered'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
