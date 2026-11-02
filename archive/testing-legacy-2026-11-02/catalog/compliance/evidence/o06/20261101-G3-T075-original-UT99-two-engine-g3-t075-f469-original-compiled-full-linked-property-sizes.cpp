// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
#include "UnLinker.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3T075f469NewLinkedElementSizes"); }
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
  try { Result=UObject::LoadPackage(NULL,TEXT("Engine.u"),LOAD_NoFail); }
  catch(...) { NativeError=1; Error.HandleError(); }
  printf("G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=%p editor=%d native_error=%d\n",Result,GIsEditor,NativeError);
  if(Result && !NativeError) {
    INT Count=0;
    for(FObjectIterator It; It; ++It) {
      UObject* O=*It;
      if(!O || !O->IsA(UProperty::StaticClass()))continue;
      UProperty* P=(UProperty*)O;
      if(!P->GetLinker() || P->GetLinkerIndex()<0)continue;
      char File[4096],Kind[1024],FullName[4096];
      const TCHAR* Pth=*(P->GetLinker()->Filename);
      if(WideCharToMultiByte(CP_UTF8,0,Pth,-1,File,sizeof(File),NULL,NULL)<=0)continue;
      if(!strstr(File,"Core.u")&&!strstr(File,"Engine.u"))continue;
      if(WideCharToMultiByte(CP_UTF8,0,P->GetClass()->GetName(),-1,Kind,sizeof(Kind),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,P->GetFullName(),-1,FullName,sizeof(FullName),NULL,NULL)<=0)continue;
      printf("T075_NEW_ORIGINAL_NATIVE_PROPERTY file=%s idx=%d type=%s element=%d dim=%d linked_size=%d offset=%d name=%s\n",
        File,(int)P->GetLinkerIndex(),Kind,(int)P->ElementSize,(int)P->ArrayDim,
        (int)P->GetSize(),(int)P->Offset,FullName);
      Count++;
    }
    printf("T075_NEW_ORIGINAL_NATIVE_LINKED_PROPERTY_COUNT=%d\n",(int)Count);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
