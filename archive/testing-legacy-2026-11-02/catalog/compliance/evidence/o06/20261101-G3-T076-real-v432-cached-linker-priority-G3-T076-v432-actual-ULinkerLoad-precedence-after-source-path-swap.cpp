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
int main(int argc, char** argv){
 GIsStarted=1;GIsGuarded=1;TCHAR cmd[64]=TEXT("");
 appInit(TEXT("Unreal"),cmd,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int")); GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 const bool textureFirst=(argc>1 && !strcmp(argv[1],"texture_first"));
 if(GSys->Paths.Num()<3){printf("G3_T076_CACHED_ERROR=source Paths absent\n");return 6;}
 const TCHAR* sys=TEXT("../System/*.u");
 const TCHAR* tex=TEXT("../Textures/*.utx");
 FString before0=GSys->Paths(0), before2=GSys->Paths(2);
 GSys->Paths(0)=textureFirst?tex:sys;
 GSys->Paths(2)=textureFirst?sys:tex;
 printf("G3_T076_ORIGINAL_LOADED_LINKER_INITIAL first=%s second=%s\n",
  textureFirst?"Textures":"System",textureFirst?"System":"Textures");fflush(stdout);
 UPackage* p1=NULL; UPackage* p2=NULL;
 UObject* saved=NULL; UObject* firstPaint=NULL;UObject* firstRough=NULL;
 UObject* afterPaint=NULL;UObject* afterRough=NULL;
 try {
  p1=(UPackage*)UObject::LoadPackage(NULL,TEXT("Detail"),LOAD_NoFail);
  firstPaint=p1?UObject::StaticLoadObject(UObject::StaticClass(),p1,TEXT("paint1"),NULL,LOAD_NoWarn,NULL):NULL;
  firstRough=p1?UObject::StaticLoadObject(UObject::StaticClass(),p1,TEXT("Rough"),NULL,LOAD_NoWarn,NULL):NULL;
  saved=firstPaint?firstPaint:firstRough;
  printf("G3_T076_ORIGINAL_LINKER_CACHED_FIRST PACKAGE=%p paint1=%p Rough=%p selected=%s\n",
   p1,firstPaint,firstRough,firstPaint&&!firstRough?"v68":firstRough&&!firstPaint?"v69":"unproven");fflush(stdout);
  FString temporary=GSys->Paths(0);
  GSys->Paths(0)=GSys->Paths(2);
  GSys->Paths(2)=temporary;
  p2=(UPackage*)UObject::LoadPackage(NULL,TEXT("Detail"),LOAD_NoFail);
  afterPaint=p2?UObject::StaticLoadObject(UObject::StaticClass(),p2,TEXT("paint1"),NULL,LOAD_NoWarn,NULL):NULL;
  afterRough=p2?UObject::StaticLoadObject(UObject::StaticClass(),p2,TEXT("Rough"),NULL,LOAD_NoWarn,NULL):NULL;
  printf("G3_T076_ORIGINAL_LINKER_CACHED_AFTER_ORDER_SWAP PACKAGE=%p paint1=%p Rough=%p samePackage=%d sameSelectedObject=%d\n",
    p2,afterPaint,afterRough,(int)(p1==p2),(int)((afterPaint?afterPaint:afterRough)==saved));fflush(stdout);
 }catch(...){printf("G3_T076_ORIGINAL_LINKER_CACHED_EXCEPTION\n");fflush(stdout);}
 const bool good=p1 && p1==p2 && saved && ((afterPaint?afterPaint:afterRough)==saved) &&
   (textureFirst ? firstRough&&!firstPaint&&!afterPaint : firstPaint&&!firstRough&&!afterRough);
 GSys->Paths(0)=before0; GSys->Paths(2)=before2;
 printf("G3_T076_ORIGINAL_LINKER_CACHED_RESULT=%d\n",good?1:0);fflush(stdout);
 appPreExit();appExit();
 return good?0:7;
}
