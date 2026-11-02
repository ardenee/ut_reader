// Distinct genuine original UT99 469f Core.dll LOAD of original May17 C98
// FObjectManager-written v61 .u. Exercises UT99 UFunction::Serialize Ar.Ver()<=63.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
#include "UnLinker.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G304469fV61OriginalLoad"); }
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
  UObject* stones=P?UObject::StaticFindObject(UObject::StaticClass(),P,TEXT("stones")):NULL;
  ULinkerLoad* L=stones?stones->GetLinker():NULL;
  if(!L && P) L=UObject::GetPackageLinker(P,NULL,0,NULL,NULL);
  printf("G3_T076_FIVE_SHIPPED_LOADER_POINTERS package=%p stones=%p linker=%p\n",P,stones,L);
  if(L){
   FPackageFileSummary& S=L->Summary;
   printf("G3_T076_FIVE_SHIPPED_REAL_SUMMARY version=%d file_size=%d exports=%d names=%d guid=%08X-%08X-%08X-%08X\n",
     (int)S.GetFileVersion(),(int)((FArchive*)L)->TotalSize(),(int)S.ExportCount,(int)S.NameCount,
     (unsigned)S.Guid.A,(unsigned)S.Guid.B,(unsigned)S.Guid.C,(unsigned)S.Guid.D);
   ok=1;
  }
 }catch(...){printf("G3_T076_FIVE_SHIPPED_REAL_FAILED\n");}
 printf("G3_T076_FIVE_SHIPPED_REAL_LOADED=%d\n",ok);fflush(stdout);
 appPreExit();appExit();return ok?0:7;
}
