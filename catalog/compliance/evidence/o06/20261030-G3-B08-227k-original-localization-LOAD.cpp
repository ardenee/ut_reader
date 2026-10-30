// One grouped 2012-original-compiled C227i package -> original 2026 C227k compiled loader
// and genuine C227k native SavePackage oracle. Canonical Epic binaries/source untouched.
#include <windows.h>
#include <malloc.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3MultiEraOriginalArray"); }
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
class Output : public FOutputDevice {
public:
 void Serialize(const TCHAR* Msg, enum EName Event) {
   char out[65536];
   int n=WideCharToMultiByte(CP_UTF8,0,Msg,-1,out,sizeof(out),NULL,NULL);
   if(n>0)printf("G3_B08_227_NATIVE_TAG %s\n",out);
 }
};
int main() {
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* cls=NULL;UObject* runtime=NULL;
 try {cls=UObject::LoadPackage(NULL,TEXT("G3Config227.u"),LOAD_NoFail);
      runtime=UObject::LoadPackage(NULL,TEXT("G3Config227Runtime.u"),LOAD_NoFail);}
 catch(...) {bad=1;Error.HandleError();}
 printf("G3_B08_227_INDEPENDENT_RELOAD cls=%p runtime=%p bad=%d\n",cls,runtime,bad);
 if(cls && runtime && !bad) {
  Output out;
  INT seen=0;
  for(FObjectIterator It; It; ++It) {
   UObject* o=*It;
   if(!o || appStrcmp(o->GetFullName(),
      TEXT("G3Config227Actor G3Config227Runtime.ConfigObject"))!=0)continue;
   printf("G3_B08_227_RELOADED_OBJECT %p\n",o);
   UClass* realClass=o->GetClass();
   printf("G3_B08_NATIVE_LOCALIZED_CLASS_FLAGS=%08X\n",(unsigned)realClass->ClassFlags);
   UObject* cdo=realClass->GetDefaultObject();
   for(TFieldIterator<UProperty> It(realClass);It;++It){
    UProperty* p=*It;
    if(appStricmp(*p->GetFName(),TEXT("LocalMessage"))!=0)continue;
    FString* nativeField=(FString*)((BYTE*)cdo+p->Offset);
    printf("G3_B08_NATIVE_LOCALIZED_BEFORE cdoValue=%ls propFlags=%08X\n",
      **nativeField,(unsigned)p->PropertyFlags);
    GIsEditor=0;
    cdo->LoadLocalized(0,realClass);
    GIsEditor=1;
    printf("G3_B08_NATIVE_LOCALIZED_AFTER cdoValue=%ls\n",**nativeField);
   }
   UObject::ExportProperties(out,o->GetClass(),(BYTE*)o,0,NULL,NULL);
   for(TFieldIterator<UProperty> It(o->GetClass());It;++It){
      UProperty* prop=*It;const TCHAR* nm=*prop->GetFName();
      if(prop->IsA(UIntProperty::StaticClass()) &&
        (!appStricmp(nm,TEXT("ScratchValue")) ||
         !appStricmp(nm,TEXT("PersistValue"))||
         !appStricmp(nm,TEXT("ConfigValue")))){
       printf("G3_B08_NATIVE_RELOADED name=%ls flags=%08X offset=%d value=%d\\n",
         nm,(unsigned)prop->PropertyFlags,prop->Offset,
         *(INT*)((BYTE*)o+prop->Offset));
      }
   }

   seen++;
  }
  printf("G3_B08_227_RELOADED_COUNT %d\n",seen);
  if(seen!=1) bad=1;
 }
 appPreExit();appExit();
 return runtime&&!bad?0:5;
}
