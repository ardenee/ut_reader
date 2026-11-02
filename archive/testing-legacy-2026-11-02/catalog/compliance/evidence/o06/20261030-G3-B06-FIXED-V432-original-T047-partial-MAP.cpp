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
 UBOOL fail=0;
 try{
  UClass* actual=UObject::StaticLoadClass(UObject::StaticClass(),NULL,
    TEXT("G5StructStringProbe.G5StructStringProbeActor"),NULL,LOAD_NoFail,NULL);
  printf("G3_B06_ORIGINAL_V432_CLASS %p\n",actual);
  check(actual);
  UMapProperty* map = new(actual,FName(TEXT("G3B06OriginalMap")),RF_Transient)UMapProperty;
  UProperty* key = new(map,FName(TEXT("G3MapKey")),RF_Transient)UIntProperty;
  UProperty* value = new(map,FName(TEXT("G3MapValue")),RF_Transient)UIntProperty;
  map->Key=key; map->Value=value;
  printf("G3_B06_ORIGINAL_V432_REAL_MAP map=%p key=%p val=%p\n",map,key,value);
  TMap<INT,INT> empty, nonempty;
  nonempty.Set(12,1200);
  nonempty.Set(34,3400);
  printf("G3_B06_ORIGINAL_V432_REAL_MAP_START nonemptyCount=%d first=%d second=%d\n",
    nonempty.Num(),*nonempty.Find(12),*nonempty.Find(34));
  for(INT i=0;i<2;i++){
    TMap<INT,INT>* obj=i?&nonempty:&empty;
    FBufferArchive ar;
    map->SerializeItem(ar,obj);
    FBufferReader rd(ar);
    map->SerializeItem(rd,obj);
    printf("G3_B06_ORIGINAL_V432_REAL_MAP_CASE idx=%d originalElements=%d archived=%d readerPos=%d remaining=%d\n",
      i,obj->Num(),ar.Num(),rd.Tell(),obj->Num());
    if(ar.Num()!=0||rd.Tell()!=0||obj->Num()!=(i?2:0))fail=1;
    if(i && (*obj->Find(12)!=1200 || *obj->Find(34)!=3400))fail=1;
  }
 }catch(...){printf("G3_B06_ORIGINAL_V432_MAP_NATIVE_ERROR\n");Error.HandleError();fail=1;}
 appPreExit();appExit();return fail?7:0;
}
