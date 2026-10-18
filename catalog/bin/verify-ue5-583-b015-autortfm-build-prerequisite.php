<?php
declare(strict_types=1);
/** Replays failed real VerseVM build logs. This is a blocker verifier, never operation acceptance. */
$base=dirname(__DIR__).'/tests/fixtures/';
$cases=[
 'msvc1450'=>['b015-versevm-msvc1450-build.log.gz','3f5f1a2c1d867330a0b0f3bf119a0943e36252a59a7d86ceef6c9feea7cb16c7','cba8f7e90cf1d63f144a7dac6983cea59ee6fff80b0069d0e145adec56c7d63b'],
 'clang22'=>['b015-versevm-clang-build.log.gz','f9bd39b050249b71bf028d359836f5ef1db0232baecf087686ad060e2d14a5f4','3c4665420c7e0313c195240ce7763f9add6b84cbea0e603173045d950625c925'],
];
$logs=[];$checks=[];
foreach($cases as $name=>[$file,$zipHash,$rawHash]){
 $zip=@file_get_contents($base.$file);
 $raw=is_string($zip)?@gzdecode($zip):false;
 $checks[$name.'_compressed_sha256']=is_string($zip) && hash('sha256',$zip)===$zipHash;
 $checks[$name.'_original_sha256']=is_string($raw) && hash('sha256',$raw)===$rawHash;
 $logs[$name]=is_string($raw)?$raw:'';
}
$checks['msvc1450_selected']=str_contains($logs['msvc1450'],'Using Visual Studio 14.50.35739 toolchain');
$checks['msvc1450_incomplete_verse_type_error']=str_contains($logs['msvc1450'],'VVMWriteBarrier.h')&&str_contains($logs['msvc1450'],'error C2139');
$checks['clang22_selected']=str_contains($logs['clang22'],'Using Clang 22.1.3 compiler');
$checks['clang_requires_autortfm']=str_contains($logs['clang22'],'static assertion failed: New VM depends on AutoRTFM.');
$checks['clang_heap_source_error']=str_contains($logs['clang22'],'VVMHeap.cpp(491,26): error: expected expression');
$checks['neither_build_claimed_success']=!str_contains($logs['msvc1450'],'Result: Succeeded')&&!str_contains($logs['clang22'],'Result: Succeeded');
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','disposition'=>'partial','role'=>'blocked-build evidence; NOT runtime acceptance','checks'=>$checks,'scriptcells_executed'=>false,'uht_codegen_proven'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
