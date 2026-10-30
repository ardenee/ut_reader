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
   if(n>0)printf("G3_SCALAR_227_NATIVE_TAG %s\n",out);
 }
};
int main() {
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* cls=NULL;UObject* runtime=NULL;
 try {cls=UObject::LoadPackage(NULL,TEXT("G3Scalar227.u"),LOAD_NoFail);
      runtime=UObject::LoadPackage(NULL,TEXT("G3Scalar227Runtime.u"),LOAD_NoFail);}
 catch(...) {bad=1;Error.HandleError();}
 printf("G3_SCALAR_227_INDEPENDENT_RELOAD cls=%p runtime=%p bad=%d\n",cls,runtime,bad);
 if(cls && runtime && !bad) {
  Output out;
  INT seen=0;
  for(FObjectIterator It; It; ++It) {
   UObject* o=*It;
   if(!o || appStrcmp(o->GetFullName(),
      TEXT("G3Scalar227Actor G3Scalar227Runtime.ScalarObject"))!=0)continue;
   printf("G3_SCALAR_227_RELOADED_OBJECT %p\n",o);
   UObject::ExportProperties(out,o->GetClass(),(BYTE*)o,0,NULL,NULL);
   for(TFieldIterator<UProperty> It(o->GetClass());It;++It){
     UProperty* p=*It;
     const TCHAR* nm=*p->GetFName();
     if(p->IsA(UBoolProperty::StaticClass())&&
       (!appStricmp(nm,TEXT("bAlpha"))||!appStricmp(nm,TEXT("bBeta"))||!appStricmp(nm,TEXT("bGamma")))){
       UBoolProperty* b=(UBoolProperty*)p;
       DWORD word=*(DWORD*)((BYTE*)o+p->Offset);
       printf("G3_SCALAR_227_RELOAD_BOOL name=%ls mask=%08X word=%08X loaded=%d\n",
         nm,(unsigned)b->BitMask,(unsigned)word,!!(word&b->BitMask));
     }
     if(p->IsA(UFloatProperty::StaticClass())&&
        !appStricmp(nm,TEXT("FloatMinusZero")))
       printf("G3_SCALAR_227_RELOAD_FLOAT_NEGZERO_WORD %08X\n",
        (unsigned)*(DWORD*)((BYTE*)o+p->Offset));
   }

   seen++;
  }
  printf("G3_SCALAR_227_RELOADED_COUNT %d\n",seen);
  if(seen!=1) bad=1;
 }
 appPreExit();appExit();
 return runtime&&!bad?0:5;
}
