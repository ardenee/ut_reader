// NEW frozen T053: actual original shipped 2026 C227k Core.dll native loads genuinely 2012 C227i UCC-compiled class with mixed FName, UObject class and struct expression.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3T053ActualMixedScript"); }
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
 GIsStarted=1;GIsGuarded=1;TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* pkg=NULL;
 try{pkg=UObject::LoadPackage(NULL,TEXT("G3Script227.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_T053_ORIGINAL_COMPILATION_2012_LOAD_2026 pkg=%p bad=%d\n",pkg,bad);fflush(stdout);
 int functions=0;
 if(pkg&&!bad)for(FObjectIterator It;It;++It){
  UObject* o=*It;
  if(!o||!o->IsA(UFunction::StaticClass()))continue;
  if(!o->IsIn(pkg))continue;
  UFunction* f=(UFunction*)o;
  const TCHAR* n=*f->GetFName();
  if(appStrncmp(n,TEXT("Probe"),5)!=0)continue;
  functions++;
  printf("G3_T053_ORIGINAL_NATIVE_FUNCTION fn=%ls full=%ls script_len=%d names=%d objects=%d\n",
     n,o->GetFullName(),f->Script.Num(),f->ScriptNames.Num(),f->ScriptObjects.Num());
  printf("G3_T053_ORIGINAL_NATIVE_BYTES fn=%ls ",n);
  for(INT i=0;i<f->Script.Num();i++)printf("%02X",(unsigned)f->Script(i));
  printf("\n");
  for(INT i=0;i<f->ScriptNames.Num();i++)printf("G3_T053_ORIGINAL_TYPED_NAME fn=%ls ordinal=%d text=%ls\n",
    n,i,*f->ScriptNames(i));
  for(INT i=0;i<f->ScriptObjects.Num();i++){
   UObject* target=f->ScriptObjects(i);
   printf("G3_T053_ORIGINAL_TYPED_OBJECT fn=%ls ordinal=%d full=%ls\n",
      n,i,target?target->GetFullName():TEXT("NULL"));
  }
  fflush(stdout);
 }
 printf("G3_T053_ORIGINAL_REAL_LOADED_MIXED_FUNCTIONS=%d\n",functions);fflush(stdout);
 appPreExit();appExit();return pkg&&!bad&&functions>=3?0:7;
}
