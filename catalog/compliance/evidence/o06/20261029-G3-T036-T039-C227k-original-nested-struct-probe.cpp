// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3Original227kNativeNested"); }
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
      if(appStrcmp(Name,TEXT("Class Engine.Pawn"))&&
         appStrcmp(Name,TEXT("Class Engine.PlayerPawn"))&&
         appStrcmp(Name,TEXT("Class Engine.Actor"))&&
         appStrcmp(Name,TEXT("Class Engine.HUD")))continue;
      UClass* C=(UClass*)O;
      UObject* Default=C->GetDefaultObject();
      if(!Default)continue;
      char FullName[4096];
      if(WideCharToMultiByte(CP_UTF8,0,Name,-1,FullName,sizeof(FullName),NULL,NULL)<=0)continue;
      int StructCount=0;
      for(UProperty* P=C->PropertyLink;P;P=P->PropertyLinkNext) {
        if(!P->IsA(UStructProperty::StaticClass()))continue;
        UStructProperty* SP=(UStructProperty*)P;
        if(!SP->Struct)continue;
        const TCHAR* PN=P->GetName();
        int IsRotationRate=appStrcmp(PN,TEXT("RotationRate"))==0;
        UStruct* Super=SP->Struct->GetSuperStruct();
        if(!IsRotationRate && !Super)continue;
        char Property[1024],Imported[4096];
        if(WideCharToMultiByte(CP_UTF8,0,PN,-1,Property,sizeof(Property),NULL,NULL)<=0)continue;
        if(WideCharToMultiByte(CP_UTF8,0,SP->Struct->GetFullName(),-1,Imported,sizeof(Imported),NULL,NULL)<=0)continue;
        printf("G3_T036_T039_NATIVE_STRUCT class=%s|property=%s|struct=%s|offset=%d|array_dim=%d|element_size=%d|super=%d\n",
          FullName,Property,Imported,(int)SP->Offset,(int)SP->ArrayDim,(int)SP->ElementSize,Super?1:0);
        for(UProperty* Child=SP->Struct->PropertyLink;Child;Child=Child->PropertyLinkNext) {
          char ChildName[1024];
          if(WideCharToMultiByte(CP_UTF8,0,Child->GetName(),-1,ChildName,sizeof(ChildName),NULL,NULL)<=0)continue;
          printf("G3_T036_T039_NATIVE_MEMBER class=%s|property=%s|member=%s|offset=%d|array_dim=%d|element_size=%d",
             FullName,Property,ChildName,(int)Child->Offset,(int)Child->ArrayDim,(int)Child->ElementSize);
          if(IsRotationRate && Child->IsA(UIntProperty::StaticClass()) && SP->ArrayDim==1 &&
             Child->Offset+4<=SP->ElementSize) {
            int Value=*(INT*)((BYTE*)Default+SP->Offset+Child->Offset);
            printf("|value=%d",Value);
          }
          printf("\n");
        }
        StructCount++;
        Observed++;
      }
      printf("G3_T036_T039_NATIVE_CLASS_STRUCTS class=%s|sampled=%d\n",FullName,StructCount);
    }
    printf("G5_NATIVE_OBJECT_COUNT %d\n",Observed);
  }
  appPreExit();
  appExit();
  return Result?0:3;
}
