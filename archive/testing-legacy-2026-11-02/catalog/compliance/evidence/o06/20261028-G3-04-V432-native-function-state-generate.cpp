// Disposable original 227k native SAVE/LOAD round-trip for UState/UFunction.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[]=TEXT("G304V432MethodSave"); }
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
 GIsStarted=1;GIsGuarded=1;TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL success=0;
 try{
  UPackage* p=UObject::CreatePackage(NULL,TEXT("G304V432FunctionStateFixture"));
  printf("G304_469F_NATIVE_NEW_PACKAGE %p\n",(void*)p);fflush(stdout);
  UState* state=(UState*)UObject::StaticConstructObject(UState::StaticClass(),p,
    FName(TEXT("G304v432State")),RF_Public|RF_Standalone);
  printf("G304_469F_NATIVE_NEW_STATE %p\n",(void*)state);fflush(stdout);
  UFunction* fn=(UFunction*)UObject::StaticConstructObject(UFunction::StaticClass(),state,
    FName(TEXT("G304v432Function")),RF_Public|RF_Standalone);
  printf("G304_469F_NATIVE_NEW_FUNCTION %p\n",(void*)fn);fflush(stdout);
  state->FriendlyName=FName(TEXT("G304v432State"));
  state->ProbeMask=0x1020304050607080ULL;
  state->IgnoreMask=0x1234567890abcdefULL;
  state->StateFlags=0x00010200;
  state->LabelTableOffset=0;
  state->Children=fn;
  fn->FriendlyName=FName(TEXT("G304v432Function"));
  fn->FunctionFlags=0x00000002;
  fn->iNative=0;
  fn->RepOffset=0;
  fn->OperPrecedence=0;
  fn->NumParms=0;
  fn->ParmsSize=0;
  fn->ReturnValueOffset=65535;
  success=UObject::SavePackage(p,NULL,RF_Public|RF_Standalone,
     TEXT("C:\\Temp\\UnrealDB\\O06\\Native-UCC\\System\\G304V432FunctionStateFixture.u"),NULL,NULL);
  printf("G304_469F_NATIVE_FUNCTION_STATE_SAVED=%d\n",(int)success);fflush(stdout);
 }catch(...){printf("G304_469F_NATIVE_FUNCTION_STATE_SAVE_EXCEPTION\n");fflush(stdout);}
 appPreExit();appExit();return success?0:7;
}
