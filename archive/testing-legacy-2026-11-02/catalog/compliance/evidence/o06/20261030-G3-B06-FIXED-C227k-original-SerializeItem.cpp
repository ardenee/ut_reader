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
 GIsStarted=1;GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* loaded=NULL;
 try{loaded=UObject::LoadPackage(NULL,TEXT("G3Fixed227.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_SCALAR_NATIVE_PACKAGE %p bad=%d\n",loaded,bad);
 UBOOL saved=0;
 if(loaded&&!bad){
  UClass* c=NULL;
  for(FObjectIterator It;It;++It){
   UObject* o=*It;
   if(o && o->IsA(UClass::StaticClass())&&
      appStrcmp(o->GetFullName(),TEXT("Class G3Fixed227.G3Fixed227Actor"))==0){c=(UClass*)o;break;}
  }
  printf("G3_SCALAR_NATIVE_CLASS %p\n",c);
  if(c){
   Output out;
   UObject* def=c->GetDefaultObject();
   printf("G3_SCALAR_NATIVE_CDO %p\n",def);
   if(def)UObject::ExportProperties(out,c,(BYTE*)def,0,NULL,NULL);
   printf("G3_B06_REAL_ORIGINAL_OWNER_CDO %p\n",def);
   UIntProperty* intP=NULL;UStructProperty* structP=NULL;
   for(TFieldIterator<UProperty> It(c);It;++It){
     UProperty* p=*It;
     if(!appStricmp(*p->GetFName(),TEXT("TwoInts")) && p->IsA(UIntProperty::StaticClass()))
       intP=(UIntProperty*)p;
     if(!appStricmp(*p->GetFName(),TEXT("PlainPairs")) && p->IsA(UArrayProperty::StaticClass()))
       structP=(UStructProperty*)((UArrayProperty*)p)->Inner;
   }
   printf("G3_B06_REAL_ORIGINAL_INNER_POINTERS int=%p struct=%p\n",intP,structP);
   if(intP&&structP){
     UFixedArrayProperty* fixedInt=new(c,FName(TEXT("G3B06GenuineOriginalFixedInt")),RF_Transient)UFixedArrayProperty;
     printf("G3_B06_REAL_ORIGINAL_FIXEDINT_ALLOC %p\n",fixedInt);
     fixedInt->AddCppProperty(intP,2);
     INT original[2]={123,-456},loadedValues[2]={0,0};
     FBufferArchive ar;fixedInt->SerializeItem(ar,original);
     FBufferReader reader(ar);fixedInt->SerializeItem(reader,loadedValues);
     printf("G3_B06_REAL_ORIGINAL_FIXEDINT saved=%d cursor=%d input=%d,%d loaded=%d,%d hex=",
       ar.Num(),reader.Tell(),original[0],original[1],loadedValues[0],loadedValues[1]);
     for(INT i=0;i<ar.Num();i++)printf("%02X",(unsigned)ar(i));printf("\n");
     if(ar.Num()!=8||reader.Tell()!=8||loadedValues[0]!=123||loadedValues[1]!=-456)bad=1;
     UFixedArrayProperty* fixedStruct=new(c,FName(TEXT("G3B06GenuineOriginalFixedStruct")),RF_Transient)UFixedArrayProperty;
     fixedStruct->AddCppProperty(structP,2);
     UArrayProperty* originalPlainPairs=NULL;
     for(TFieldIterator<UProperty> It(c);It;++It){
       if(!appStricmp(*It->GetFName(),TEXT("PlainPairs")))
        originalPlainPairs=(UArrayProperty*)*It;
     }
     FArray* loadedSource=(FArray*)((BYTE*)def+originalPlainPairs->Offset);
     printf("G3_B06_REAL_ORIGINAL_FIXEDSTRUCT_ALLOC %p count=%d stride=%d\n",
      fixedStruct,loadedSource->Num(),structP->ElementSize);
     if(loadedSource->Num()==2){
       FBufferArchive buf;
       fixedStruct->SerializeItem(buf,loadedSource->GetData());
       BYTE restored[256]={0};
       FBufferReader rd(buf);
       fixedStruct->SerializeItem(rd,restored);
       printf("G3_B06_REAL_ORIGINAL_FIXEDSTRUCT saved=%d cursor=%d hex=",
         buf.Num(),rd.Tell());
       for(INT j=0;j<buf.Num();j++)printf("%02X",(unsigned)buf(j));
       printf("\n");
       if(buf.Num()!=16||rd.Tell()!=16)bad=1;
       UStruct* layout=structP->Struct;
       for(INT j=0;j<2;j++){
         for(TFieldIterator<UProperty> It(layout);It;++It){
          UProperty* field=*It;
          BYTE* ptr=restored+j*structP->ElementSize+field->Offset;
          if(!appStricmp(*field->GetFName(),TEXT("Number")))
            printf("G3_B06_REAL_FIXEDSTRUCT_LOADED pair=%d Number=%d\n",j,*(INT*)ptr);
          if(!appStricmp(*field->GetFName(),TEXT("Fraction")))
            printf("G3_B06_REAL_FIXEDSTRUCT_LOADED pair=%d Fraction=%f\n",j,*(FLOAT*)ptr);
          if(!appStricmp(*field->GetFName(),TEXT("Label")))
            printf("G3_B06_REAL_FIXEDSTRUCT_LOADED pair=%d LabelNameIndex=%d\n",j,((FName*)ptr)->GetIndex());
         }
       }
     }

   }


  }
 }
 appPreExit();appExit();return loaded&&!bad?0:5;
}
