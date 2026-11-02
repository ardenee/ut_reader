<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptImportPathHasher as Hasher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5CityHash64 as CityHash;

$checks=[];$failures=[];$detail=[];
$check=static function(string $key,bool $pass)use(&$checks,&$failures):void{
 $checks[$key]=$pass;if(!$pass)$failures[]=$key;
};
$root=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$epic=$root.'/Engine/Source/Runtime/';
$cpp=$epic.'CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$city=$epic.'Core/Private/Hash/CityHash.cpp';
$cityHeader=$epic.'Core/Public/Hash/CityHash.h';
$char=$epic.'Core/Public/Misc/Char.h';
$pins=[
 'script_cpp'=>[$cpp,'43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9'],
 'city_cpp'=>[$city,'035965ffa9875cc2ad93694a44a45cd8bdc042101e4b84d355b22d089f070d62'],
 'city_header'=>[$cityHeader,'591ef28603dea447d857d5eccea607386f0b4a9b9df283160a5d375cdc6648be'],
 'tchar_header'=>[$char,'b0a3b64758caddf5151a0396fdb042476bba4d9c9d1eaaeda94318b4b069a0e1']
];
$source=[];
foreach($pins as $name=>[$file,$hash]){
 $source[$name]=is_file($file)?(string)file_get_contents($file):'';
 $check('hash_epic_'.$name.'_pinned',hash('sha256',$source[$name])===$hash);
}
$methodStart=strpos($source['script_cpp'],'uint64 FPackageObjectIndex::GenerateImportHashFromObjectPath(');
$methodEnd=strpos($source['script_cpp'],'void FindAllRuntimeScriptPackages(',$methodStart===false?0:$methodStart);
$methods=$methodEnd!==false&&$methodStart!==false?substr($source['script_cpp'],$methodStart,$methodEnd-$methodStart):'';
$check('hash_epic_object_normalizes_dots_colons_lowercase_tchar',
 str_contains($methods,"ObjectPath[I] == TEXT('.') || ObjectPath[I] == TEXT(':')")
 &&str_contains($methods,"FullImportPath[I] = TEXT('/');")
 &&str_contains($methods,'TChar<TCHAR>::ToLower(ObjectPath[I])')
 &&str_contains($methods,'Len * sizeof(TCHAR)'));
$check('hash_epic_verse_bytes_distinct_from_script_unicode',
 str_contains($methods,'GenerateImportHashFromVersePath(FUtf8StringView VersePath)')
 &&str_contains($methods,'CityHash64(reinterpret_cast<const char*>(VersePath.GetData()), VersePath.Len())'));
$check('hash_epic_mask_clears_upper_two_bits_both_branches',
 substr_count($methods,'Hash &= ~(3ull << 62ull);')===2);
$check('hash_epic_to_lower_only_ascii_not_unicode_fold',
 str_contains($source['tchar_header'],'Only converts ASCII characters')
 &&str_contains($source['tchar_header'],"uint32(Char) - 'A' < 26u"));
$check('hash_epic_city_is_64bit_not_other_hash',
 str_contains($source['city_cpp'],'uint64 CityHash64(const char *s, uint32 len)')
 &&str_contains($source['city_header'],'CityHash128to64('));
