// T003 genuine original UT99 v432 x86 Core.dll and Engine.dll loaded script bytes.
// Object references are relocated 32-bit runtime addresses; compare source-known mask.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[]=TEXT("G3T003V432"); }
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
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UObject* core=UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail);
 UObject* engine=UObject::LoadPackage(NULL,TEXT("Engine.u"),LOAD_NoFail);
 printf("G3_T003_V432_NATIVE_PACKAGE_CORE=%p ENGINE=%p\n",(void*)core,(void*)engine);fflush(stdout);
 const TCHAR* names[]={TEXT("Core.Object.Vector"),TEXT("Core.Object.NotEqual_FloatFloat"),TEXT("Engine.Actor.PostBeginPlay")};
 int found=0;
 for(int i=0;i<3;i++){
  UStruct* s=(UStruct*)UObject::StaticFindObject(UStruct::StaticClass(),(UObject*)-1,names[i]);
  if(!s){printf("G3_T003_V432_NATIVE_MISSING[%d]\n",i);fflush(stdout);continue;}
  printf("G3_T003_V432_NATIVE_SCRIPT[%d] linker=%p export=%d memory_count=%d bytes_hex=",
    i,(void*)s->GetLinker(),s->GetLinkerIndex(),s->Script.Num());
  for(int j=0;j<s->Script.Num()&&j<1024;j++)printf("%02x",(unsigned)s->Script(j));
  printf("\n");fflush(stdout);
  if(i==0 && s->Script.Num()==0) found++;
  if(i==1 && s->Script.Num()==10) found++;
  if(i==2 && s->Script.Num()==2) found++;
 }
 printf("G3_T003_V432_ORIGINAL_NATIVE_MEMORY_MATCH=%d\n",found);fflush(stdout);
 appPreExit();appExit();return (core&&engine&&found==3)?0:8;
}
