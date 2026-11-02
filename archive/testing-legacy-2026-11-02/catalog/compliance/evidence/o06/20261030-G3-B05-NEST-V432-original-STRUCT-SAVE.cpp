// Disposable UT99 compiled v432 actual UStruct->FString archive producer.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[64]=TEXT("G5StringStructNative"); }
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
#include "FMallocAnsi.h"
FMallocAnsi Malloc;
#include "FConfigCacheIni.h"
static void SetRecord(UStruct* layout,BYTE* dest,const TCHAR* message,INT num){
 for(TFieldIterator<UProperty> It(layout);It;++It){
   UProperty* field=*It;
   if(appStricmp(*field->GetFName(),TEXT("Label"))==0){
     check(field->IsA(UStrProperty::StaticClass()));
     *(FString*)(dest+field->Offset)=message;
     printf("NATIVE_SET_STRUCT_STRING text='%ls' fieldOffset=%d\n",message,field->Offset);
   }else if(appStricmp(*field->GetFName(),TEXT("Marker"))==0){
     check(field->IsA(UIntProperty::StaticClass()));
     *(INT*)(dest+field->Offset)=num;
     printf("NATIVE_SET_STRUCT_INT value=%d fieldOffset=%d\n",num,field->Offset);
   }
 }
}
int main(){
 setvbuf(stdout,NULL,_IONBF,0);
 GIsStarted=1;GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("UnrealTournament"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL saved=0;
 try{
  UClass* actor=UObject::StaticLoadClass(UObject::StaticClass(),NULL,
    TEXT("G5StructStringProbe.G5StructStringProbeActor"),NULL,LOAD_NoFail,NULL);
  check(actor);
  UPackage* package=UObject::CreatePackage(NULL,TEXT("G5StructStringDirectPayload"));
  UObject* object=UObject::StaticConstructObject(actor,package,FName(TEXT("G5StructStringActor")),RF_Public|RF_Standalone);
  check(object);
  UArrayProperty* records=NULL;
  for(TFieldIterator<UProperty> It(actor);It;++It){
    UProperty* field=*It;
    if(appStricmp(*field->GetFName(),TEXT("OneRecord"))==0){
      UStructProperty* sp=(UStructProperty*)field;
      check(field->IsA(UStructProperty::StaticClass()));
      SetRecord(sp->Struct,(BYTE*)object+field->Offset,TEXT("Direct-Record"),71);
    }else if(appStricmp(*field->GetFName(),TEXT("Records"))==0){
      check(field->IsA(UArrayProperty::StaticClass()));
      records=(UArrayProperty*)field;
      printf("NATIVE_ARRAY_FIELD offset=%d element=%d\n",field->Offset,records->Inner->ElementSize);
    }
  }
  check(records&&records->Inner->IsA(UStructProperty::StaticClass()));
  saved=UObject::SavePackage(package,NULL,RF_Public|RF_Standalone,
     TEXT("C:\\Temp\\UnrealDB\\O06\\Native-UCC\\System\\G5StructStringDirectPayload.u"));
  printf("NATIVE_DIRECT_STRUCT_SAVED %d\n",saved);
  UPackage* package2=UObject::CreatePackage(NULL,TEXT("G5StructStringArrayPayload"));
  UObject* object2=UObject::StaticConstructObject(actor,package2,FName(TEXT("G5StructStringActor")),RF_Public|RF_Standalone);
  check(object2);
  UStructProperty* inner=(UStructProperty*)records->Inner;
  FArray* a=(FArray*)((BYTE*)object2+records->Offset);
  a->Empty(inner->ElementSize);
  a->AddZeroed(inner->ElementSize,1);
  SetRecord(inner->Struct,(BYTE*)a->GetData(),TEXT("Nested-Record"),84);
  saved=UObject::SavePackage(package2,NULL,RF_Public|RF_Standalone,
     TEXT("C:\\Temp\\UnrealDB\\O06\\Native-UCC\\System\\G5StructStringArrayPayload.u"));
  printf("NATIVE_ARRAY_STRUCT_SAVED %d\n",saved);
 }catch(...){printf("NATIVE_STRING_STRUCT_SAVE_EXCEPTION\n");Error.HandleError();saved=0;}
 appPreExit();appExit();return saved?0:1;
}