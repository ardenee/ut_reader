// Native doubles exist solely to execute byte-for-byte Epic source function bodies.
// NOT a live Unreal Engine UObject registry or runtime test.
#include <algorithm>
#include <cstdint>
#include <cstdio>
#include <map>
#include <stdexcept>
#include <string>
#include <string_view>
#include <vector>
using int32=int;using uint64=uint64_t;using TCHAR=char;
#define TEXT(x) x
#define TRACE_CPUPROFILER_EVENT_SCOPE(x) ((void)0)
#define LLM_SCOPE_BYTAG(x) ((void)0)
#define check(x) do{if(!(x))throw std::runtime_error("source assertion failed");}while(false)
#define ensure(x) (x)
#define UE_CLOGF(condition, cat, level, fmt, ...) ((void)0)
#define RF_Public 1
#define PKG_CompiledIn 1
#define LogStreaming 0
#define Display 0
#define Warning 1
int MissingWarnings=0;
void logEvent(const char* s){if(std::string(s).find("missing a NotifyRegistrationEvent")!=std::string::npos)++MissingWarnings;}
#define UE_LOGF(cat, level, fmt, ...) logEvent(fmt)
enum class ESearchCase {CaseSensitive};
struct FStringView{
 std::string s;
 FStringView(const char* p,int n):s(p,(size_t)n){}
 FStringView(std::string_view p):s(p){}
 operator std::string_view()const{return s;}
 bool StartsWith(const char* x,ESearchCase)const{return s.rfind(x,0)==0;}
 bool Contains(const char* x,ESearchCase)const{return s.find(x)!=std::string::npos;}
};
template<int N>struct TStringBuilder{
 std::string s;void Reset(){s.clear();}
 operator std::string_view()const{return s;}
};
struct FName{static constexpr int StringBufferSize=1024;std::string s;
 int ToString(char* out)const{std::copy(s.begin(),s.end(),out);return (int)s.size();}
 std::string ToString()const{return s;}
};
struct UObject{
 std::string path;UObject* outer=nullptr;bool publicFlag=false;bool uht=false;bool unreachable=false;
 UObject(std::string p,UObject* o=nullptr,bool pub=false,bool uh=false):
  path(std::move(p)),outer(o),publicFlag(pub),uht(uh){}
 virtual ~UObject()=default;
 bool HasAnyFlags(int x)const{return x==RF_Public&&publicFlag;}
 UObject* GetOutermost(){UObject* p=this;while(p->outer)p=p->outer;return p;}
 FName GetFName()const{auto n=path.find_last_of(".:");return {path.substr(n==std::string::npos?0:n+1)};}
 bool IsUnreachable()const{return unreachable;}
 bool IsValidLowLevel()const{return true;}
 template<int N>void GetPathName(void*,TStringBuilder<N>& b)const{b.s=path;}
 std::string GetFullName()const{return path;}
};
struct UPackage:UObject{
 bool compiled;UPackage(std::string name,bool flag):UObject(std::move(name)),compiled(flag){}
 static int StaticClass(){return 1;}
 bool HasAnyPackageFlags(int x)const{return x==PKG_CompiledIn&&compiled;}
 FName GetFName()const{return {path};}
};
std::vector<UPackage*> AllPackages;std::vector<UObject*> AllObjects;
template<class T>T* CastChecked(UObject* x){return static_cast<T*>(x);}
template<class F>void ForEachObjectOfClass(int,F f,bool){for(auto* p:AllPackages)f(p);}
enum class EGetObjectsFlags{IncludeNestedObjects};
template<class T>struct TArray:std::vector<T>{
 void Empty(int=0){this->clear();}
 void Add(T v){this->push_back(v);}
 void Reset(){this->clear();}
};
template<class F>void ForEachObjectWithOuter(UObject* parent,F callback,EGetObjectsFlags){
 for(auto* obj:AllObjects)for(auto* p=obj->outer;p;p=p->outer)
  if(p==parent){callback(obj);break;}
}
struct FPathViews{
 template<int N>static void Append(TStringBuilder<N>& b,std::string_view segment){
  if(!b.s.empty()&&!segment.empty()&&segment.front()!='/')b.s+='.';
  b.s.append(segment);
 }
};
void GetObjectsWithOuter(UPackage* pkg,TArray<UObject*>& output,EGetObjectsFlags){
 for(auto* obj:AllObjects)for(auto* p=obj->outer;p;p=p->outer)
  if(p==pkg){output.Add(obj);break;}
}
namespace Verse{namespace VerseVM{
 bool IsUHTGeneratedVerseVNIObject(const UObject* o){return o->uht;}
}}
struct FPackageObjectIndex{
 std::string path;bool isPackage=false;int importedIndex=0;
 template<class T>static FPackageObjectIndex FromScriptPath(const T& text){
  return {std::string(std::string_view(text))};
 }
 bool IsScriptImport()const{return !path.empty()&&!isPackage;}
 bool IsPackageImport()const{return isPackage;}
 bool IsNull()const{return path.empty()&&!isPackage;}
 struct Ref{int i;int GetImportedPackageIndex()const{return i;}};
 Ref ToPackageImportRef()const{return {importedIndex};}
 bool operator<(const FPackageObjectIndex& rhs)const{return path<rhs.path;}
 uint64 Value()const{return 0;}
};
template<class K,class V>struct TPair{K Key;V Value;};
struct ScriptMap{
 std::map<FPackageObjectIndex,UObject*> m;
 void Add(FPackageObjectIndex k,UObject* value){m[k]=value;}
 bool Contains(FPackageObjectIndex k)const{return m.count(k)!=0;}
 UObject* FindRef(FPackageObjectIndex k)const{auto it=m.find(k);return it==m.end()?nullptr:it->second;}
 template<class F>int ParallelRemoveIf(F f){int count=0;
  for(auto it=m.begin();it!=m.end();){
   if(f(TPair<FPackageObjectIndex,UObject*>{it->first,it->second})){it=m.erase(it);++count;}
   else ++it;
  }
  return count;
 }
 void Shrink(){}
};
struct FRuntimeScriptPackages{TArray<UPackage*> Script,VerseVNI;};
__EPIC_FIND_PACKAGES__
struct FMockDebug{
 bool trimmed=false;std::map<std::string,std::string> paths;
 std::string GetPathName(FPackageObjectIndex k)const{
  auto it=paths.find(k.path);return it==paths.end()?"":it->second;
 }
 void TrimDebugData(const ScriptMap&){trimmed=true;}
};
struct FGlobalImportStore{
 ScriptMap ScriptObjects;FMockDebug CookedScriptObjectsDebug;
 __EPIC_FIND_SCRIPT_IMPORT__
 __EPIC_ADD_SCRIPT_OBJECT__
 __EPIC_REMOVE_UNREACHABLE__
 void FindAllScriptObjects(bool bVerifyOnly);
 void RegistrationComplete();
};
__EPIC_FIND_OBJECTS__
__EPIC_REGISTRATION_COMPLETE__
using FString=std::string;
struct SourceIds{bool IsValidIndex(int i)const{return i>=0&&i<1;}};
struct FAsyncPackageHeaderData{SourceIds ImportedPackageIds;};
struct FPackageImportStore{
 FGlobalImportStore& GlobalImportStore;
 explicit FPackageImportStore(FGlobalImportStore& s):GlobalImportStore(s){}
 UObject* GetImportObject(const FAsyncPackageHeaderData&, FPackageObjectIndex idx){
  return idx.IsScriptImport()?GlobalImportStore.FindScriptImportObject(idx):nullptr;
 }
 __EPIC_GET_IMPORT_NAME__
};
void require(bool ok,const char* msg){if(!ok)throw std::runtime_error(msg);}
int main(){
 try{
  UPackage script("/Script/Demo",true),vni("/Project/_Verse/VNI/Package",true),
   other("/Game/Other",true),notCompiled("/Script/Other",false),
   wrongCase("/script/Lower",true);
  UObject root("/Script/Demo.Thing",&script,true),
   nested("/Script/Demo.Thing:Nested",&root,true),
   hidden("/Script/Demo.Thing:Hidden",&root,false),
   uht("/Project/_Verse/VNI/Package.UHT",&vni,true,true),
   compiler("/Project/_Verse/VNI/Package.Compiler",&vni,true,false);
  AllPackages={&script,&vni,&other,&notCompiled,&wrongCase};
  AllObjects={&root,&nested,&hidden,&uht,&compiler};
  FGlobalImportStore initial;
  initial.AddScriptObject(FStringView("/Script/Demo",12),FStringView("Thing",5),&root);
  auto key=FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Thing"));
  auto childKey=FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Thing:Nested"));
  auto privateKey=FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Thing:Hidden"));
  require(initial.FindScriptImportObject(key)==&root,"finished script object findref not live");
  require(initial.ScriptObjects.Contains(childKey),"public nested initial registration missed");
  require(!initial.ScriptObjects.Contains(privateKey),"private nested initial registration incorrectly included");
  FPackageImportStore names(initial);
  FAsyncPackageHeaderData header;
  auto missing=FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Missing"));
  initial.CookedScriptObjectsDebug.paths[key.path]="wrong_cooked_name";
  initial.CookedScriptObjectsDebug.paths[missing.path]="/Script/Demo.Missing";
  require(names.GetImportName(header,key)=="Thing","GetImportName should prefer live UObject FName");
#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING
  require(names.GetImportName(header,missing)=="/Script/Demo.Missing",
    "GetImportName should use cooked fallback only for absent live entry");
#else
  require(names.GetImportName(header,missing).empty(),
    "GetImportName debug-disabled fallback should be empty");
#endif
  root.unreachable=true;
  initial.RemoveUnreachableScriptObjects();
  require(!initial.ScriptObjects.Contains(key),"unreachable live object should be removed");
  require(initial.ScriptObjects.Contains(childKey),"reachable child should remain registered");
  root.unreachable=false;
  FRuntimeScriptPackages selected;
  FindAllRuntimeScriptPackages(selected);
  require(selected.Script.size()==1&&selected.VerseVNI.size()==1,
    "compiled-in /Script vs Verse-VNI filtering incorrect");
  FGlobalImportStore registry;
  registry.RegistrationComplete();
#if WITH_EDITOR
  require(registry.ScriptObjects.m.size()==5,"editor should add 2 packages and 3 public UObjects");
  require(registry.ScriptObjects.Contains(FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo"))),
    "editor package key absent");
  require(registry.ScriptObjects.Contains(FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Thing:Nested"))),
    "editor nested public object absent");
  require(registry.ScriptObjects.Contains(FPackageObjectIndex::FromScriptPath(std::string("/Project/_Verse/VNI/Package.UHT"))),
    "editor UHT VNI object absent");
  require(!registry.ScriptObjects.Contains(FPackageObjectIndex::FromScriptPath(std::string("/Script/Demo.Thing:Hidden"))),
    "private UObject included");
  require(!registry.ScriptObjects.Contains(FPackageObjectIndex::FromScriptPath(std::string("/Project/_Verse/VNI/Package.Compiler"))),
    "compiler generated Verse VNI object included");
#elif DO_CHECK
  require(registry.ScriptObjects.m.empty(),"non-editor verify-only must never insert");
  require(MissingWarnings==3,"non-editor verify-only missing warnings wrong");
#else
  require(registry.ScriptObjects.m.empty(),"shipping inserted forbidden registration");
  require(MissingWarnings==0,"shipping emitted forbidden check-only warning");
#endif
#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING
  require(registry.CookedScriptObjectsDebug.trimmed,"compiled diagnostic trim was not called");
#else
  require(!registry.CookedScriptObjectsDebug.trimmed,"disabled diagnostic unexpectedly trimmed");
#endif
  std::printf("PASS editor=%d check=%d debug=%d entries=%zu warnings=%d\n",
    WITH_EDITOR,DO_CHECK,ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING,
    registry.ScriptObjects.m.size(),MissingWarnings);
  return 0;
 }catch(const std::exception& e){
  std::fprintf(stderr,"FAIL editor=%d check=%d debug=%d: %s\n",
    WITH_EDITOR,DO_CHECK,ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING,e.what());
  return 1;
 }
}
