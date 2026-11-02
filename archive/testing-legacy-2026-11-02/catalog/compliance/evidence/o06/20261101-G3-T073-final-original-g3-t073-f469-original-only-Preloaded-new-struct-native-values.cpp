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
    ULinkerLoad *EngineL=NULL,*CoreL=NULL;
    for(FObjectIterator It;It;++It) {
      UObject* O=*It;
      if(!O||!O->GetLinker())continue;
      const TCHAR* path=*(O->GetLinker()->Filename);
      if(appStrstr(path,TEXT("Engine.u")))EngineL=O->GetLinker();
      if(appStrstr(path,TEXT("Core.u")))CoreL=O->GetLinker();
    }
    printf("T073_NATIVE_BULK_ORIGINAL_PRE core=%p engine=%p\n",CoreL,EngineL);fflush(stdout);
    if(CoreL){printf("T073_NATIVE_BULK_CORE_BEGIN\n");fflush(stdout);CoreL->LoadAllObjects();printf("T073_NATIVE_BULK_CORE_DONE\n");fflush(stdout);}
    if(EngineL){printf("T073_NATIVE_BULK_ENGINE_BEGIN\n");fflush(stdout);EngineL->LoadAllObjects();printf("T073_NATIVE_BULK_ENGINE_DONE\n");fflush(stdout);}
    INT Full=0;
    for(FObjectIterator It;It;++It) {
      UObject* O=*It;
      if(!O||!O->GetLinker()||O->GetLinkerIndex()<0)continue;
      const TCHAR* path=*(O->GetLinker()->Filename);
      if(!appStrstr(path,TEXT("Core.u"))&&!appStrstr(path,TEXT("Engine.u")))continue;
      char File[4096],Kind[1024],Name[4096];
      if(WideCharToMultiByte(CP_UTF8,0,path,-1,File,sizeof(File),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,O->GetClass()->GetName(),-1,Kind,sizeof(Kind),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,Name,sizeof(Name),NULL,NULL)<=0)continue;
      printf("T073_ORIGINAL_BULK_MATERIALIZED file=%s idx=%d class=%s name=%s\n",File,(int)O->GetLinkerIndex(),Kind,Name);
      Full++;
    }
    printf("T073_ORIGINAL_BULK_MATERIALIZED_TOTAL=%d\n",Full);fflush(stdout);
    const INT CoreNewIds[]={7,10,329,630,653,654,662,665,667,727,728,734,756,761,765,769,770};
    const INT CoreNewCount=sizeof(CoreNewIds)/sizeof(INT);
    const INT EngineNewIds[]={-1};
    const INT EngineNewCount=sizeof(EngineNewIds)/sizeof(INT);
    int newSourceNative=0;
    for(FObjectIterator It;It;++It){
      UObject* O=*It;
      if(!O || !O->GetLinker() || O->GetLinkerIndex()<0 || !O->IsA(UStruct::StaticClass()))continue;
      const TCHAR* fn=*(O->GetLinker()->Filename);
      bool core=appStrstr(fn,TEXT("Core.u"))!=NULL,engine=appStrstr(fn,TEXT("Engine.u"))!=NULL;
      int idx=O->GetLinkerIndex(),allowed=0;
      if(core)for(INT q=0;q<CoreNewCount;q++)if(CoreNewIds[q]==idx)allowed=1;
      if(engine)for(INT q=0;q<EngineNewCount;q++)if(EngineNewIds[q]==idx)allowed=1;
      if(!allowed)continue;
      O->GetLinker()->Preload(O);
      UStruct* S=(UStruct*)O;
      int sup=S->SuperField?S->SuperField->GetLinkerIndex()+1:0;
      int next=S->Next?S->Next->GetLinkerIndex()+1:0;
      int child=S->Children?S->Children->GetLinkerIndex()+1:0;
      int text=S->ScriptText?S->ScriptText->GetLinkerIndex()+1:0;
      char cls[512],obj[4096];
      if(WideCharToMultiByte(CP_UTF8,0,O->GetClass()->GetName(),-1,cls,sizeof(cls),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,obj,sizeof(obj),NULL,NULL)<=0)continue;
      printf("T073_NEW_ACTUAL_NATIVE_STRUCT pkg=%s idx=%d type=%s line=%d textpos=%d script=%d super=%d next=%d children=%d text=%d object=%s\n",
      core?"Core.u":"Engine.u",idx,cls,(int)S->Line,(int)S->TextPos,(int)S->Script.Num(),sup,next,child,text,obj);
      newSourceNative++;
    }
    printf("T073_NEW_ACTUAL_NATIVE_STRUCT_COUNT=%d\n",newSourceNative);fflush(stdout);
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
