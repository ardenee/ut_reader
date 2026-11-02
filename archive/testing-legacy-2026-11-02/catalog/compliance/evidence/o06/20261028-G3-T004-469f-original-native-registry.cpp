// T004: genuine original compiled UT99 469f Core.dll forced direct registry UStruct LOAD.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR* GPackage=TEXT("G3T004469f"); }
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
 GIsStarted=1;GIsGuarded=1;TCHAR cmd[64]=TEXT("");
 appInit(TEXT("Unreal"),cmd,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UObject* pkg=UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail);
 printf("G3_T004_469F_PACKAGE=%p\n",(void*)pkg);fflush(stdout);
 const TCHAR* names[]={
  TEXT("Core.Registry.RegistryPropertyInfo"),
  TEXT("Core.Registry.RegistryObjectInfo"),
  TEXT("Core.Registry.PreferencesInfo")};
 int loaded=0;
 for(int i=0;i<3;i++){
  UStruct* s=(UStruct*)UObject::StaticFindObject(UStruct::StaticClass(),(UObject*)-1,names[i]);
  printf("G3_T004_469F_LOOKUP[%d] obj=%p\n",i,(void*)s);fflush(stdout);
  if(!s)continue;
  UField* child=s->Children;
  UField* next=s->Next;
  UField* super=s->SuperField;
  printf("G3_T004_469F_NATIVE[%d] export=%d script=%d line=%d textpos=%d name=%S child_ref=%d next_ref=%d super_ref=%d scripttext_null=%d child_same_linker=%d\n",
    i,s->GetLinkerIndex(),s->Script.Num(),s->Line,s->TextPos,*s->FriendlyName,
    child?child->GetLinkerIndex()+1:0,next?next->GetLinkerIndex()+1:0,
    super?super->GetLinkerIndex()+1:0,s->ScriptText==NULL,
    child?child->GetLinker()==s->GetLinker():0);
  fflush(stdout);loaded++;
 }
 printf("G3_T004_469F_LOADED_COUNT=%d\n",loaded);fflush(stdout);
 appPreExit();appExit();return loaded==3?0:9;
}
