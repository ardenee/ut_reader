<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$path=sys_get_temp_dir().'/ue583-versioned-zen-fixture.uheader';
if(!is_file($exe))throw new RuntimeException('Pinned native Epic probe binary is missing');
$original=file_get_contents(__DIR__.'/verify-uedb5-ue5-zen-package.php');
if(!is_string($original))throw new RuntimeException('Cannot read existing source-grounded versioned fixture builder');
$needle="$".'packageBytes = ' . "$" . "header . 'DATA';";
if(substr_count($original,$needle)!==1)throw new RuntimeException('Fixture insertion point changed');
$generator=str_replace($needle,$needle."\nfile_put_contents(sys_get_temp_dir().'/ue583-versioned-zen-fixture.uheader', \$packageBytes);\nexit(0);",$original);
$temp=__DIR__.'/_ue583_native_fixture_'.getmypid().'.php';
$emitted=[];$generatorExit=0;
try{
 if(file_put_contents($temp,$generator)!==strlen($generator))throw new RuntimeException('Cannot write isolated fixture generator');
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temp).' 2>&1',$emitted,$generatorExit);
}finally{if(is_file($temp))unlink($temp);}
if($generatorExit!==0||!is_file($path))throw new RuntimeException('Versioned Zen fixture generation failed: '.implode(' ', $emitted));
$store=['entry_index'=>0,'package_id'=>'0123456789ABCDEF','optional_segment'=>false,'imported_package_ids'=>['FEDCBA9876543210'],'shader_map_hashes'=>[]];
$bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('Missing fixture');
$z=Uedb5ZenPackageReader::parse($bytes,'0123456789ABCDEF',$store);
$out=[];$exit=0;exec(escapeshellarg($exe).' -ZenHeaderProbe='.escapeshellarg($path).' -unattended 2>&1',$out,$exit);
$text=str_replace('\\n',"\n",implode("\n",$out));
if(!in_array($exit,[0,777006],true)||!preg_match('/UE583_NATIVE_ZEN_ORACLE (\{[^\r\n]+\})/',$text,$m))throw new RuntimeException('Epic native Zen probe failed '.$exit);
$native=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
$expected=[
 'has_versioning_info'=>1,'header_size'=>(int)$z['summary']['header_size'],
 'package_flags'=>hexdec($z['summary']['package_flags']),
 'imported_public_export_hashes_offset'=>(int)$z['summary']['imported_public_export_hashes_offset'],
 'import_map_offset'=>(int)$z['summary']['import_map_offset'],
 'export_map_offset'=>(int)$z['summary']['export_map_offset'],
 'export_bundle_entries_offset'=>(int)$z['summary']['export_bundle_entries_offset'],
 'dependency_bundle_headers_offset'=>(int)$z['summary']['dependency_bundle_headers_offset'],
 'dependency_bundle_entries_offset'=>(int)$z['summary']['dependency_bundle_entries_offset'],
 'imported_package_names_offset'=>(int)$z['summary']['imported_package_names_offset'],
 'ue4_version'=>522,'ue5_version'=>1018,
 'imported_hash_count'=>count($z['imported_public_export_hashes']),
 'import_count'=>count($z['import_map']),'export_count'=>count($z['exports']),
 'cell_import_count'=>count($z['cell_import_map']),
 'cell_export_count'=>count($z['cell_exports']),
 'bulk_data_count'=>count($z['bulk_data_map']),
 'export_bundle_count'=>count($z['export_bundle_entries']),
 'dependency_bundle_header_count'=>count($z['dependency_bundle_headers']),
 'dependency_bundle_entry_count'=>count($z['dependency_bundle_entries'])
];
$compared=0;$errors=[];
foreach($expected as $k=>$v){if(($native[$k]??null)!==$v)$errors[]=['field'=>$k,'native'=>$native[$k]??null,'unrealdb'=>$v];$compared++;}
preg_match_all('/^UE583_NATIVE_ZEN_EXPORT (\d+) (\d+) (\d+) (\d+) (\d+) (\d+)$/m',$text,$exports,PREG_SET_ORDER);
foreach($exports as $e){$i=(int)$e[1];$ours=$z['exports'][$i]??null;if(!is_array($ours)){$errors[]='missing export';continue;}
 foreach([[2,(int)$ours['cooked_serial_offset']],[3,(int)$ours['cooked_serial_size']],[5,hexdec($ours['object_flags'])],[6,(int)$ours['filter_flags']]] as [$col,$val]){$compared++;if((int)$e[$col]!==$val)$errors[]=['export'=>$i,'column'=>$col,'native'=>$e[$col],'unrealdb'=>$val];}}
preg_match_all('/^UE583_NATIVE_ZEN_IMPORT (\d+) ([0-9A-F]{16})$/m',$text,$imports,PREG_SET_ORDER);
foreach($imports as $e){$i=(int)$e[1];$compared++;if($e[2]!==($z['import_map'][$i]['raw_type_and_id']??null))$errors[]=['import'=>$i,'native'=>$e[2]];}
preg_match_all('/^UE583_NATIVE_ZEN_PUBHASH (\d+) ([0-9A-F]{16})$/m',$text,$hashes,PREG_SET_ORDER);
foreach($hashes as $e){$i=(int)$e[1];$compared++;if($e[2]!==($z['exports'][$i]['public_export_hash']??null))$errors[]=['public_hash'=>$i];}
preg_match_all('/^UE583_NATIVE_ZEN_DEPHEADER (\d+) (-?\d+) (\d+) (\d+) (\d+) (\d+)$/m',$text,$headers,PREG_SET_ORDER);
foreach($headers as $e){$i=(int)$e[1];$h=$z['dependency_bundle_headers'][$i]??null;if(!is_array($h)){$errors[]=['dependency_header'=>$i];continue;}
 $vals=[$h['first_entry_index'],$h['entry_count'][0][0],$h['entry_count'][0][1],$h['entry_count'][1][0],$h['entry_count'][1][1]];
 foreach($vals as $n=>$val){$compared++;if((int)$e[$n+2]!==$val)$errors[]=['dependency_header'=>$i,'column'=>$n];}}
preg_match_all('/^UE583_NATIVE_ZEN_DEPENTRY (\d+) (-?\d+)$/m',$text,$entries,PREG_SET_ORDER);
foreach($entries as $e){$i=(int)$e[1];$compared++;if((int)$e[2]!==($z['dependency_bundle_entries'][$i]['raw_package_index']??null))$errors[]=['dependency_entry'=>$i];}
if(count($exports)!==count($z['exports'])||count($imports)!==count($z['import_map'])||count($headers)!==count($z['dependency_bundle_headers'])||count($entries)!==count($z['dependency_bundle_entries']))$errors[]='Native row cardinality mismatch';
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'versioned_ue4'=>522,'versioned_ue5'=>1018,'native_field_comparisons'=>$compared,'failures'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;exit($ok?0:2);
