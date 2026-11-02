#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" {HINSTANCE hInstance;}
extern "C" {TCHAR GPackage[]=TEXT("G3T076RealSuperClassLoader");}
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
#define i I
#include "FFileManagerWindows.h"
#undef i
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
int main(){
 GIsStarted=1;GIsGuarded=1;TCHAR cmd[64]=TEXT("");
 appInit(TEXT("Unreal"),cmd,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int")); GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 int ok=0;
 try {
  UPackage* P=(UPackage*)UObject::LoadPackage(NULL,TEXT("G3T076Consumer"),LOAD_NoFail);
  UClass* C=P?(UClass*)UObject::StaticFindObject(UClass::StaticClass(),P,TEXT("G3T076ConsumerActor")):NULL;
  UObject* Default=C?C->GetDefaultObject():NULL;
  printf("G3_T076_NATIVE_CONSUMER_LOAD package=%p class=%p cdo=%p\n",P,C,Default);


  if(Default){
   UClass* Super=C->GetSuperClass();
   printf("G3_T076_NATIVE_CONSUMER_SUPER class=%p super=%p\\n",C,Super);
   if(Super){
    UObject* providerCDO=Super->GetDefaultObject();
    for(TFieldIterator<UProperty> Q(Super);Q;++Q){
      UProperty* F=*Q;
      if(!appStricmp(*F->GetFName(),TEXT("ProviderSentinel"))){
        INT got=*(INT*)((BYTE*)providerCDO+F->Offset);
        printf("G3_T076_NATIVE_TRUE_IMPORTED_PROVIDER superclass=%p cdo=%p sentinel=%d offset=%d\\n",
          Super,providerCDO,got,F->Offset);
        ok=got;
      }
    }
   }
  }
 }catch(...){printf("G3_T076_NATIVE_CONSUMER_EXCEPTION\n");}
 printf("G3_T076_NATIVE_SUPERCLASS_RESULT=%d\n",ok);
 appPreExit();appExit();return ok?0:7;
}
