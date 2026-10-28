// Fresh original 227k compiled Core.dll round-trip for tiny native SAVE fixture.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G304469fBadNetReload"); }
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
 UBOOL ok=0;
 try{
  UPackage* pkg=(UPackage*)UObject::LoadPackage(NULL,TEXT("G304469FFunctionStateBadNet.u"),LOAD_NoFail);
  printf("G304_469F_ORIGINAL_REAL_LOADER_PACKAGE=%p\n",(void*)pkg);fflush(stdout);
  UState* s=pkg?(UState*)UObject::StaticFindObject(UState::StaticClass(),pkg,TEXT("G304469fState")):NULL;
  UFunction* f=s?(UFunction*)UObject::StaticFindObject(UFunction::StaticClass(),s,TEXT("G304469fFunction")):NULL;
  printf("G304_469F_ORIGINAL_REAL_LOADER_STATE=%p FUNCTION=%p\n",(void*)s,(void*)f);fflush(stdout);
  if(s)printf("G304_469F_LOADED_STATE_PROBE=%016llX IGNORE=%016llX FLAGS=%08X LABEL=%u CHILD=%p\n",
   (unsigned long long)s->ProbeMask,(unsigned long long)s->IgnoreMask,
   (unsigned)s->StateFlags,(unsigned)s->LabelTableOffset,(void*)s->Children);
  if(f)printf("G304_469F_LOADED_FUNCTION_FLAGS=%08X NATIVE=%u PRECEDENCE=%u NUMPARMS=%u PARMSIZE=%u RETURN=%u\n",
  (unsigned)f->FunctionFlags,(unsigned)f->iNative,(unsigned)f->OperPrecedence,
  (unsigned)f->NumParms,(unsigned)f->ParmsSize,(unsigned)f->ReturnValueOffset);
  fflush(stdout);
  ok=(s&&f&&s->ProbeMask==0x1020304050607080ULL
     &&s->IgnoreMask==0x1234567890abcdefULL&&s->StateFlags==0x00010200
     &&s->Children==f && f->FunctionFlags==2
     && f->NumParms==0 && f->ParmsSize==0 && f->ReturnValueOffset==65535);
  printf("G304_469F_ORIGINAL_REAL_LOADER_FIELDS_MATCH=%d\n",(int)ok);fflush(stdout);
 }catch(...){printf("G304_469F_ORIGINAL_REAL_LOADER_EXCEPTION\n");fflush(stdout);}
 appPreExit();appExit();return ok?0:8;
}
