<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);

/**
 * Compile EXACT Epic function bodies using local C++ UObject/package doubles.
 * The executable is a *source-branch substitute*, NOT Unreal Engine runtime.
 */
$checks=[];$errors=[];$results=[];
$check=static function(string $name,bool $ok)use(&$checks,&$errors):void{
 $checks[$name]=$ok;if(!$ok)$errors[]=$name;
};
$root=dirname(__DIR__,2);
$epicRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$srcFile=$epicRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$src=is_file($srcFile)?str_replace("\r\n","\n",(string)file_get_contents($srcFile)):'';
$check('b0151_native_pinned_epic_async_source',
 hash('sha256',is_file($srcFile)?file_get_contents($srcFile):'')==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$extract=static function(string $start,string $end,int $closeChars=2)use($src):string{
 $at=strpos($src,$start);
 if($at===false)throw new RuntimeException('Missing source start: '.$start);
 $to=strpos($src,$end,$at+strlen($start));
 if($to===false)throw new RuntimeException('Missing source end: '.$end);
 return substr($src,$at,$to-$at+$closeChars);
};
$templateFile=$root.'/catalog/tests/fixtures/ue5_script_runtime_native_harness.cpp.tpl';
$template=is_file($templateFile)?(string)file_get_contents($templateFile):'';
$check('b0151_native_template_sha256_pinned',
 hash('sha256',$template)==='81d49fdd1122d85c547da94693b9dcceb5404fdb6d8abe43491266657e3f69c4');
$check('b0151_native_test_template_available',str_contains($template,'__EPIC_FIND_PACKAGES__')
 &&str_contains($template,'__EPIC_FIND_OBJECTS__')
 &&str_contains($template,'__EPIC_REGISTRATION_COMPLETE__'));
$compiler='C:/msys64/ucrt64/bin/g++.exe';
$check('b0151_native_cpp_compiler_available',is_file($compiler));
$temp=sys_get_temp_dir().'/uedb5-b0151-epic-native-'.bin2hex(random_bytes(5));
$files=[];$error=null;
try{
 $blocks=[
 '__EPIC_FIND_PACKAGES__'=>$extract(
  'void FindAllRuntimeScriptPackages(FRuntimeScriptPackages& OutPackages)',
  "\n}\n\n#ifndef ALT2_VERIFY_LINKERLOAD_MATCHES_IMPORTSTORE"
 ),
 '__EPIC_FIND_OBJECTS__'=>$extract(
  'void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)',
  "\n}\n\n#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING"
 ),
 '__EPIC_REGISTRATION_COMPLETE__'=>$extract(
  'void FGlobalImportStore::RegistrationComplete()',
  "\n}\n\nvoid FAsyncPackage2::ImportPackagesRecursiveInner"
 ),
 '__EPIC_FIND_SCRIPT_IMPORT__'=>$extract(
  'inline UObject* FindScriptImportObject(FPackageObjectIndex GlobalIndex) const',
  "\n\t}\n\n\tVerse::VCell* FindPublicExportCell(",3
 ),
 '__EPIC_ADD_SCRIPT_OBJECT__'=>$extract(
  'void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)',
  "\n\t}\n\n\tvoid RemoveUnreachableScriptObjects()",3
 ),
 '__EPIC_REMOVE_UNREACHABLE__'=>$extract(
  'void RemoveUnreachableScriptObjects()',
  "\n\t}\n\n\tvoid Shrink()",3
 ),
 '__EPIC_GET_IMPORT_NAME__'=>$extract(
  'FString GetImportName(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)',
  "\n\t}\n\n\tvoid GetUnresolvedCDOs(",3
 )
 ];
 foreach($blocks as $label=>$snippet){
  $check('b0151_native_exact_function_'.$label.'_extracted',
   str_contains($snippet,'{')&&str_ends_with(trim($snippet),'}'));
  $template=str_replace($label,$snippet,$template);
 }
 if(!mkdir($temp,0700,true))throw new RuntimeException('Cannot create isolated build directory');
 $srcPath=$temp.'/test.cpp';
 file_put_contents($srcPath,$template);$files[]=$srcPath;
 $originalPath=getenv('PATH');
 putenv('PATH=C:/msys64/ucrt64/bin;C:/msys64/usr/bin;'.$originalPath);
 foreach([
  'editor'=>[1,1,1,5,0],
  'check_only'=>[0,1,1,0,3],
  'shipping'=>[0,0,0,0,0],
  'check_no_debug'=>[0,1,0,0,3],
 ] as $label=>[$editor,$doCheck,$debug,$entries,$warnings]){
  $out=$temp.'/test_'.$label.'.exe';$files[]=$out;
  $command=[$compiler,'-std=c++17','-O2',
   '-DWITH_EDITOR='.$editor,'-DDO_CHECK='.$doCheck,
   '-DALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING='.$debug,
   $srcPath,'-o',$out];
  $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($proc))throw new RuntimeException('Cannot execute native compiler');
  fclose($pipes[0]);$compilerOutput=stream_get_contents($pipes[1]);fclose($pipes[1]);
  $compilerErrors=stream_get_contents($pipes[2]);fclose($pipes[2]);
  $exit=proc_close($proc);
  $compiled=$exit===0&&is_file($out);
  $check('b0151_native_'.$label.'_compiled_exact_source',$compiled);
  if(!$compiled){
   $results[$label]=['compiler_excerpt'=>substr($compilerErrors,0,900),'exit'=>$exit];
   continue;
  }
  $proc=proc_open([$out],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($proc))throw new RuntimeException('Cannot run compiled Epic function fixture');
  fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
  $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
  $exit=proc_close($proc);
  $expected="PASS editor=$editor check=$doCheck debug=$debug entries=$entries warnings=$warnings";
  $pass=$exit===0&&str_contains($stdout,$expected);
  $check('b0151_native_'.$label.'_epic_branches_executed',$pass);
  $results[$label]=['ok'=>$pass,'output'=>trim($stdout),
   'stderr_excerpt'=>substr($stderr,0,750),'exit'=>$exit];
 }
} catch(Throwable $e) {
 $error=$e->getMessage();$errors[]='b0151_native_exception';
} finally {
 foreach($files as $file)@unlink($file);
 @rmdir($temp);
}
echo json_encode([
 'ok'=>$errors===[],'operation'=>'UE5-583-B015-1',
 'native_source_function_bodies_unmodified'=>true,
 'actual_unreal_engine_executed'=>false,'runtime_evidence_accepted'=>false,
 'checks'=>$checks,'failures'=>$errors,'configurations'=>$results,
 'exception'=>$error
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:1);
