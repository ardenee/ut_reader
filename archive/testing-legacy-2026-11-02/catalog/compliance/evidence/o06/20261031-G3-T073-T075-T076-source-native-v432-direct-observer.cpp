#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" {HINSTANCE hInstance;}
extern "C" {TCHAR GPackage[]=TEXT("G3T076ShippedDetailOriginalNative");}
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
 UObject::SetLanguage(TEXT("int")); GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;

 int ok=0;
 try{
  UPackage* P=(UPackage*)UObject::LoadPackage(NULL,TEXT("Detail"),LOAD_NoFail);
  printf("G3_T076_SHIPPED_ORIGINAL_NATIVE_PACKAGE=%p\n",P);fflush(stdout);
  UObject* stones=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("stones")):NULL;
  UObject* dirty=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("dirty")):NULL;
  UObject* paint=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("paint1")):NULL;
  UObject* rough=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("rough")):NULL;
  printf("G3_T076_SHIPPED_ORIGINAL_NATIVE_FOUND stones=%p dirty=%p paint1=%p rough=%p\n",stones,dirty,paint,rough);fflush(stdout);
  UObject* loadedPaint=NULL;UObject* loadedRough=NULL;
  try{loadedPaint=UObject::StaticLoadObject(UObject::StaticClass(),P,TEXT("paint1"),NULL,LOAD_NoWarn,NULL);}
  catch(...){printf("G3_T076_SHIPPED_PAINT_MISSING_EXCEPTION\n");}
  try{loadedRough=UObject::StaticLoadObject(UObject::StaticClass(),P,TEXT("rough"),NULL,LOAD_NoWarn,NULL);}
  catch(...){printf("G3_T076_SHIPPED_ROUGH_MISSING_EXCEPTION\n");}
  printf("G3_T076_SHIPPED_ORIGINAL_NATIVE_ACTUAL paint1=%p rough=%p\n",loadedPaint,loadedRough);fflush(stdout);
  if(P && loadedPaint && !loadedRough)ok=68;
  if(P && loadedRough && !loadedPaint)ok=69;
 }catch(...){printf("G3_T076_SHIPPED_ORIGINAL_NATIVE_PACKAGE_MISSING_OR_CORRUPT\n");fflush(stdout);}
 printf("G3_T076_SHIPPED_ORIGINAL_NATIVE_SELECTED_VERSION=%d\n",ok);fflush(stdout);
 appPreExit();appExit();return ok?0:7;
}