$compiler='';
foreach (array_filter([
    getenv('UNREALDB_NATIVE_GXX') ?: null,
    'C:/msys64/ucrt64/bin/g++.exe',
    'D:/msys64/ucrt64/bin/g++.exe',
    'Z:/msys64/ucrt64/bin/g++.exe'
]) as $candidate) {
    if (is_file($candidate)) { $compiler=$candidate; break; }
}
$check('hash_native_cpp_compiler_available',is_file($compiler));
$temp=sys_get_temp_dir().'/uedb5-b013-cityhash-'.bin2hex(random_bytes(5));
$created=[];
$nativeOutput='';
$compileMessage='';
try {
 if (!mkdir($temp.'/Hash',0777,true)||!mkdir($temp.'/HAL',0777,true)||!mkdir($temp.'/Misc',0777,true)){
  throw new RuntimeException('Cannot create isolated native test directories.');
 }
 $write=static function(string $path,string $content)use(&$created):void{
  if(file_put_contents($path,$content)!==strlen($content))throw new RuntimeException('Unable to stage native oracle.');
  $created[]=$path;
 };
 $write($temp.'/PinnedCityHash.cpp',$source['city_cpp']);
 $write($temp.'/Hash/CityHash.h',<<<'CPP'
#include <cstdint>
using uint64=uint64_t; using uint32=uint32_t; using uint8=uint8_t;
struct Uint128_64 {uint64 lo,hi;Uint128_64(uint64 l,uint64 h):lo(l),hi(h){}};
inline uint64 CityHash128to64(const Uint128_64& x){
 const uint64 m=0x9ddfea08eb382d69ULL;
 uint64 a=(x.lo^x.hi)*m;a^=a>>47;
 uint64 b=(x.hi^a)*m;b^=b>>47;b*=m;return b;
}
uint64 CityHash64(const char*,uint32);
uint64 CityHash64WithSeed(const char*,uint32,uint64);
uint64 CityHash64WithSeeds(const char*,uint32,uint64,uint64);
uint32 CityHash32(const char*,uint32);
CPP);
 $write($temp.'/HAL/UnrealMemory.h',<<<'CPP'
#pragma once
#include <cstring>
struct FPlatformMemory {
 static void* Memcpy(void* d,const void* s,size_t n){return std::memcpy(d,s,n);}
};
CPP);
 $write($temp.'/Misc/ByteSwap.h',<<<'CPP'
#pragma once
#define BYTESWAP_ORDER32(x) __builtin_bswap32(x)
#define BYTESWAP_ORDER64(x) __builtin_bswap64(x)
CPP);
 $write($temp.'/oracle.cpp',<<<'CPP'
#include <cstdio>
#include <iostream>
#include <string>
#include <vector>
#include <windows.h>
#include "Hash/CityHash.h"
static void hash(const std::string& utf8){
 static_assert(sizeof(wchar_t)==2,"Epic Win64 TCHAR must be UTF16");
 const int count=MultiByteToWideChar(CP_UTF8,MB_ERR_INVALID_CHARS,
  utf8.data(),(int)utf8.size(),nullptr,0);
 if(count==0&&!utf8.empty()){std::cout<<"INVALID_UTF8"<<std::endl;return;}
 std::vector<wchar_t> tchars(count);
 if(count) MultiByteToWideChar(CP_UTF8,MB_ERR_INVALID_CHARS,
   utf8.data(),(int)utf8.size(),tchars.data(),count);
 for(wchar_t& c:tchars){
  if(c==L'.'||c==L':')c=L'/';
  else if(c>=L'A'&&c<=L'Z')c=(wchar_t)(c+32);
 }
 uint64 script=CityHash64(reinterpret_cast<const char*>(tchars.data()),
      (uint32)(count*sizeof(wchar_t)))&0x3FFFFFFFFFFFFFFFULL;
 uint64 verse=CityHash64(utf8.data(),(uint32)utf8.size())&0x3FFFFFFFFFFFFFFFULL;
 std::printf("%016llX %016llX\n",
     (unsigned long long)script,(unsigned long long)verse);
}
int main(){std::string s;while(std::getline(std::cin,s))hash(s);}
CPP);
 $envPath=(string)getenv('PATH');
 $bin=str_replace('\\','/',dirname($compiler));
 putenv('PATH='.$bin.';'.preg_replace('~/ucrt64/bin$~','/usr/bin',$bin).';'.$envPath);
 $command=[$compiler,'-std=c++17','-O2','-I',$temp,$temp.'/oracle.cpp',
  $temp.'/PinnedCityHash.cpp','-o',$temp.'/oracle.exe'];
 $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('Cannot start GNU C++ native oracle compiler.');
 fclose($pipes[0]);$compilerStdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $compileMessage=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $compiled=proc_close($proc)===0 &&is_file($temp.'/oracle.exe');
 $check('hash_native_oracle_compiled_from_exact_epic_cityhash_cpp',$compiled);
 if(!$compiled)throw new RuntimeException('Native CityHash compile failed: '.substr($compileMessage,0,250));
 $vectors=[
  '', 'Hello', '/Script/CoreUObject.Object', '/Script/CoreUObject.ObjectRedirector',
  '/Script/Engine.Actor','/Script/Engine.actor','/SCRIPT/ENGINE:ACTOR',
  '/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes',
  'A.B:C','a/b/c','a:B.c','SimpleName','MadeInUE5.8.3',
  'Café','CAFÉ','Ångström:GO','Συνάρτηση','İSTANBUL','Straße','ÄÖÜ',
  '😀','😀:Thing.WITH.UPPER','/Script/École:Nested','猫.犬','Zürich:Résumé'
 ];
 foreach([1,2,3,4,7,8,15,16,17,31,32,33,63,64,65,66,96,127,128,129,191,192,193,256,321] as $length){
  $vectors[]=substr(str_repeat('Ab.cD:Ef/Gh.iJ:K/',22),0,$length);
 }
 foreach(range(0,47) as $n){
  $len=($n*17+7)%271;
  $vectors[]=substr(str_repeat('K1.l2:M3/N4.o5:P6/',18),$n%13,$len);
 }
 $proc=proc_open([$temp.'/oracle.exe'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('Cannot run compiled native oracle.');
 fwrite($pipes[0],implode("\n",$vectors)."\n");fclose($pipes[0]);
 $nativeOutput=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $nativeError=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $exit=proc_close($proc);
 $lines=explode("\n",trim($nativeOutput));
 $check('hash_native_oracle_exact_count_and_zero_exit',$exit===0&&count($lines)===count($vectors));
 $mismatches=[];
 foreach($vectors as $i=>$str){
  if(!isset($lines[$i])||!preg_match('/^([0-3][A-F0-9]{15}) ([0-3][A-F0-9]{15})$/',trim($lines[$i]),$match)){
   $mismatches[]=['case'=>$i,'reason'=>'oracle_result_invalid'];continue;
  }
  $script=Hasher::fromObjectPath($str);
  $verse=Hasher::fromVersePath($str);
  if($script!==$match[1]||$verse!==$match[2]){
   $mismatches[]=['case'=>$i,'bytes'=>bin2hex($str),'script'=>$script,'native_script'=>$match[1],
    'verse'=>$verse,'native_verse'=>$match[2]];
  }
 }
 $detail['vector_count']=count($vectors);
 $detail['mismatches']=array_slice($mismatches,0,7);
 $check('hash_native_ascii_unicode_all_cityhash_lengths_match',$mismatches===[]);
 $check('hash_real_getrecipes_native_and_cooked_identity',
  Hasher::fromObjectPath('/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes')
    ==='167E29465232A0A9');
 $check('hash_original_objectredirector_native_and_resolver_constant',
  Hasher::fromObjectPath('/Script/CoreUObject.ObjectRedirector')
    ==='1D39669A89BAECB6');
 $check('hash_script_normalizes_but_verse_does_not',
  Hasher::fromObjectPath('A.B:C')===Hasher::fromObjectPath('a/b/c')
  &&Hasher::fromVersePath('A.B:C')!==Hasher::fromVersePath('a/b/c'));
 $check('hash_non_ascii_accent_casing_preserved_as_source_tchar',
  Hasher::fromObjectPath('CAFÉ')!==Hasher::fromObjectPath('café')
  &&Hasher::fromObjectPath('Café')===Hasher::fromObjectPath('café'));
 $reject=false;
 try{Hasher::fromObjectPath("\xFF");}catch(InvalidArgumentException){$reject=true;}
 $check('hash_invalid_utf8_tchar_conversion_fails_closed',$reject);
 $check('hash_invalid_utf8_verse_raw_bytes_hashed_without_assumptions',
  preg_match('/^[0-3][A-F0-9]{15}$/',Hasher::fromVersePath("\xFF"))===1);
} catch(Throwable $e) {
 $failures[]='hash_native_oracle_exception';
 $detail['exception']=$e->getMessage();
} finally {
 foreach($created as $path)@unlink($path);
 @unlink($temp.'/oracle.exe');
 @rmdir($temp.'/Hash');@rmdir($temp.'/HAL');@rmdir($temp.'/Misc');@rmdir($temp);
}
echo json_encode(['ok'=>$failures===[],'original_operation'=>'UE5-583-SCRIPT-HASH-001',
 'checks'=>$checks,'failures'=>$failures,'details'=>$detail,
 'native_cityhash_source_copied_only_to_disposable_test_directory'=>true,
 'epic_live_loader_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
