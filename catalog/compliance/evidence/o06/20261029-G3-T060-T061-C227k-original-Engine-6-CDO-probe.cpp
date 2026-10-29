// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3Original227kBatchCDO"); }
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
class G5OutputProperties : public FOutputDevice {
public:
  void Serialize(const TCHAR* Message, enum EName Event) {
    char U8[65536];
    int size=WideCharToMultiByte(CP_UTF8,0,Message,-1,U8,sizeof(U8),NULL,NULL);
    if(size>0)printf("G5_NATIVE_TAG_VALUE %s\n",U8);
  }
};
int main(int argc,char** argv) {
  GIsStarted=1;
  GIsGuarded=1;
  TCHAR CmdLine[64]=TEXT("");
  appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
  UObject::SetLanguage(TEXT("int"));
  GIsClient=0;
  GIsServer=0;
  GIsEditor=1;
  GIsScriptable=1;
  GLazyLoad=0;
  printf("G5_NATIVE_DIRECT_PACKAGE_BEGIN editor=%d client=%d server=%d\n",GIsEditor,GIsClient,GIsServer);
  UObject* Result=NULL;
  UBOOL NativeError=0;
  try { Result=UObject::LoadPackage(NULL,TEXT("Engine.u"),LOAD_NoFail); }
  catch(...) { NativeError=1; Error.HandleError(); }
  printf("G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=%p editor=%d native_error=%d\n",Result,GIsEditor,NativeError);
  if(Result && !NativeError) {
    G5OutputProperties Out;
    INT Observed=0;
    for(FObjectIterator It; It; ++It) {
      UObject* O=*It;
      if(!O || !O->IsA(UClass::StaticClass()))continue;
      const TCHAR* Name=O->GetFullName();
      if(appStrcmp(Name,TEXT("Class Engine.Actor")) &&
         appStrcmp(Name,TEXT("Class Engine.Pawn")) &&
         appStrcmp(Name,TEXT("Class Engine.HUD")) &&
         appStrcmp(Name,TEXT("Class Engine.GameInfo")) &&
         appStrcmp(Name,TEXT("Class Engine.PlayerPawn")) &&
         appStrcmp(Name,TEXT("Class Engine.LevelInfo")))continue;
      UClass* C=(UClass*)O;
      UObject* Default=C->GetDefaultObject();
      char FullName[4096];
      if(WideCharToMultiByte(CP_UTF8,0,Name,-1,FullName,sizeof(FullName),NULL,NULL)<=0)continue;
      printf("G3_T060_ORIGINAL_227K_CDO_BEGIN %s|defaultPtr=%p\n",FullName,Default);
      if(Default) UObject::ExportProperties(Out,C,(BYTE*)Default,0,NULL,NULL);
      printf("G3_T060_ORIGINAL_227K_CDO_END %s\n",FullName);
      Observed++;
    }
    printf("G5_NATIVE_OBJECT_COUNT %d\n",Observed);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
