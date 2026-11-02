// Distinct genuine original UT99 469f Core.dll LOAD of original May17 C98
// FObjectManager-written v61 .u. Exercises UT99 UFunction::Serialize Ar.Ver()<=63.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3T076Original469fShippedDetail"); }
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

 int ok=0;
 try {
  UPackage* P=(UPackage*)UObject::LoadPackage(NULL,TEXT("Detail"),LOAD_NoFail);
  printf("G3_T076_469F_SHIPPED_NATIVE_PACKAGE=%p\n",P);fflush(stdout);
  UObject* shared=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("stones")):NULL;
  UObject* paint=NULL;UObject* rough=NULL;
  try{paint=UObject::StaticLoadObject(UObject::StaticClass(),P,TEXT("paint1"),NULL,LOAD_NoWarn,NULL);}catch(...){}
  try{rough=UObject::StaticLoadObject(UObject::StaticClass(),P,TEXT("rough"),NULL,LOAD_NoWarn,NULL);}catch(...){}
  printf("G3_T076_469F_SHIPPED_NATIVE_ACTUAL stones=%p paint1=%p rough=%p\n",shared,paint,rough);fflush(stdout);
  if(P&&paint&&!rough)ok=68;
  if(P&&rough&&!paint)ok=69;
 }catch(...){printf("G3_T076_469F_SHIPPED_NATIVE_MISSING_OR_INVALID\n");fflush(stdout);}
 printf("G3_T076_469F_SHIPPED_NATIVE_SELECTED_VERSION=%d\n",ok);fflush(stdout);
 appPreExit();appExit();return ok?0:7;
}
