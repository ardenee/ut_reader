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
 setvbuf(stdout,NULL,_IONBF,0);
 GIsStarted=1; GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* loaded=NULL;
 try {loaded=UObject::LoadPackage(NULL,TEXT("G3Array227.u"),LOAD_NoFail);}
 catch(...) {bad=1;Error.HandleError();}
 printf("G3_BULK_227_PACKAGE_LOADED %p bad=%d\n",loaded,bad);
 UBOOL saved=0;
 if(loaded&&!bad) {
  UClass* c=NULL;
  for(FObjectIterator It;It;++It){
    UObject* o=*It;
    if(o && o->IsA(UClass::StaticClass()) &&
       appStrcmp(o->GetFullName(),TEXT("Class G3Array227.G3Array227Actor"))==0)
     {c=(UClass*)o;break;}
  }
  printf("G3_BULK_227_CLASS %p\n",c);
  if(c){
   Output out;
   UObject* def=c->GetDefaultObject();
   printf("G3_BULK_227_DEFAULT %p\n",def);
   if(def)UObject::ExportProperties(out,c,(BYTE*)def,0,NULL,NULL);
   UPackage* pkg=UObject::CreatePackage(NULL,TEXT("G3Array227Runtime"));
   UObject* obj=UObject::StaticConstructObject(c,pkg,FName(TEXT("ArrayObject")),RF_Public|RF_Standalone);
   printf("G3_BULK_227_OBJECT %p\n",obj);
   if(obj){
    for(TFieldIterator<UProperty> It(c);It;++It){
      UProperty* f=*It;
      if(f->IsA(UArrayProperty::StaticClass())){
        UArrayProperty* p=(UArrayProperty*)f;
        FArray* arr=(FArray*)((BYTE*)obj+p->Offset);
        if(!appStricmp(*f->GetFName(),TEXT("Pairs"))){
           printf("G3_B06_ORIGINAL_PAIRS class_default_count=%d actual_count=%d inner_bytes=%d type=%ls\n",
             ((FArray*)((BYTE*)def+p->Offset))->Num(),arr->Num(),p->Inner->ElementSize,
             *p->Inner->GetClass()->GetFName());
           UStruct* stru=((UStructProperty*)p->Inner)->Struct;
           for(INT j=0;j<arr->Num();j++){
              for(TFieldIterator<UProperty> field(stru);field;++field){
                UProperty* x=*field;BYTE* v=(BYTE*)arr->GetData()+j*p->Inner->ElementSize+x->Offset;
                if(!appStricmp(*x->GetFName(),TEXT("Number"))) {
                  INT original=*(INT*)v;*(INT*)v=(j==0?101:-202);
                  printf("G3_B06_ORIGINAL_STRUCT pair=%d field=Number default=%d updated=%d\n",j,original,*(INT*)v);
                }
                if(!appStricmp(*x->GetFName(),TEXT("Fraction"))) {
                  FLOAT original=*(FLOAT*)v;*(FLOAT*)v=(j==0?1.25f:-2.75f);
                  printf("G3_B06_ORIGINAL_STRUCT pair=%d field=Fraction default=%f updated=%f\n",j,original,*(FLOAT*)v);
                }
                if(!appStricmp(*x->GetFName(),TEXT("Label"))) {
                  FName original=*(FName*)v;*(FName*)v=FName(j==0?TEXT("Alpha"):TEXT("Gamma"));
                  printf("G3_B06_ORIGINAL_STRUCT pair=%d field=Label default=%ls updated=%ls\n",j,*original,*(*(FName*)v));
                }
              }
           }
        }else if(!appStricmp(*f->GetFName(),TEXT("Strings"))){
          printf("G3_B06_ORIGINAL_STRINGS class_default_count=%d actual_count=%d inner_bytes=%d\n",
            ((FArray*)((BYTE*)def+p->Offset))->Num(),arr->Num(),p->Inner->ElementSize);
          FString* v=(FString*)arr->GetData();
          if(arr->Num()>=2) {
             v[0]=TEXT("Alpha-227");
             v[1]=TEXT("Gamma-227");
             printf("G3_B06_ORIGINAL_STRING value0=%ls value1=%ls\n",*v[0],*v[1]);
          }
        }
      }else if(f->ArrayDim==2 &&
        (!appStricmp(*f->GetFName(),TEXT("StaticInts"))||
         !appStricmp(*f->GetFName(),TEXT("StaticNames")))){
        printf("G3_B06_ORIGINAL_STATIC_ARRAY property=%ls property_class=%ls arraydim=%d elementsize=%d offset=%d\n",
          *f->GetFName(),*f->GetClass()->GetFName(),f->ArrayDim,f->ElementSize,f->Offset);
      }
    }
    printf("G3_BULK_227_OBJECT_VALUES_BEGIN\n");
    UObject::ExportProperties(out,c,(BYTE*)obj,0,NULL,NULL);
    printf("G3_BULK_227_OBJECT_VALUES_END\n");
    saved=UObject::SavePackage(pkg,NULL,RF_Public|RF_Standalone,
     TEXT("C:\\Temp\\UnrealDB\\O06\\Native-227k\\System\\G3Array227Runtime.u"));
    printf("G3_BULK_227_NATIVE_SAVE %d\n",saved);
   }
  }
 }
 appPreExit();appExit();
 return saved?0:5;
}
