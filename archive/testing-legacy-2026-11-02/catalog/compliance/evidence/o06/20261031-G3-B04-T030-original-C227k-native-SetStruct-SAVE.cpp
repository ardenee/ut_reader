// New frozen G3 T030: ORIGINAL 2026 C227k compiled Core.dll genuine UAnyProperty::SetStruct and SavePackage, no UCC ImportText substitute.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3AnyStructOriginalSave"); }
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
 UBOOL bad=0,saved=0;UObject* loaded=NULL;
 try{loaded=UObject::LoadPackage(NULL,TEXT("G3Any227K.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_T030_ORIGINAL_LOAD_COMPILER_PACKAGE ptr=%p bad=%d\n",loaded,bad);fflush(stdout);
 UClass* cl=NULL;UStruct* structType=NULL;
 if(loaded&&!bad)for(FObjectIterator It;It;++It){
  UObject* o=*It;
  if(o&&o->IsA(UClass::StaticClass()) &&
    appStrcmp(o->GetFullName(),TEXT("Class G3Any227K.G3Any227KActor"))==0)
     cl=(UClass*)o;
  if(o&&o->IsA(UStruct::StaticClass()) &&
    appStrcmp(o->GetFullName(),TEXT("Struct Core.Object.Vector"))==0)
     structType=(UStruct*)o;
 }
 printf("G3_T030_ORIGINAL_NATIVE_CLASS %p real_UStruct_Vector=%p\n",cl,structType);fflush(stdout);
 if(cl && !structType){
  for(FObjectIterator It;It;++It){
   UObject* o=*It;
   if(o&&o->IsA(UStruct::StaticClass())&&appStrcmp(*o->GetFName(),TEXT("Vector"))==0){
     printf("G3_T030_ORIGINAL_SOURCE_VECTOR_METADATA %ls\n",o->GetFullName());fflush(stdout);
     structType=(UStruct*)o;break;
   }
  }
 }
 printf("G3_T030_ORIGINAL_STRUCT_RESOLVED %p\n",structType);fflush(stdout);
 if(cl&&structType){
  UPackage* pkg=UObject::CreatePackage(NULL,TEXT("G3Any227KRuntime"));
  UObject* obj=UObject::StaticConstructObject(cl,pkg,FName(TEXT("AnyStructInstance")),RF_Public|RF_Standalone);
  printf("G3_T030_ORIGINAL_REAL_CREATED_INSTANCE %p\n",obj);fflush(stdout);
  if(obj){
    for(TFieldIterator<UProperty> It(cl);It;++It){
      UProperty* p=*It;
      if(!p||!p->IsA(UAnyProperty::StaticClass()))continue;
      if(appStricmp(*p->GetFName(),TEXT("StructValue"))!=0)continue;
      FAnyProperty** slot=(FAnyProperty**)((BYTE*)obj+p->Offset);
      if(*slot)(*slot)->Release();
      FAnyProperty* any=new FAnyProperty;
      FVector vec(3.25f,-4.5f,5.75f);
      printf("G3_T030_ORIGINAL_REAL_STRUCT FVector bytes x=%f y=%f z=%f size=%d structSize=%d\n",vec.X,vec.Y,vec.Z,(INT)sizeof(vec),structType->GetPropertiesSize());fflush(stdout);
      any->SetStruct((BYTE*)&vec,structType);
      *slot=any;
      printf("G3_T030_ORIGINAL_NATIVE_ANY_SET name=%ls runtimeOffset=%d type=%d trueStruct=%ls raw=%p\n",
         *p->GetFName(),p->Offset,any->GetType(),any->GetStructType()->GetFullName(),any->GetRawData());fflush(stdout);
      saved=UObject::SavePackage(pkg,NULL,RF_Public|RF_Standalone,
        TEXT("C:\\Temp\\UnrealDB\\O06\\Native-227k\\System\\G3Any227KRuntime.u"));
      printf("G3_T030_ORIGINAL_NATIVE_SAVEPACKAGE result=%d\n",saved);fflush(stdout);
    }
  }
 }
 appPreExit();appExit();
 return saved?0:5;
}
