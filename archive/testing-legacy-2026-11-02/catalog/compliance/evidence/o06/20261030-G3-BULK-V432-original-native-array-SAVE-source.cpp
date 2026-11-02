// Disposable compiled UT99 v432 Core archive producer. Never modify Epic files.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[64]=TEXT("G5ArrayNative"); }
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
// Isolated compatibility alias for Epic v432 header INT I / i typo under modern MSVC.
// Original header at L:\\Source is not altered.
#define i I
#include "FFileManagerWindows.h"
#undef i
FFileManagerWindows FileManager;
#include "FMallocAnsi.h"
FMallocAnsi Malloc;
#include "FConfigCacheIni.h"
int main() {
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("UnrealTournament"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL ok=0;
 try {
  UClass* actor=UObject::StaticLoadClass(UObject::StaticClass(),NULL,
    TEXT("G5ArrayProbe.G5ArrayProbeActor"),NULL,LOAD_NoFail,NULL);
  printf("G5_ARRAY_CLASS %p\n",actor);
  UPackage* package=UObject::CreatePackage(NULL,TEXT("G5ArrayPayload"));
  UObject* value=UObject::StaticConstructObject(actor,package,FName(TEXT("G5DynamicActor")),
     RF_Public|RF_Standalone);
  printf("G5_ARRAY_OBJECT %p\n",value);
  for(TFieldIterator<UProperty> It(actor);It;++It) {
   UProperty* field=*It;
   if(!field->IsA(UArrayProperty::StaticClass()))continue;
   UArrayProperty* p=(UArrayProperty*)field;
   FArray* arr=(FArray*)((BYTE*)value+p->Offset);
   if(appStricmp(*field->GetFName(),TEXT("Numbers"))==0) {
    arr->Empty(sizeof(INT));
    arr->Add(3,sizeof(INT));
    INT* a=(INT*)arr->GetData();a[0]=11;a[1]=42;a[2]=-7;
    printf("G5_ARRAY_NUMBER source_count=%d elem_size=%d\n",arr->Num(),p->Inner->ElementSize);
   } else if(appStricmp(*field->GetFName(),TEXT("Labels"))==0) {
    arr->Empty(sizeof(FName));arr->Add(2,sizeof(FName));
    FName* a=(FName*)arr->GetData();a[0]=FName(TEXT("Alpha"));a[1]=FName(TEXT("Beta"));
    printf("G5_ARRAY_NAME source_count=%d elem_size=%d\n",arr->Num(),p->Inner->ElementSize);
   } else if(appStricmp(*field->GetFName(),TEXT("References"))==0) {
    arr->Empty(sizeof(UObject*));arr->Add(2,sizeof(UObject*));
    UObject** a=(UObject**)arr->GetData();a[0]=value;a[1]=NULL;
    printf("G5_ARRAY_REFERENCE source_count=%d elem_size=%d\n",arr->Num(),p->Inner->ElementSize);
   }
  }
  ok=UObject::SavePackage(package,NULL,RF_Public|RF_Standalone,
    TEXT("C:\\Temp\\UnrealDB\\O06\\Native-UCC\\System\\G5ArrayPayload.u"));
  printf("G5_ARRAY_SAVE %d\n",ok);
 }catch(...){printf("G5_ARRAY_GENERATION_EXCEPTION\n"); Error.HandleError();}
 appPreExit();appExit();
 return ok?0:6;
}
