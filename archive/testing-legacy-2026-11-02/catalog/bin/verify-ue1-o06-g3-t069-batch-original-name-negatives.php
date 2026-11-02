#!/usr/bin/env php
<?php
/** Fixed G3 T069 PARTIAL: two original compiled FName errors; C98 excluded from this proof. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$temp='C:/Temp/UnrealDB/O06/';
$ev=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$fails=[];
$ck=static function(bool $yes,string $label)use(&$checks,&$fails):void{
    ++$checks; if(!$yes)$fails[]=$label;
};
$cases=[
    ['v432','ut99-v432',$temp.'Native-UCC/System/Core.u',634,43307,326,
      'ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70',
      $temp.'G3-batch-03-04-FName/v432/System/Core.u',
      'cecc18bd831c0177036fe3da7f14104c6f4bffbf36dd79ca07df595f83e4e569',
      '20261028-G3-T069-original-v432-malformed-FName.stdout.log',
      'f2bd406eeb0496cd3626ccf81453620f1b6f44b8d618421d0408613c8e690eee'],
    ['227k','unreal-v227k',$temp.'Native-227k/System/Core.u',633,67623,566,
      '28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c',
      $temp.'G3-batch-03-04-FName/227k/System/Core.u',
      '3f804ca3908a43ba5fd0002fa991f9338f789641190ddd052b0f07c206003baa',
      '20261028-G3-T069-original-227k-malformed-FName.stdout.log',
      'a86520cad668bd75f540ea15b683f2ff928208a06f9e1b42a2c24d13247d0129'],
];
$details=[];
foreach($cases as [$name,$family,$stock,$export,$byte,$count,$stockSha,$mutant,$mutSha,$logname,$logsha]){
    $before=file_get_contents($stock);$after=file_get_contents($mutant);
    $ck(hash('sha256',$before)===$stockSha,$name.' original package SHA');
    $ck(hash('sha256',$after)===$mutSha,$name.' exact one-byte mutant SHA');
    $ck(strlen($before)===strlen($after)&&ord($before[$byte])===0x21&&ord($after[$byte])===0xbf,$name.' only source-authentic name byte changed');
    $restored=$after;$restored[$byte]=$before[$byte];
    $ck($restored===$before,$name.' no other archive bytes modified');
    $log=(string)file_get_contents($ev.$logname);
    $ck(hash('sha256',$log)===$logsha,$name.' real compiled loader log SHA');
    $ck(str_contains($log,'Bad name index -63/'.$count),$name.' original ULinkerLoad bad name index/count');
    $ck(str_contains($log,(string)($byte+1)),$name.' original physical archive Tell from linker history');
    $pkg=new P($mutant);
    $ck(count($pkg->getNames())===$count&&($pkg->getExports()[$export]['objectNameText']??null)==='EndState',
      $name.' original UStruct EndState and real name table');
    $message=null;$loaded=false;
    try{
      $v=N::inspect($mutant,$pkg,$export,T::inspect($mutant,$pkg,$export,$family),$family);
      $loaded=is_array($v);
    }catch(Throwable $err){$message=$err->getMessage();}
    $expected=sprintf('UE1 Bad name index -63/%d at archive cursor %d',$count,$byte+1);
    $ck(!$loaded&&$message===$expected,$name.' source-selected native failure + Tell and no false loaded object');
    $details[]=['profile'=>$name,'native_loader_message'=>$expected,
      'archive_modified_byte'=>$byte,'physical_Tell'=>$byte+1,
      'original_compiled_log_sha256'=>$logsha,'reader_error'=>$message,
      'accepted_for_this_original_profile'=>!$loaded&&$message===$expected];
}
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$fails,
 'fixed_test'=>'T069','status'=>'PARTIAL_ONLY',
 'original_compiled_profiles_verified'=>2,'original_C98_FName_native_oracle'=>'BLOCKED',
 'original_UT99_469f_and_other_source_branches'=>'NOT_IN_THIS_ORACLE',
 'source_sha256'=>hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php'),
 'original_engine_runs_in_this_verifier'=>0,'details'=>$details],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
