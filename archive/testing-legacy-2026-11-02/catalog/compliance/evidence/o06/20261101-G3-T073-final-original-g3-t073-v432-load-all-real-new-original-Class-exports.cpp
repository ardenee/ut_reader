// Disposable compiled engine oracle, UT99 v432 binary-linked Core.
// Runtime and initialization follow original v400 UCC.cpp, corrected by actual
// compiled v432 export API; source engine files and original archive untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
#include "UnLinker.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { TCHAR GPackage[]=TEXT("G3T075v432NewLinkedElementSizes"); }
#include "FOutputDeviceFile.h"
FOutputDeviceFile Log;
#include "FOutputDeviceAnsiError.h"
FOutputDeviceAnsiError Error;
#include "FFeedbackContextAnsi.h"
FFeedbackContextAnsi Warn;
#define i I
#include "FFileManagerWindows.h"
#undef i
FFileManagerWindows FileManager;
#define _HEAPBADBEGIN (-1)
#define _HEAPBADNODE (-2)
#define _HEAPBADPTR (-3)
#define _HEAPEMPTY (-4)
#define _HEAPOK 0
#define _heapchk() _HEAPOK
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

    const TCHAR* Targets[]={
      TEXT("Core.Subsystem"),
      TEXT("Core.Time"),
      TEXT("Core.Locale"),
      TEXT("Core.HelloWorldCommandlet"),
      TEXT("Core.SimpleCommandlet"),
      TEXT("Engine.TestInfo"),
      TEXT("Engine.LiftExit"),
      TEXT("Engine.InterpolationPoint"),
      TEXT("Engine.WarpZoneInfo"),
      TEXT("Engine.TestObj"),
      TEXT("Engine.Light"),
      TEXT("Engine.ZoneTrigger"),
      TEXT("Engine.PatrolPoint"),
      TEXT("Engine.MessagingSpectator"),
      TEXT("Engine.Effects"),
      TEXT("Engine.Engine"),
      TEXT("Engine.TriggerLight"),
      TEXT("Engine.Spotlight"),
      TEXT("Engine.SpecialEvent"),
      TEXT("Engine.ScaledSprite"),
      TEXT("Engine.RoundRobin"),
      TEXT("Engine.MusicEvent"),
      TEXT("Engine.HomeBase"),
      TEXT("Engine.Dispatcher"),
      TEXT("Engine.DemoRecSpectator"),
      TEXT("Engine.DamageType"),
      TEXT("Engine.BlockPlayer"),
      TEXT("Engine.BlockMonsters"),
      TEXT("Engine.BlockAll"),
      TEXT("Engine.Ambushpoint"),
      TEXT("Engine.AmbientSound"),
      TEXT("Engine.Camera"),
      TEXT("Engine.PathNode"),
      TEXT("Engine.Scout"),
      TEXT("Engine.TriggerMarker"),
      TEXT("Engine.ButtonMarker"),
      TEXT("Engine.WarpZoneMarker"),
      TEXT("Engine.LiftCenter"),
      TEXT("Engine.InternetInfo"),
      TEXT("Engine.ClipMarker")
    };
    int requested=0,success=0;
    for(unsigned int z=0;z<sizeof(Targets)/sizeof(Targets[0]);z++){
      printf("T073_V432_LOAD_CLASS_REQUEST name=%u\n",z);fflush(stdout);
      try {
        UClass* C=UObject::StaticLoadClass(UObject::StaticClass(),NULL,Targets[z],NULL,LOAD_NoWarn,NULL);
        printf("T073_V432_LOAD_CLASS_RESULT ordinal=%u ptr=%p\n",z,C);fflush(stdout);
        if(C)success++;
      }catch(...){printf("T073_V432_LOAD_CLASS_EXCEPTION ordinal=%u\n",z);fflush(stdout);}
      requested++;
    }
    printf("T073_V432_LOAD_CLASS_COUNTS requested=%d loaded=%d\n",requested,success);fflush(stdout);
    INT loadedCount=0;
    for(FObjectIterator It;It;++It){
      UObject* O=*It;
      if(!O||!O->GetLinker()||O->GetLinkerIndex()<0)continue;
      const TCHAR* File=*(O->GetLinker()->Filename);
      if(!appStrstr(File,TEXT("Core.u"))&&!appStrstr(File,TEXT("Engine.u")))continue;
      char fname[3000],cname[256],name[2048];
      if(WideCharToMultiByte(CP_UTF8,0,File,-1,fname,sizeof(fname),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,O->GetClass()->GetName(),-1,cname,sizeof(cname),NULL,NULL)<=0)continue;
      if(WideCharToMultiByte(CP_UTF8,0,O->GetFullName(),-1,name,sizeof(name),NULL,NULL)<=0)continue;
      printf("T073_V432_NATIVE_LOADED_ALL file=%s idx=%d type=%s name=%s\n",fname,(int)O->GetLinkerIndex(),cname,name);
      loadedCount++;
    }
    printf("T073_V432_NATIVE_LOADED_ALL_TOTAL=%d\n",loadedCount);fflush(stdout);
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
