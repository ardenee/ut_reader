// Disposable original UT99 v432 compiled Core.dll: force direct Core.Object.Vector/Rotator/Color UStruct LOAD.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[]=TEXT("G3T001V432"); }
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
 UObject* pkg=UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail);
 printf("G3_T001_ORIGINAL_V432_PACKAGE=%p\n",(void*)pkg);
 const TCHAR* names[]={TEXT("Core.Object.Vector"),TEXT("Core.Object.Rotator"),TEXT("Core.Object.Color"),TEXT("Core.Object.Plane")};
 int count=0;
 for(int i=0;i<4;i++){
  UObject* obj=UObject::StaticFindObject(UStruct::StaticClass(),(UObject*)-1,names[i]);
  printf("G3_T001_ORIGINAL_V432_FIND[%d]=%p\n",i,(void*)obj);
  fflush(stdout);
  if(obj){UStruct* s=(UStruct*)obj;printf("G3_T001_V432_LINK[%d] linker=%p export=%d child=%p script=%d line=%d textpos=%d friendly_global=%d propssize=%d propsalign=%d scripttext_null=%d\n",i,(void*)obj->GetLinker(),obj->GetLinkerIndex(),(void*)s->Children,(int)s->Script.Num(),(int)s->Line,(int)s->TextPos,(int)s->FriendlyName.GetIndex(),(int)s->PropertiesSize,(int)s->PropertiesAlign,s->ScriptText==NULL);UField* child=s->Children;UField* next=s->Next;UField* sup=s->SuperField;printf("G3_T001_V432_REFERENTS[%d] child_index=%d next_index=%d super_index=%d child_same_linker=%d next_same_linker=%d friendly_name=%S\n",i,child?child->GetLinkerIndex():-1,next?next->GetLinkerIndex():-1,sup?sup->GetLinkerIndex():-1,child?child->GetLinker()==s->GetLinker():0,next?next->GetLinker()==s->GetLinker():0,*s->FriendlyName);count++;}
 }
 printf("G3_T001_V432_FOUND=%d\n",count);fflush(stdout);
 appPreExit();appExit();return count==4?0:8;
}
