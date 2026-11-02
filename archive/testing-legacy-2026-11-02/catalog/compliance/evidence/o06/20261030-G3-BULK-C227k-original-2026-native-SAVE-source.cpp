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
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* loaded=NULL;
 try {loaded=UObject::LoadPackage(NULL,TEXT("G3Bulk227.u"),LOAD_NoFail);}
 catch(...) {bad=1;Error.HandleError();}
 printf("G3_BULK_227_PACKAGE_LOADED %p bad=%d\n",loaded,bad);
 UBOOL saved=0;
 if(loaded&&!bad) {
  UClass* c=NULL;
  for(FObjectIterator It;It;++It){
    UObject* o=*It;
    if(o && o->IsA(UClass::StaticClass()) &&
       appStrcmp(o->GetFullName(),TEXT("Class G3Bulk227.G3Bulk227Actor"))==0)
     {c=(UClass*)o;break;}
  }
  printf("G3_BULK_227_CLASS %p\n",c);
  if(c){
   Output out;
   UObject* def=c->GetDefaultObject();
   printf("G3_BULK_227_DEFAULT %p\n",def);
   if(def)UObject::ExportProperties(out,c,(BYTE*)def,0,NULL,NULL);
   UPackage* pkg=UObject::CreatePackage(NULL,TEXT("G3Bulk227Runtime"));
   UObject* obj=UObject::StaticConstructObject(c,pkg,FName(TEXT("ArrayObject")),RF_Public|RF_Standalone);
   printf("G3_BULK_227_OBJECT %p\n",obj);
   if(obj){
    for(TFieldIterator<UProperty> It(c);It;++It){
     UProperty* f=*It;
     if(!f->IsA(UArrayProperty::StaticClass()))continue;
     UArrayProperty* p=(UArrayProperty*)f;
     FArray* arr=(FArray*)((BYTE*)obj+p->Offset);
     if(appStricmp(*f->GetFName(),TEXT("Numbers"))==0){
      arr->Empty(sizeof(INT));arr->Add(3,sizeof(INT));
      INT* a=(INT*)arr->GetData();a[0]=11;a[1]=42;a[2]=-7;
      printf("G3_BULK_227_ARRAY Numbers 11,42,-7 num=%d size=%d\n",arr->Num(),p->Inner->ElementSize);
     } else if(appStricmp(*f->GetFName(),TEXT("Labels"))==0){
      arr->Empty(sizeof(FName));arr->Add(2,sizeof(FName));
      FName* a=(FName*)arr->GetData();a[0]=FName(TEXT("Alpha"));a[1]=FName(TEXT("Gamma"));
      printf("G3_BULK_227_ARRAY Labels Alpha,Gamma num=%d size=%d\n",arr->Num(),p->Inner->ElementSize);
     } else if(appStricmp(*f->GetFName(),TEXT("References"))==0){
      arr->Empty(sizeof(UObject*));arr->Add(2,sizeof(UObject*));
      UObject** a=(UObject**)arr->GetData();a[0]=obj;a[1]=NULL;
      printf("G3_BULK_227_ARRAY References self,None num=%d size=%d\n",arr->Num(),p->Inner->ElementSize);
     }
    }
    printf("G3_BULK_227_OBJECT_VALUES_BEGIN\n");
    UObject::ExportProperties(out,c,(BYTE*)obj,0,NULL,NULL);
    printf("G3_BULK_227_OBJECT_VALUES_END\n");
    saved=UObject::SavePackage(pkg,NULL,RF_Public|RF_Standalone,
     TEXT("C:\\Temp\\UnrealDB\\O06\\Native-227k\\System\\G3Bulk227Runtime.u"));
    printf("G3_BULK_227_NATIVE_SAVE %d\n",saved);
   }
  }
 }
 appPreExit();appExit();
 return saved?0:5;
}
