#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR* GPackage=TEXT("G3T073469fOriginalNativeClassPropertyV61V62"); }
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
#include "FFileManagerWindows.h"
FFileManagerWindows FileManager;
#define _HEAPBADBEGIN (-1)
#define _HEAPBADNODE (-2)
#define _HEAPBADPTR (-3)
#define _HEAPEMPTY (-4)
#define _HEAPOK 0
#define _heapchk() _HEAPOK
#include "FMallocAnsi.h"
FMallocAnsi Malloc;
#include "FConfigCacheIni.h"
class G3OriginalArchive:public FArchive {
 public:
 INT NameCount,RefCount,Bytes,Calls;
 G3OriginalArchive(INT v):NameCount(0),RefCount(0),Bytes(0),Calls(0) {
   ArVer=v;ArIsTrans=1;ArIsLoading=0;ArIsSaving=0;
 }
 virtual void Serialize(void* ptr,INT n) {Bytes+=n;Calls++;}
 virtual FArchive& operator<<(FName& name){NameCount++;return *this;}
 virtual FArchive& operator<<(UObject*& ob){RefCount++;return *this;}
};
int main(int argc,char**argv) {
 GIsStarted=1;GIsGuarded=1;TCHAR cmd[64]=TEXT("");
 appInit(TEXT("Unreal"),cmd,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int")); GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 int good=0;
 for(INT k=0;k<(argc>1?0:2);k++) {
  UClass* cls=k?UClass::StaticClass():UObject::StaticClass();
  printf("G3_T065_ORIGINAL_V432_UClass_ORACLE_OWNER=%d PTR=%p DEFAULTS=%d PROPERTIES=%d\n",
         k,cls,cls->Defaults.Num(),cls->GetPropertiesSize());fflush(stdout);
  for(INT v=61;v<=62;v++) {
   G3OriginalArchive ar(v);
   try {
    cls->Serialize(ar);
    printf("G3_T065_ORIGINAL_V432_UClass_VER=%d CLASS=%d NAMES=%d REFS=%d BYTES=%d CALLS=%d CONFIG=%s\n",
      v,k,ar.NameCount,ar.RefCount,ar.Bytes,ar.Calls,*cls->ClassConfigName);fflush(stdout);
    good++;
   }catch(...) {printf("G3_T065_ORIGINAL_V432_UClass_EXCEPTION=%d CLASS=%d\n",v,k);fflush(stdout);}
  }
 }
 UPackage* engine=(UPackage*)UObject::LoadPackage(NULL,TEXT("Engine"),LOAD_NoFail);
 UClass* actor=engine?(UClass*)UObject::StaticFindObject(UClass::StaticClass(),engine,TEXT("Actor")):NULL;
 printf("G3_T065_ORIGINAL_V432_Engine_LOADED_PTR=%p ACTOR_PTR=%p\n",engine,actor);fflush(stdout);
 UProperty* prop=NULL;
 for(TFieldIterator<UProperty> It(actor?actor:UClass::StaticClass());It;++It) {
  prop=*It;break;
 }
 printf("G3_T065_ORIGINAL_V432_UProperty_OWNER_PTR=%p\n",prop);fflush(stdout);
 if(prop) {
  const DWORD initial=prop->PropertyFlags;
  for(INT v=61;v<=62;v++) {
   prop->PropertyFlags=initial|0x00080040;
   DWORD before=prop->PropertyFlags;
   G3OriginalArchive ar(v);
   try {
    prop->Serialize(ar);
    printf("G3_T065_ORIGINAL_V432_UProperty_VER=%d BEFORE=%08X AFTER=%08X NAMES=%d REFS=%d BYTES=%d CALLS=%d\n",
       v,before,prop->PropertyFlags,ar.NameCount,ar.RefCount,ar.Bytes,ar.Calls);fflush(stdout);
    good++;
   }catch(...){printf("G3_T065_ORIGINAL_V432_UProperty_EXCEPTION_VER=%d\n",v);fflush(stdout);}
  }
  prop->PropertyFlags=initial;
 }
 printf("G3_T065_ORIGINAL_V432_6_NATIVE_ORACLES=%d\n",good);fflush(stdout);
 appPreExit();appExit();return good==(argc>1?2:6)?0:10;
}
