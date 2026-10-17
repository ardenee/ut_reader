<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptRuntimeTraceInspector as Inspector;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptImportPathHasher as Hasher;

if(PHP_SAPI!=='cli') exit(2);
$checks=[];$failures=[];
$check=static function(string $name,bool $result)use(&$checks,&$failures):void {
    $checks[$name]=$result;
    if(!$result)$failures[]=$name;
};
$root=dirname(__DIR__,2);
$generator=(string)file_get_contents($root.'/catalog/bin/prepare-ue5-script-runtime-trace.php');
$check('trace_source_generator_logs_hash_input_paths',
    substr_count($generator,'->GetPathName()')===9
    &&!str_contains($generator,'->GetFullName()'));
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),"/\\");
$source=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$check('trace_unchanged_epic_source_pinned',
    is_file($source) &&hash_file('sha256',$source)===Inspector::PINNED_EPIC_SHA);
$path='/Script/Engine.Actor';
$nested='/Script/Engine.Actor:Nested';
$verse='/Project/_Verse/VNI/Foo.UHTObject';
$key=static function(string $name):string {
    $raw=Hasher::fromObjectPath($name);
    return strtoupper(dechex(hexdec($raw[0])+4)).substr($raw,1);
};
$actor=$key($path);
$nestedIndex=$key($nested);
$verseIndex=$key($verse);
$scriptPkg=$key('/Script/Engine');
$cellIndex=$key('/Project/_Verse/VNI/Foo.VerseCell');
$prefix='[2026.10.17-12.00.00:000][  0]LogStreaming: Display: UEDB_RT|';
$make=static fn(string $payload):string=>$prefix.$payload."\r\n";
$events=[
    'event=eligible_package|kind=script|path=/Script/Engine',
    'event=eligible_package|kind=verse_vni|path=/Project/_Verse/VNI/Foo',
    'event=add|kind=editor_package|index='.$scriptPkg.'|path=/Script/Engine',
    'event=add|kind=initial_object|index='.$actor.'|path='.$path,
    'event=add|kind=nested_public|index='.$nestedIndex.'|path='.$nested,
    'event=add|kind=enumerated_public|index='.$verseIndex.'|path='.$verse,
    'event=lookup|index='.$actor.'|found=1',
    'event=lookup|index='.$key('/Script/Engine.Missing').'|found=0',
    'event=name|branch=live|index='.$actor,
    'event=name|branch=cooked_diagnostic|index='.$key('/Script/Engine.Missing'),
    'event=add_cell|index='.$cellIndex,
    'event=excluded|reason=verse_vni_non_uht|path=/Project/_Verse/VNI/Foo.CompilerObject',
    'event=remove_gc|index='.$nestedIndex.'|path='.$nested,
    'event=registration_complete|epic_sha='.Inspector::PINNED_EPIC_SHA.'|editor=1|check=1|debug=1|count=4'
];
$temp=sys_get_temp_dir().'/uedb5-script-trace-reader-'.bin2hex(random_bytes(4)).'.log';
$write=static function(array $lines)use($temp,$make):void {
    file_put_contents($temp,"LogInit: Engine Version: 5.8.3\n".implode('',array_map($make,$lines)));
};
$analyze=static function(array $lines)use($temp,$write):array {
    $write($lines);
    return Inspector::inspectFile($temp);
};
try{
    $valid=$analyze($events);
    $check('trace_good_fixture_parses_without_claiming_engine',
        $valid['ok']===true &&$valid['events_seen']===count($events)
        &&$valid['source_marker_matches_pinned_ue583']===true
        &&$valid['runtime_origin_attested']===false
        &&$valid['live_engine_behavior_independently_verified']===false
        &&$valid['original_operation_dispositions_unchanged']===true);
    $check('trace_normalized_typed_indices_verified',
        $valid['distinct_registered_indices_seen']===4
        &&$valid['malformed_event_count']===0
        &&$valid['rejected_semantic_event_count']===0);
    $check('trace_diagnostic_path_does_not_prove_live_uobject',
        ($valid['live_lookups_reported'][0]??0)===1
        &&($valid['diagnostic_names_reported']['cooked_diagnostic']??0)===1
        &&$valid['live_engine_behavior_independently_verified']===false);
    $check('trace_vm_cells_remain_distinct_from_object_indices',
        $valid['distinct_verse_cell_indices_seen']===1
        &&$valid['distinct_registered_indices_seen']===4);
    $check('trace_single_registration_completion_required',
        $valid['registration_complete_events']===1
        &&$valid['snapshots'][0]['script_object_count']===4);
    $check('trace_gc_removal_is_recorded_not_assumed_to_prove_lifecycle',
        ($valid['event_counts']['remove_gc']??0)===1
        &&$valid['original_operation_dispositions_unchanged']===true);
    $check('trace_no_object_paths_disclosed_in_summary',
        !str_contains(json_encode($valid,JSON_THROW_ON_ERROR),$path)
        &&!str_contains(json_encode($valid,JSON_THROW_ON_ERROR),$verse));

    $modified=$events; $modified[3]='event=add|kind=initial_object|index='.$nestedIndex.'|path='.$path;
    $r=$analyze($modified);
    $check('trace_bad_path_index_hash_rejected',
        $r['ok']===false&&$r['rejected_semantic_event_count']>0);

    $modified=$events; $modified[13]='event=registration_complete|epic_sha='.str_repeat('0',64).'|editor=1|check=1|debug=1|count=4';
    $r=$analyze($modified);
    $check('trace_fake_epic_source_marker_rejected',
        !$r['ok']&&!$r['source_marker_matches_pinned_ue583']);

    $modified=$events;array_pop($modified);
    $r=$analyze($modified);
    $check('trace_missing_completion_not_accepted',
        !$r['ok']&&$r['registration_complete_events']===0);

    $modified=$events;$modified[]=$events[13];
    $r=$analyze($modified);
    $check('trace_duplicate_completion_not_accepted',
        !$r['ok']&&$r['registration_complete_events']===2);

    $modified=$events;$modified[3]='event=add|kind=initial_object|index=0123456789ABCDEF|path='.$path;
    $r=$analyze($modified);
    $check('trace_untyped_script_index_rejected',
        !$r['ok']&&$r['malformed_event_count']>0);

    $modified=$events;$modified[3]='event=add|kind=initial_object|index='.$actor.'|path=Actor '.$path;
    $r=$analyze($modified);
    $check('trace_class_prefixed_fullname_cannot_masquerade_as_hash_path',
        !$r['ok']&&$r['rejected_semantic_event_count']>0);

    $modified=$events;$modified[2].='|index='.$scriptPkg;
    $r=$analyze($modified);
    $check('trace_duplicate_field_rejected',!$r['ok']&&$r['malformed_event_count']>0);

    $modified=$events;$modified[2]='event=unknown|kind=editor_package|index='.$scriptPkg.'|path=/Script/Engine';
    $r=$analyze($modified);
    $check('trace_unknown_event_rejected',!$r['ok']&&$r['malformed_event_count']>0);

    $modified=$events;$modified[0]='event=eligible_package|kind=script|path=/Game/FalseScript';
    $r=$analyze($modified);
    $check('trace_wrong_script_package_classification_rejected',
        !$r['ok']&&$r['rejected_semantic_event_count']>0);

    $modified=$events;$modified[13]='event=registration_complete|epic_sha='.Inspector::PINNED_EPIC_SHA.'|editor=0|check=1|debug=1|count=0';
    $r=$analyze($modified);
    $check('trace_editor_insertion_in_noneditor_session_rejected',!$r['ok']);

    $modified=$events;$modified[13]='event=registration_complete|epic_sha='.Inspector::PINNED_EPIC_SHA.'|editor=1|check=1|debug=0|count=4';
    $r=$analyze($modified);
    $check('trace_cooked_debug_fallback_in_nodebug_build_rejected',!$r['ok']);

    $modified=$events; $modified[4]='event=add|kind=nested_public|index='.$actor.'|path=/Script/ENGINE.actor';
    $r=$analyze($modified);
    $check('trace_colliding_hash_identity_reported_without_invented_winner',
        $r['ok']===true&&$r['hash_collision_indices_reported']===1);

    $r=$analyze(['event=lookup|index='.$actor.'|found=1']);
    $check('trace_partial_or_synthetic_log_does_not_certify_runtime',
        !$r['ok']&&$r['runtime_origin_attested']===false);

    file_put_contents($temp,"LogInit: Engine Version: 5.8.3\n");
    $r=Inspector::inspectFile($temp);
    $check('trace_stock_noninstrumented_editor_log_is_not_evidence',
        !$r['ok']&&$r['events_seen']===0);

    $cli=dirname(__DIR__).'/bin/inspect-ue5-script-runtime-trace.php';
    $write($events);
    $lines=[];$exit=127;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($cli).' --log='.escapeshellarg($temp).' 2>&1',$lines,$exit);
    $cliOutput=json_decode(implode("\n",$lines),true);
    $check('trace_readonly_cli_emits_machine_readable_summary',
        $exit===0&&is_array($cliOutput)&&$cliOutput['ok']===true
        &&$cliOutput['runtime_origin_attested']===false);
    $lines=[];$exit=127;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($cli).' --log='.escapeshellarg($temp).' --accept-live 2>&1',$lines,$exit);
    $check('trace_cli_has_no_unsafe_accept_live_flag',$exit===2);
} catch(Throwable $e) {
    $failures[]='trace_test_exception';
    $checks['trace_test_exception']=$e->getMessage();
} finally {
    @unlink($temp);
}
echo json_encode([
    'ok'=>$failures===[],'original_operations_verified_by_this_test'=>0,
    'operation_scope'=>['UE5-583-SCRIPT-003','UE5-583-SCRIPT-004','UE5-583-SCRIPT-005','UE5-583-B015-1'],
    'checks'=>$checks,'failures'=>$failures,'synthetic_fixtures_are_not_real_runtime_evidence'=>true
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($failures===[]?0:1);
