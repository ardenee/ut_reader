// Disposable authentic OldUnreal UT99 469f Engine.u loader oracle.
// Imports original unchanged x86 469f Core.dll through genuine Core.lib.
// Instrument UFunction::Serialize and UState::Serialize via Microsoft x86 CDB.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G304469fEngineProbe"); }
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
  appInit(TEXT("UnrealTournament"),cmd,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
  UObject::SetLanguage(TEXT("int"));
  GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
  UBOOL loaded=0;
  try{
    UPackage* pkg=(UPackage*)UObject::LoadPackage(NULL,TEXT("Engine.u"),LOAD_NoFail);
    loaded=(pkg!=NULL);
    printf("G304_469F_ENGINE_REAL_COMPILED_LOADER_PACKAGE=%p\n",(void*)pkg);fflush(stdout);
  }catch(...){printf("G304_469F_ENGINE_REAL_COMPILED_LOADER_EXCEPTION\n");fflush(stdout);}
  appPreExit();appExit();
  printf("G304_469F_ENGINE_LOADER_RESULT=%d\n",(int)loaded);fflush(stdout);
  return loaded?0:9;
}
