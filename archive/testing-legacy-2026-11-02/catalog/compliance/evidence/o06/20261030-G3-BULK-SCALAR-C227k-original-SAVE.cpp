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
   UPackage* pkg=UObject::CreatePackage(NULL,TEXT("G3Scalar227Runtime"));
   UObject* obj=UObject::StaticConstructObject(c,pkg,FName(TEXT("ScalarObject")),RF_Public|RF_Standalone);
   printf("G3_SCALAR_NATIVE_OBJ %p\n",obj);
   if(obj){
    for(TFieldIterator<UProperty> It(c);It;++It){
      UProperty* p=*It;
      const TCHAR* name=*p->GetFName();
      if(p->IsA(UBoolProperty::StaticClass())&&
        (!appStricmp(name,TEXT("bAlpha")) ||!appStricmp(name,TEXT("bBeta")) ||
          !appStricmp(name,TEXT("bGamma")))){
       UBoolProperty* b=(UBoolProperty*)p;
       DWORD original=*(DWORD*)((BYTE*)def+p->Offset);
       printf("G3_SCALAR_BOOL name=%ls offset=%d mask=%08X cdoWord=%08X cdoValue=%d\n",
         name,p->Offset,(unsigned)b->BitMask,(unsigned)original,
         !!(original&b->BitMask));
       if(!appStricmp(name,TEXT("bBeta")))
        *(DWORD*)((BYTE*)obj+p->Offset) |= b->BitMask;
      }
      if(p->IsA(UFloatProperty::StaticClass())&&
        (!appStricmp(name,TEXT("FloatPos")) ||
         !appStricmp(name,TEXT("FloatNegative")) ||
         !appStricmp(name,TEXT("FloatMinusZero")) ||
         !appStricmp(name,TEXT("FloatFrac")))){
       DWORD word=*(DWORD*)((BYTE*)def+p->Offset);
       printf("G3_SCALAR_FLOAT name=%ls offset=%d cdoBits=%08X\n",name,p->Offset,(unsigned)word);
       if(!appStricmp(name,TEXT("FloatMinusZero"))){
         *(DWORD*)((BYTE*)obj+p->Offset)=0x80000000u;
         printf("G3_SCALAR_NATIVE_SET_NEGZERO bits=%08X\n",
           (unsigned)*(DWORD*)((BYTE*)obj+p->Offset));
       }
      }
      if(p->IsA(UStrProperty::StaticClass())&&
         !appStricmp(name,TEXT("EmptyText"))){
        FString* f=(FString*)((BYTE*)obj+p->Offset);
        *f=TEXT("B");
        printf("G3_SCALAR_NATIVE_STRING_OVERRIDE EmptyText=B\n");
      }
    }
    printf("G3_SCALAR_NATIVE_OBJECT_BEGIN\n");
    UObject::ExportProperties(out,c,(BYTE*)obj,0,NULL,NULL);
    printf("G3_SCALAR_NATIVE_OBJECT_END\n");
    saved=UObject::SavePackage(pkg,NULL,RF_Public|RF_Standalone,
      TEXT("C:\\Temp\\UnrealDB\\O06\\Native-227k\\System\\G3Scalar227Runtime.u"));
    printf("G3_SCALAR_NATIVE_SAVE %d\n",saved);
   }
  }
 }
 appPreExit();appExit();return saved?0:5;
}
