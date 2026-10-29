// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3Original227kCore"); }
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
  try { Result=UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail); }
  catch(...) { NativeError=1; Error.HandleError(); }
  printf("G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=%p editor=%d native_error=%d\n",Result,GIsEditor,NativeError);
  if(Result && !NativeError) {
    G5OutputProperties Out;
    INT Observed=0;
    for(FObjectIterator It; It; ++It) {
      UObject* O=*It;
      if(!O||!O->IsA(UClass::StaticClass())||!appStrstr(O->GetFullName(),TEXT("Class Core.")))continue;
      UClass* C=(UClass*)O;
      char FullName[4096],Within[4096],Config[4096];
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,FullName,sizeof(FullName),NULL,NULL)<=0)continue;
      const TCHAR* W=C->ClassWithin?C->ClassWithin->GetFullName():TEXT("None");
      const TCHAR* N=*C->ClassConfigName;
      if(WideCharToMultiByte(CP_UTF8,0,W,-1,Within,sizeof(Within),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,N,-1,Config,sizeof(Config),NULL,NULL)<=0)continue;
      printf("G3_BATCH_NATIVE_C227K_CORE_CLASS %s|flags=%u|within=%s|config=%s|guid=%08X%08X%08X%08X|deps=%d|imports=%d\n",
         FullName,(unsigned)C->ClassFlags,Within,Config,
         (unsigned)C->ClassGuid.A,(unsigned)C->ClassGuid.B,(unsigned)C->ClassGuid.C,(unsigned)C->ClassGuid.D,
         (int)C->Dependencies.Num(),(int)C->PackageImports.Num());
      Observed++;
    }
    printf("G5_NATIVE_OBJECT_COUNT %d\n",Observed);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
