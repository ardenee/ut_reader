#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" {HINSTANCE hInstance;}
extern "C" {TCHAR GPackage[]=TEXT("G3BulkT019Original432");}
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
  UPackage* P=(UPackage*)UObject::LoadPackage(NULL,TEXT("G3BulkConfigV432.u"),LOAD_NoFail);
  UClass* C=P?(UClass*)UObject::StaticFindObject(UClass::StaticClass(),P,TEXT("G3BulkConfigV432Actor")):NULL;
  UObject* Default=C?C->GetDefaultObject():NULL;
  printf("T019_V432_ORIGINAL_CPP_LOAD package=%p class=%p cdo=%p\n",P,C,Default);
  if(Default){
   for(TFieldIterator<UProperty> It(C);It;++It){
    UProperty* Prop=*It;
    const TCHAR* nm=*Prop->GetFName();
    if(!appStricmp(nm,TEXT("ConfigValue"))||!appStricmp(nm,TEXT("ScratchValue"))||!appStricmp(nm,TEXT("PersistValue"))){
     printf("T019_V432_ORIGINAL_CPP_INT %ls=%d flags=%08X offset=%d\n",nm,*(INT*)((BYTE*)Default+Prop->Offset),(unsigned)Prop->PropertyFlags,Prop->Offset);
    }
    if(!appStricmp(nm,TEXT("LocalMessage"))){
     FString* value=(FString*)((BYTE*)Default+Prop->Offset);
     printf("T019_V432_ORIGINAL_CPP_LOCAL_BEFORE=%ls flags=%08X\n",**value,(unsigned)Prop->PropertyFlags);
     GIsEditor=0;Default->LoadLocalized(0,C);GIsEditor=1;
     printf("T019_V432_ORIGINAL_CPP_LOCAL_AFTER=%ls\n",**value);
    }
   }
   ok=1;
  }
 }catch(...){printf("T019_V432_ORIGINAL_CPP_EXCEPTION\n");}
 printf("T019_V432_ORIGINAL_CPP_RESULT=%d\n",ok);
 appPreExit();appExit();return ok?0:7;
}
