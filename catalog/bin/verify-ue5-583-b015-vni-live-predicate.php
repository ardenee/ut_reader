<?php
declare(strict_types=1);
/** Genuine UnrealEditor-Cmd 5.8.3 enumeration of controlled UVerseEnum flag cases. */
$path=dirname(__DIR__).'/tests/fixtures/ue5-b015-vni-genuine-uverseenum-runtime.log.gz';
$bytes=is_file($path)?file_get_contents($path):false;
$raw=is_string($bytes)?gzdecode($bytes):false;
$log=is_string($raw)?$raw:'';
$checks=[
 'archived_gzip_sha256'=>is_string($bytes)&&hash('sha256',$bytes)==='3d5dfbc1bf4dd69a74593c3641c040af0fc325f615863c0ddf44c7de05e4c668',
 'original_ue_runtime_sha256'=>is_string($raw)&&hash('sha256',$raw)==='dbabb7dfabe97268fd99416239d6d9b1090f7d63ca2754d5c0d6c765a8dd8092',
 'controlled_flagged_real_uverseenum_objects'=>str_contains($log,'UEDB_B015_VNI_PROBE|package=/Engine/_Verse/VNI/UedbB015|compiled=1|uht=1|compiler=0'),
 'real_compiled_in_vni_package_selected'=>str_contains($log,'UEDB_RT|event=eligible_package|kind=verse_vni|path=/Engine/_Verse/VNI/UedbB015'),
 'uht_flagged_public_enum_registered'=>str_contains($log,'UEDB_RT|event=add|kind=enumerated_public|index=675D07B86F4B7EDF|path=/Engine/_Verse/VNI/UedbB015.UedbUhtProbe'),
 'non_uht_public_enum_excluded'=>str_contains($log,'UEDB_RT|event=excluded|reason=verse_vni_non_uht|path=/Engine/_Verse/VNI/UedbB015.UedbCompilerProbe'),
 'negative_enum_never_registered'=>!str_contains($log,'kind=enumerated_public|index=')||!preg_match('/kind=enumerated_public\|[^\r\n]*UedbCompilerProbe/',$log),
 'actual_editor_registration_completed'=>str_contains($log,'UEDB_RT|event=registration_complete|')&&str_contains($log,'|editor=1|check=1|debug=1|count=23560'),
 'editor_compiled_verse_vm_off_source'=>is_file(dirname(__DIR__).'/tests/fixtures/ue5-b015-editor-compiled-definitions.h')
    && hash_file('sha256',dirname(__DIR__).'/tests/fixtures/ue5-b015-editor-compiled-definitions.h')==='c23d37d28aa4760d5ef4e85d6d408eacfb94adc834506efa07c7344b7a0fe7ff'
    && preg_match('/^#define WITH_VERSE_VM 0\s*$/m',(string)file_get_contents(dirname(__DIR__).'/tests/fixtures/ue5-b015-editor-compiled-definitions.h'))===1,
 'editor_compiled_verse_compiler_on_source'=>str_contains((string)file_get_contents(dirname(__DIR__).'/tests/fixtures/ue5-b015-editor-compiled-definitions.h'),'#define WITH_VERSE_COMPILER 1'),
];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','disposition'=>'partial','checks'=>$checks,'genuine_engine_predicate_and_enumeration_executed'=>true,'source_uht_codegen_proven'=>false,'with_verse_vm'=>false,'scriptcells_executed'=>false,'editor_shutdown_cleanly'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
