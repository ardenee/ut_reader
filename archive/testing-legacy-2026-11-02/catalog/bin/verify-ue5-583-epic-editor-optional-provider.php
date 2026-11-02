<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealAssetStringify.exe';
$source=__DIR__.'/verify-uedb5-ue5-zen-package.php';
$original=@file_get_contents($source);
if(!is_string($original)||!is_file($exe))throw new RuntimeException('Native editor probe or original fixture unavailable');
$needle='$chunkId = static function';
if(substr_count($original,$needle)!==1)throw new RuntimeException('Source fixture insertion point missing');
$fixture=sys_get_temp_dir().'/ue583-editor-optional-'.getmypid().'.bin';
$temporary=__DIR__.'/_ue583_editor_optional_generator_'.getmypid().'.php';
$generator=str_replace($needle,'file_put_contents('.var_export($fixture,true).', $containerHeader);'.PHP_EOL.'exit(0);'.PHP_EOL.$needle,$original);
try{
 if(file_put_contents($temporary,$generator)!==strlen($generator))throw new RuntimeException('Unable to create isolated fixture generator');
 $out=[];$rc=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temporary).' 2>&1',$out,$rc);
 if($rc!==0||!is_file($fixture))throw new RuntimeException('Fixture generation failed: '.implode(' ',$out));
 $local=Uedb5IoStoreContainerHeaderReader::parse(file_get_contents($fixture));
 $output=[];$nativeRc=0;
 exec(escapeshellarg($exe).' '.escapeshellarg('-NativeOptionalProbe='.$fixture).' 2>&1',$output,$nativeRc);
 $text=str_replace('\\n',"\n",implode("\n",$output));
 if($nativeRc!==0||!preg_match('/UE583_EDITOR_OPTIONAL (\d+) (\d+) (\d+) (\d+) ([0-9A-F]{16}) ([0-9A-F]{16})/',$text,$native))throw new RuntimeException('Epic native editor probe unsuccessful: '.substr($text,-500));
 $ordinary=$local['store_entries'][0]['imported_package_ids']??[];
 $optional=$local['optional_segment_store_entries'][0]['imported_package_ids']??[];
 $checks=[
   'native_status_ok'=>(int)$native[1]===4,
   'ordinary_import_count'=>(int)$native[2]===count($ordinary),
   'optional_import_count'=>(int)$native[3]===count($optional),
   'optional_segment_present'=>(int)$native[4]===1,
   'ordinary_provider_id'=>$native[5]===($ordinary[0]??null),
   'optional_provider_id'=>$native[6]===($optional[0]??null),
   'separate_provider_identities'=>$native[5]!==$native[6]
 ];
 $ok=!in_array(false,$checks,true);
 echo json_encode(['ok'=>$ok,'native_editor_source_branch'=>'FFilePackageStoreBackend::GetPackageStoreEntry WITH_EDITOR','checks'=>$checks,'passed'=>count(array_filter($checks)),'total'=>count($checks)],JSON_PRETTY_PRINT),PHP_EOL;
 $exitCode=$ok?0:2;
}finally{
 if(is_file($temporary))@unlink($temporary);
 if(is_file($fixture))@unlink($fixture);
}
exit($exitCode);
