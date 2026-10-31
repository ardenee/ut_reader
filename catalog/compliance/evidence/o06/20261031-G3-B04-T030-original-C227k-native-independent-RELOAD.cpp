// Frozen G3 T030 genuine original shipped C227k Core.dll independent LOAD,
 // original C++ native UAnyProperty FAny::SetStruct SavedPackage 2026 (no simulated/byte-edited package).
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3AnyStructOriginalReload"); }
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
#include "FFileManagerWindows.h"
FFileManagerWindows FileManager;
#include "FMallocAnsi.h"
FMallocAnsi Malloc;
#include "FConfigCacheIni.h"
int main(){
 GIsStarted=1;GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL failed=0;
 UObject* decl=NULL;
 UObject* pkg=NULL;
 try{decl=UObject::LoadPackage(NULL,TEXT("G3Any227K.u"),LOAD_NoFail);}
 catch(...){failed=1;Error.HandleError();}
 printf("G3_T030_ORIGINAL_INDEPENDENT_DECLARATIONS=%p failed=%d\n",decl,failed);fflush(stdout);
 if(decl&&!failed){
  try{pkg=UObject::LoadPackage(NULL,TEXT("G3Any227KRuntime.u"),LOAD_NoFail);}
  catch(...){failed=1;Error.HandleError();}
 }
 printf("G3_T030_ORIGINAL_INDEPENDENT_RUNTIME_PACKAGE=%p failed=%d\n",pkg,failed);fflush(stdout);
 UClass* cls=NULL;UObject* instance=NULL;
 if(pkg&&!failed)for(FObjectIterator It;It;++It){
  UObject* obj=*It;
  if(obj && obj->IsA(UClass::StaticClass())&&
     appStrcmp(obj->GetFullName(),TEXT("Class G3Any227K.G3Any227KActor"))==0)cls=(UClass*)obj;
  if(obj && !appStricmp(*obj->GetFName(),TEXT("AnyStructInstance"))){
   printf("G3_T030_ORIGINAL_RELOADED_CANDIDATE name=%ls\n",obj->GetFullName());fflush(stdout);
   instance=obj;
  }
 }
 printf("G3_T030_ORIGINAL_INDEPENDENT_INST_AND_CLASS inst=%p cls=%p\n",instance,cls);
 INT success=0;
 if(instance&&cls&&instance->IsA(cls)){
  for(TFieldIterator<UProperty> It(cls);It;++It){
   UProperty* p=*It;
   if(!p||!p->IsA(UAnyProperty::StaticClass())||appStricmp(*p->GetFName(),TEXT("StructValue"))!=0)continue;
   FAnyProperty** slot=(FAnyProperty**)((BYTE*)instance+p->Offset);
   FAnyProperty* value=slot?*slot:NULL;
   printf("G3_T030_ORIGINAL_INDEPENDENT_REAL_ANY_PROP offset=%d ptr=%p anytype=%d\n",
       p->Offset,value,value?value->GetType():-1);
   if(value&&value->GetType()==6&&value->GetStructType()&&value->GetRawData()){
    FVector* vec=(FVector*)value->GetRawData();
    printf("G3_T030_ORIGINAL_INDEPENDENT_REAL_STRUCT full=%ls structSize=%d vectorX=%f vectorY=%f vectorZ=%f bitsX=%08X bitsY=%08X bitsZ=%08X\n",
      value->GetStructType()->GetFullName(),value->GetStructType()->GetPropertiesSize(),
      vec->X,vec->Y,vec->Z,*(DWORD*)&vec->X,*(DWORD*)&vec->Y,*(DWORD*)&vec->Z);
    success=value->GetStructType()->GetPropertiesSize()==12
       &&vec->X==3.25f&&vec->Y==-4.5f&&vec->Z==5.75f;
   }
  }
 }
 printf("G3_T030_ORIGINAL_INDEPENDENT_REAL_LOAD_PASS=%d\n",success);fflush(stdout);
 appPreExit();appExit();return success?0:9;
}
