<?php
declare(strict_types=1);
/** B011 independent Epic imported package names binary contract. */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$srcPath=rtrim($root,'/\\').'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$zenPath=rtrim($root,'/\\').'/Engine/Source/Runtime/CoreUObject/Private/Serialization/ZenPackageHeader.cpp';
$src=is_file($srcPath)?(string)file_get_contents($srcPath):'';
$zen=is_file($zenPath)?(string)file_get_contents($zenPath):'';
$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$check('b011_pinned_loader_source',hash('sha256',$src)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$check('b011_source_reads_name_batch_first',str_contains($src,'NameEntries = LoadNameBatch(Ar);'));
$check('b011_source_reads_separate_signed_number_per_name',str_contains($src,'Ar << Number;')&&str_contains($src,'NameEntries[Index].ToName(Number)'));
$check('b011_source_allows_empty_imported_names_for_known_packages',
 str_contains($src,'ImportedPackageNames.Num() == 0 || ImportedPackageNames.Num() == ImportedPackageCount'));
$check('b011_source_reader_view_continues_to_header_size',
 str_contains($zen,'PackageSummary->HeaderSize - PackageSummary->ImportedPackageNamesOffset'));
$check('b011_source_no_trailing_consumption_assertion',
 str_contains($zen,'ImportedPackageNamesDataReader << Container;')
 &&!preg_match('/ImportedPackageNamesDataReader\.(?:AtEnd|Tell|TotalSize|GetError)/',$zen));
$check('b011_pinned_zen_header_source',
 hash('sha256',$zen)==='954117a45b9a802d3f289b30f2a0e191a7bb82cdcb107a22fef134a73172995b');
$readerSource=(string)file_get_contents(dirname(__DIR__).
 '/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php');
$builderSource=(string)file_get_contents(dirname(__DIR__).
 '/src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php');
$check('b011_direct_reader_call_and_definition_accounted',
 substr_count($readerSource,'readImportedPackageNames(')===2
 &&str_contains($readerSource,'self::readNameBatch($reader'));
$check('b011_main_optional_builder_preserves_imported_names',
 substr_count($builderSource,"'imported_package_names' => self::indexedRows(")===2);
$check('b011_no_unsourced_trailing_bytes_restriction',
 !str_contains($readerSource,'Zen ImportedPackageNames has unexpected trailing bytes.'));
$batch=static function(array $names):string{
 if(!$names)return pack('V',0);
 $headers='';$raw='';
 foreach($names as $name){$len=strlen($name);
  $headers.=chr(($len>>8)&0x7f).chr($len&0xff);$raw.=$name;
 }
 return pack('V2',count($names),strlen($raw))
  .str_repeat("\0",8+count($names)*8).$headers.$raw;
};
$parse=new ReflectionMethod(Uedb5ZenPackageReader::class,'readImportedPackageNames');
$cases=[
 ['one_number_zero',['/Game/Provider'],[0],'','/Game/Provider'],
 ['one_number_three',['/Game/Provider'],[3],'','/Game/Provider_2'],
 ['two_names',['/Game/A','/Game/B'],[2,0],'','/Game/A_1'],
 ['trailing_header_padding',['/Game/Provider'],[0],"\xCA\xFE\x01\x02",'/Game/Provider'],
 ['zero_names_with_padding',[],[],"\xAA\xBB",null],
];
foreach($cases as [$name,$names,$nums,$trailer,$first]){
 $raw=$batch($names);
 foreach($nums as $num){$raw.=pack('V',$num);}
 $raw.=$trailer;
 try{
  $out=$parse->invoke(null,$raw,0,strlen($raw));
  $pass=count($out)===count($names)
   &&($first===null||($out[0]['display_text']??null)===$first);
  foreach($nums as $i=>$num){
   if(($out[$i]['number']??null)!==$num)$pass=false;
  }
  $check('b011_case_'.$name,$pass);
 }catch(Throwable $e){$check('b011_case_'.$name,false);}
}
$utf16=pack('V2',1,4).str_repeat("\0",16).chr(0x80).chr(2)
 ."A\0B\0".pack('V',2);
try {
 $names=$parse->invoke(null,$utf16,0,strlen($utf16));
 $check('b011_utf16_batch_and_number',count($names)===1
  &&($names[0]['text']??null)==='AB'
  &&($names[0]['number']??null)===2
  &&($names[0]['display_text']??null)==='AB_1');
}catch(Throwable){$check('b011_utf16_batch_and_number',false);}
$missingNumber=$batch(['/Game/Incomplete']);
$reject=false;try{$parse->invoke(null,$missingNumber,0,strlen($missingNumber));}
catch(Throwable){$reject=true;}
$check('b011_truncated_number_rejected',$reject);
$tooMany=$batch(['/Game/A']).pack('V',0);
$reject=false;try{$parse->invoke(null,$tooMany,0,strlen($tooMany)+1);}
catch(Throwable){$reject=true;}
$check('b011_out_of_bounds_span_rejected',$reject);
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed,
 'source_derived'=>true,'no_prod_data_access'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
