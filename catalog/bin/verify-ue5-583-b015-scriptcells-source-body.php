<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
$source=getenv('UNREALDB_EPIC_SOURCE_ROOT').'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$s=is_file($source)?file_get_contents($source):false;
if (!is_string($s)||hash('sha256',$s)!=='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9') {fwrite(STDERR,"Source mismatch\n");exit(2);}
$s=str_replace("\r\n","\n",$s);
$start=strpos($s,'void AddScriptCellPackage(Verse::VPackage* Package)');
$end=strpos($s,"\n\t}\n#endif",$start);
if($start===false||$end===false)exit(2);
$body=substr($s,$start,$end-$start+4);
$cpp=<<<'CPP'
#include <cassert>
#include <cstdint>
#include <iostream>
#include <map>
#include <string>
#include <vector>
using int32=int32_t;
#define LLM_SCOPE_BYTAG(x) ((void)0)
using FUtf8StringView=std::string;
struct FPackageObjectIndex {
 std::string Key;
 static FPackageObjectIndex FromVersePath(const FUtf8StringView& p) {return {p};}
 bool operator<(const FPackageObjectIndex& rhs)const{return Key<rhs.Key;}
};
namespace Verse {
 struct VCell{int Value;};
 struct VUniqueString{std::string Text; FUtf8StringView AsStringView()const{return Text;}};
 struct VValue{VCell* Ptr;VCell* ExtractCell()const{return Ptr;}};
 struct VPackage{
  std::vector<std::pair<VUniqueString,VValue>> Definitions;
  int32 NumDefinitions()const{return static_cast<int32>(Definitions.size());}
  VUniqueString GetDefinitionName(int32 i)const{return Definitions.at(i).first;}
  VValue GetDefinition(int32 i)const{return Definitions.at(i).second;}
 };
}
struct FCellMap{
 std::map<FPackageObjectIndex,Verse::VCell*> M;
 void Add(FPackageObjectIndex idx,Verse::VCell* cell){M[idx]=cell;}
};
struct Store {
 FCellMap ScriptCells;
__EPIC_FUNCTION__
};
int main(){
 Store store;
 Verse::VCell a{11},b{22},c{33};
 Verse::VPackage empty;
 store.AddScriptCellPackage(&empty);
 assert(store.ScriptCells.M.empty());
 Verse::VPackage p; p.Definitions.push_back({{ "/Verse.org/Verse/a" }, {&a}}); p.Definitions.push_back({{ "/Verse.org/Verse/b" }, {&b}});
 store.AddScriptCellPackage(&p);
 assert(store.ScriptCells.M.size()==2);
 assert(store.ScriptCells.M.at({"/Verse.org/Verse/a"})==&a);
 assert(store.ScriptCells.M.at({"/Verse.org/Verse/b"})==&b);
 Verse::VPackage p2; p2.Definitions.push_back({{ "/Verse.org/Verse/a" }, {&c}});
 store.AddScriptCellPackage(&p2);
 assert(store.ScriptCells.M.size()==2);
 assert(store.ScriptCells.M.at({"/Verse.org/Verse/a"})==&c);
 std::cout<<"EPIC_B015_SCRIPT_CELLS_SOURCE_BODY|empty=1|two_distinct=1|duplicate_overwrite=1|count=2\n";
}
CPP;
$cpp=str_replace('__EPIC_FUNCTION__',$body,$cpp);
$tmp=sys_get_temp_dir().'/uedb-b015-cell-native-'.getmypid();
@mkdir($tmp);
file_put_contents($tmp.'/main.cpp',$cpp);
$vcvars='C:/Program Files/Microsoft Visual Studio/18/Community/VC/Auxiliary/Build/vcvars64.bat';
$batch='@echo off' . "\r\n" . 'call "'.$vcvars.'" >nul' . "\r\n" . 'cl.exe /nologo /EHsc /std:c++20 "'.$tmp.'/main.cpp" /Fe:"'.$tmp.'/run.exe"' . "\r\n";
file_put_contents($tmp.'/compile.cmd',$batch);
exec('cmd.exe /d /c '.escapeshellarg($tmp.'/compile.cmd').' 2>&1',$output,$rc);
if($rc!==0){echo json_encode(['compile_exit'=>$rc,'compiler_output'=>$output],JSON_PRETTY_PRINT),PHP_EOL;exit(1);}exec(escapeshellarg($tmp.'/run.exe').' 2>&1',$run,$exit);
$ok=$exit===0&&str_contains(implode("\n",$run),'EPIC_B015_SCRIPT_CELLS_SOURCE_BODY|empty=1|two_distinct=1|duplicate_overwrite=1|count=2');
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','disposition'=>'partial','source_identical_epic_function_executed'=>true,'real_verse_vm_executed'=>false,'native_result'=>$run],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
@unlink($tmp.'/compile.cmd');@unlink($tmp.'/run.exe');@unlink($tmp.'/main.cpp');@rmdir($tmp);
exit($ok?0:1);
