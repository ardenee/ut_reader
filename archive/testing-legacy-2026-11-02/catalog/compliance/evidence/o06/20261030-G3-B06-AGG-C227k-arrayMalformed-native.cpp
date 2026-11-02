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
   if(n>0)printf("G3_B06_227_NATIVE_TAG %s\n",out);
 }
};
int main(int argc,char** argv) {
 setvbuf(stdout,NULL,_IONBF,0);
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* cls=NULL;UObject* runtime=NULL;
 try {cls=UObject::LoadPackage(NULL,TEXT("G3Array227.u"),LOAD_NoFail);
      TCHAR packageFilename[512]=TEXT("G3Array227Runtime.u");
      if(argc>1)MultiByteToWideChar(CP_ACP,0,argv[1],-1,packageFilename,512);
      printf("G3_B06_NEGATIVE_ORIGINAL_BEGIN package=%ls\n",packageFilename);
      runtime=UObject::LoadPackage(NULL,packageFilename,LOAD_NoFail);}
 catch(...) {bad=1;Error.HandleError();}
 printf("G3_B06_227_INDEPENDENT_RELOAD cls=%p runtime=%p bad=%d\n",cls,runtime,bad);
 if(cls && runtime && !bad) {
  Output out;
  UClass* nativeClass=UObject::StaticLoadClass(UObject::StaticClass(),NULL,
    TEXT("G3Array227.G3Array227Actor"),NULL,LOAD_NoFail,NULL);
  if(nativeClass){
    UObject* cdo=nativeClass->GetDefaultObject();
    for(TFieldIterator<UProperty> Pt(nativeClass);Pt;++Pt){
      UProperty* prop=*Pt;
      if(!appStricmp(*prop->GetFName(),TEXT("Strings")) && prop->IsA(UArrayProperty::StaticClass())){
        UArrayProperty* arrProp=(UArrayProperty*)prop;
        FArray* arr=(FArray*)((BYTE*)cdo+prop->Offset);
        printf("G3_B06_NATIVE_CDO_STRINGS count=%d innerSize=%d\\n",arr->Num(),arrProp->Inner->ElementSize);
        FString* v=(FString*)arr->GetData();
        for(INT j=0;j<arr->Num();j++){
         printf("G3_B06_NATIVE_CDO_STRING index=%d text=%ls len=%d\\n",j,*v[j],v[j].Len());
        }
      }
    }
  }
  INT seen=0;
  for(FObjectIterator It; It; ++It) {
   UObject* o=*It;
   if(!o || !appStrstr(o->GetFullName(),TEXT("G3Array227Actor G3Array227Runtime")) || !appStrstr(o->GetFullName(),TEXT(".ArrayObject")))continue;
   printf("G3_B06_227_RELOADED_OBJECT %p\n",o);
   UObject::ExportProperties(out,o->GetClass(),(BYTE*)o,0,NULL,NULL);
   for(TFieldIterator<UProperty> It(o->GetClass());It;++It){
     UProperty* p=*It;
     if(!p->IsA(UArrayProperty::StaticClass()))continue;
     UArrayProperty* ap=(UArrayProperty*)p;
     FArray* arr=(FArray*)((BYTE*)o+p->Offset);
     printf("G3_B06_NATIVE_RELOADED_ARRAY name=%ls count=%d innerSize=%d\n",*p->GetFName(),arr->Num(),ap->Inner->ElementSize);
     if(!appStricmp(*p->GetFName(),TEXT("Pairs"))&&
       ap->Inner->IsA(UStructProperty::StaticClass())){
       UStruct* structType=((UStructProperty*)ap->Inner)->Struct;
       for(INT j=0;j<arr->Num();j++){
        for(TFieldIterator<UProperty> F(structType);F;++F){
         UProperty* q=*F;BYTE* base=(BYTE*)arr->GetData()+j*ap->Inner->ElementSize+q->Offset;
         if(!appStricmp(*q->GetFName(),TEXT("Number")))
           printf("G3_B06_NATIVE_RELOADED_PAIR index=%d field=Number value=%d\n",j,*(INT*)base);
         if(!appStricmp(*q->GetFName(),TEXT("Fraction")))
           printf("G3_B06_NATIVE_RELOADED_PAIR index=%d field=Fraction value=%f\n",j,*(FLOAT*)base);
         if(!appStricmp(*q->GetFName(),TEXT("Label")))
           printf("G3_B06_NATIVE_RELOADED_PAIR index=%d field=Label value=%ls\n",j,**(FName*)base);
        }
       }
     }else if(!appStricmp(*p->GetFName(),TEXT("Strings"))){
       FString* arrValues=(FString*)arr->GetData();
       for(INT j=0;j<arr->Num();j++)
        printf("G3_B06_NATIVE_RELOADED_STRING index=%d text=%ls len=%d\n",j,*arrValues[j],arrValues[j].Len());
     }
   }

   seen++;
  }
  printf("G3_B06_227_RELOADED_COUNT %d\n",seen);
  if(seen!=1) bad=1;
 }
 appPreExit();appExit();
 return runtime&&!bad?0:5;
}
