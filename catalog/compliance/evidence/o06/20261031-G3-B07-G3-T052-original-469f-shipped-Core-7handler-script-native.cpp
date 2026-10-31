// Frozen T052 actual shipped original 469f Core.dll native loaded genuine Engine.u, typed script memory for all seven compiled handler different groups.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3T052Original469fOpcodeNative"); }
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
 UObject::SetLanguage(TEXT("int"));GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* pkg=NULL;
 try{pkg=UObject::LoadPackage(NULL,TEXT("Engine.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_T052_GENUINE_469F_CORE_ENGINE_PACKAGE=%p bad=%d\n",pkg,bad);fflush(stdout);
 const TCHAR* targets[]={TEXT("TriggerPound"),TEXT("QuickSave"),TEXT("FormatFloat"),TEXT("TakeDamage"),TEXT("PreBeginPlay"),TEXT("CalcDrawOffset"),TEXT("GetShortAbsoluteTime"),TEXT("SpawnCopy"),TEXT("PlayerMove")};
 INT seen=0;
 if(pkg&&!bad)for(FObjectIterator It;It;++It){
   UObject* o=*It;
   if(!o||!(o->IsA(UFunction::StaticClass())||o->IsA(UState::StaticClass()))||!o->IsIn(pkg))continue;
   UStruct* f=(UStruct*)o;const TCHAR* n=*f->GetFName();
   UBOOL match=0;for(INT j=0;j<9;j++)if(appStricmp(n,targets[j])==0){match=1;break;}
   if(!match||f->Script.Num()<=0||f->Script.Num()>1600)continue;
   printf("G3_T052_GENUINE_469F_SCRIPT full=%ls len=%d native_hex=",f->GetFullName(),f->Script.Num());
   for(INT i=0;i<f->Script.Num();i++)printf("%02X",(unsigned)f->Script(i));
   printf("\n");
   // Original 469f compiled Core.dll relocates ULinker UObject references
   // to ACTUAL process UObject* in Script, unlike later C227k typed
   // ScriptObjects and ScriptNames arrays. Match genuine live pointer
   // identities through the loaded UObject global table, never blindly
   // dereference arbitrary four-byte script words.
   for(FObjectIterator Ti;Ti;++Ti){
     UObject* target=*Ti;
     if(!target)continue;
     DWORD addr=(DWORD)(UINT_PTR)target;
     for(INT offset=0;offset+4<=f->Script.Num();offset++){
       DWORD value=*(DWORD*)&f->Script(offset);
       if(value==addr)printf("G3_T052_GENUINE_469F_RUNTIME_POINTER fn=%ls offset=%d target=%ls\n",
          f->GetFullName(),offset,target->GetFullName());
     }
   }
   seen++;fflush(stdout);
 }
 printf("G3_T052_GENUINE_469F_NATIVE_SCRIPT_SAMPLES=%d\n",seen);fflush(stdout);
 appPreExit();appExit();return pkg&&!bad&&seen>0?0:7;
}
