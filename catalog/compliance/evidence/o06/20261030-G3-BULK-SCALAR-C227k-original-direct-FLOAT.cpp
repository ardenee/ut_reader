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
   if(n>0)printf("G3_BULK_227_NATIVE_TAG %s\n",out);
 }
};
int main() {
 GIsStarted=1;GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* loaded=NULL;
 try{loaded=UObject::LoadPackage(NULL,TEXT("G3Scalar227.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_SCALAR_NATIVE_PACKAGE %p bad=%d\n",loaded,bad);
 UBOOL saved=0;
 if(loaded&&!bad){
  UClass* c=NULL;
  for(FObjectIterator It;It;++It){
   UObject* o=*It;
   if(o && o->IsA(UClass::StaticClass())&&
      appStrcmp(o->GetFullName(),TEXT("Class G3Scalar227.G3Scalar227Actor"))==0){c=(UClass*)o;break;}
  }
  printf("G3_SCALAR_NATIVE_CLASS %p\n",c);
  if(c){
   Output out;
   UObject* def=c->GetDefaultObject();
   printf("G3_SCALAR_NATIVE_CDO %p\n",def);
   if(def)UObject::ExportProperties(out,c,(BYTE*)def,0,NULL,NULL);
   if(def)for(TFieldIterator<UProperty> It(c);It;++It){
     UProperty* p=*It;const TCHAR* nm=*p->GetFName();
     if(!p->IsA(UFloatProperty::StaticClass()))continue;
     if(appStricmp(nm,TEXT("FloatMinusZero"))!=0&&
        appStricmp(nm,TEXT("FloatNegative"))!=0 &&
        appStricmp(nm,TEXT("FloatPos"))!=0)continue;
     DWORD bits=appStricmp(nm,TEXT("FloatMinusZero"))==0?0x80000000u:
       *(DWORD*)((BYTE*)def+p->Offset);
     FLOAT original=0;appMemcpy(&original,&bits,4);
     FBufferArchive archive;
     p->SerializeItem(archive,&original);
     FLOAT reloaded=888.f;FBufferReader reader(archive);
     p->SerializeItem(reader,&reloaded);
     DWORD reloadedBits=0;appMemcpy(&reloadedBits,&reloaded,4);
     DWORD writtenBits=0;if(archive.Num()==4)appMemcpy(&writtenBits,&archive(0),4);
     printf("G3_SCALAR_DIRECT_ORIGINAL_FLOAT name=%ls inputBits=%08X archiveLen=%d savedBits=%08X loadedBits=%08X readerPos=%d\\n",
       nm,(unsigned)bits,archive.Num(),(unsigned)writtenBits,(unsigned)reloadedBits,reader.Tell());
     if(archive.Num()!=4||reader.Tell()!=4||writtenBits!=bits||reloadedBits!=bits)
       bad=1;
   }

  }
 }
 appPreExit();appExit();return loaded&&!bad?0:5;
}
