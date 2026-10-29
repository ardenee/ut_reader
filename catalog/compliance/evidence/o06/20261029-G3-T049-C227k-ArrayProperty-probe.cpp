// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3Original227kT049V2"); }
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
      if(!O||!O->IsA(UArrayProperty::StaticClass()))continue;
      if(!appStrstr(O->GetFullName(),TEXT(" Engine.")))continue;
      UArrayProperty* P=(UArrayProperty*)O;
      // Actual compiled 227k indexing is FArray[slot] (12-byte stride),
      // and recursively checks Inner->ShouldSerializeItem(Array.Data,index).
      // Restrict to genuine object-reference arrays, where our real UObject*
      // values have the correct native element size.
      if(!P->Inner||!P->Inner->IsA(UObjectProperty::StaticClass()))continue;
      TArray<UObject*> Values[2];
      Values[0].AddItem(NULL);
      Values[1].AddItem(O);
      UBOOL zero=P->ShouldSerializeItem((const BYTE*)Values,0);
      UBOOL nonnull=P->ShouldSerializeItem((const BYTE*)Values,1);
      char FullName[4096];
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,FullName,sizeof(FullName),NULL,NULL)<=0)continue;
      printf("G3_T049_ORIGINAL_227K_ARRAY_SHOULD_SERIALIZE name=%s null=%d nonnull=%d innerKind=%d num=%d\n",
         FullName,(int)zero,(int)nonnull,P->Inner?(int)P->Inner->GetID():-1,(int)Values[0].Num());
      Observed++;
      if(Observed>=16)break;
    }
    printf("G5_NATIVE_OBJECT_COUNT %d\n",Observed);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
