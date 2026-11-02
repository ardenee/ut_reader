#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" {HINSTANCE hInstance;}
extern "C" {TCHAR GPackage[]=TEXT("G3T076OriginalProviderSelection");}
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
  UPackage* P=(UPackage*)UObject::LoadPackage(NULL,TEXT("G3T076Dupe"),LOAD_NoFail);
  UClass* C=P?(UClass*)UObject::StaticFindObject(UClass::StaticClass(),P,TEXT("G3T076DupeActor")):NULL;
  UObject* Default=C?C->GetDefaultObject():NULL;
  printf("G3_T076_ORIGINAL_COMPILED_LOAD package=%p class=%p cdo=%p\n",P,C,Default);

  if(Default){
   for(TFieldIterator<UProperty> It(C);It;++It){
    UProperty* Prop=*It;
    if(!appStricmp(*Prop->GetFName(),TEXT("ProviderSentinel"))){
      INT value=*(INT*)((BYTE*)Default+Prop->Offset);
      printf("G3_T076_ORIGINAL_COMPILED_PROVIDER_SELECTION sentinel=%d offset=%d class=%p obj=%p package=%p\\n",value,Prop->Offset,C,Default,P);
      ok=value;
    }
   }
  }
 }catch(...){printf("G3_T076_ORIGINAL_COMPILED_EXCEPTION\n");}
 printf("T019_V432_ORIGINAL_CPP_RESULT=%d\n",ok);
 appPreExit();appExit();return ok?0:7;
}
