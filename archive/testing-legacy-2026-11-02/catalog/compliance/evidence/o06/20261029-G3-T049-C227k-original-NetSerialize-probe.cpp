// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3Original227kT049Net"); }
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
      if(!O||!O->IsA(UArrayProperty::StaticClass())||!appStrstr(O->GetFullName(),TEXT(" Engine.")))continue;
      UArrayProperty* P=(UArrayProperty*)O;
      if(!P->Inner||!P->Inner->IsA(UIntProperty::StaticClass()))continue;
      char Name[4096];
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,Name,sizeof(Name),NULL,NULL)<=0)continue;
      for(INT Case=0;Case<3;Case++) {
        TArray<INT> V;
        if(Case>=1)V.AddItem(11);
        if(Case>=2)V.AddItem(-7);
        FBufferArchive Writer;
        UBOOL Res=P->NetSerializeItem(Writer,NULL,&V);
        printf("G3_T049_ORIGINAL_227K_INT_NET name=%s|case=%d|result=%d|output_size=%d|output_hex=",
          Name,(int)Case,(int)Res,(int)Writer.Num());
        for(INT j=0;j<Writer.Num();j++)printf("%02X",(unsigned)Writer(j));
        printf("\n");
      }
      Observed++;
      if(Observed>=8)break;
    }
    printf("G5_NATIVE_OBJECT_COUNT %d\n",Observed);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
