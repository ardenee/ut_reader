// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[64]=TEXT("G5ObjectNative"); }
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
// Disposable only: header uses INT I / lower-case i with modern MSVC.
#define i I
#include "FFileManagerWindows.h"
#undef i
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
  appInit(TEXT("UnrealTournament"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
  UObject::SetLanguage(TEXT("int"));
  GIsClient=0;
  GIsServer=0;
  GIsEditor=1;
  GIsScriptable=1;
  GLazyLoad=0;
  printf("G5_NATIVE_DIRECT_PACKAGE_BEGIN editor=%d client=%d server=%d\n",GIsEditor,GIsClient,GIsServer);
  UObject* Result=NULL;
  UBOOL NativeError=0;
  try { Result=UObject::LoadPackage(NULL,TEXT("G5ArrayPayload.u"),LOAD_NoFail); }
  catch(...) { NativeError=1; Error.HandleError(); }
  printf("G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=%p editor=%d native_error=%d\n",Result,GIsEditor,NativeError);
  if(Result && !NativeError) {
    UClass* cl=UObject::StaticLoadClass(UObject::StaticClass(),NULL,TEXT("G5ArrayProbe.G5ArrayProbeActor"),NULL,LOAD_NoFail,NULL);
    printf("G3_T049_ORIGINAL_V432_SOURCE_CLASS %p\n",cl);
    UArrayProperty* array=NULL;
    if(cl)for(TFieldIterator<UProperty> It(cl);It;++It){
      if(!appStricmp(*It->GetFName(),TEXT("Numbers"))&&It->IsA(UArrayProperty::StaticClass()))
        array=(UArrayProperty*)*It;
    }
    printf("G3_T049_ORIGINAL_V432_REAL_ARRAY %p inner=%p\n",array,array?array->Inner:NULL);
    if(array && array->Inner && array->Inner->IsA(UIntProperty::StaticClass())){
      for(INT test=0;test<3;test++){
        TArray<INT> real;
        if(test>0)real.AddItem(11);
        if(test>1)real.AddItem(-7);
        FBufferArchive writer;
        UBOOL result=array->NetSerializeItem(writer,NULL,&real);
        printf("G3_T049_ORIGINAL_V432_NET case=%d result=%d nativeCount=%d bytes=%d hex=",test,(int)result,real.Num(),writer.Num());
        for(INT i=0;i<writer.Num();i++)printf("%02X",(unsigned)writer(i));
        printf("\n");
        if(result!=1||writer.Num()!=0)NativeError=1;
      }
      printf("G3_T049_ORIGINAL_V432_NET_ALL_PASS=%d\n",(int)!NativeError);
    }else NativeError=1;

  }
  appPreExit();
  appExit();
  return Result&&!NativeError?0:3;
}
