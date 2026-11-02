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
 try{loaded=UObject::LoadPackage(NULL,TEXT("G3Array227.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("G3_SCALAR_NATIVE_PACKAGE %p bad=%d\n",loaded,bad);
 UBOOL saved=0;
 if(loaded&&!bad){
  UClass* c=NULL;
  for(FObjectIterator It;It;++It){
   UObject* o=*It;
   if(o && o->IsA(UClass::StaticClass())&&
      appStrcmp(o->GetFullName(),TEXT("Class G3Array227.G3Array227Actor"))==0){c=(UClass*)o;break;}
  }
  printf("G3_SCALAR_NATIVE_CLASS %p\n",c);
  if(c){
   Output out;
   UObject* def=c->GetDefaultObject();
   printf("G3_SCALAR_NATIVE_CDO %p\n",def);
   if(def)UObject::ExportProperties(out,c,(BYTE*)def,0,NULL,NULL);
   printf("G3_B06_REAL_ORIGINAL_OWNER_CDO %p\n",def);
   UIntProperty* intP=NULL;UNameProperty* nameP=NULL;UStructProperty* structP=NULL;
   for(TFieldIterator<UProperty> It(c);It;++It){
     UProperty* p=*It;
     if(!appStricmp(*p->GetFName(),TEXT("StaticInts")) && p->IsA(UIntProperty::StaticClass()))
       intP=(UIntProperty*)p;
     if(!appStricmp(*p->GetFName(),TEXT("StaticNames")) && p->IsA(UNameProperty::StaticClass()))
       nameP=(UNameProperty*)p;
     if(!appStricmp(*p->GetFName(),TEXT("Pairs")) && p->IsA(UArrayProperty::StaticClass()))
       structP=(UStructProperty*)((UArrayProperty*)p)->Inner;
   }
   printf("G3_B06_REAL_ORIGINAL_INNER_POINTERS int=%p name=%p struct=%p\n",intP,nameP,structP);
   if(intP&&nameP&&structP){
     UFixedArrayProperty* fixedInt=new(c,FName(TEXT("G3B06RealFixedInt")),RF_Transient)UFixedArrayProperty;
     printf("G3_B06_REAL_ORIGINAL_FIXEDINT_ALLOC %p\n",fixedInt);
     fixedInt->AddCppProperty(intP,2);
     INT original[2]={123,-456},loadedValues[2]={0,0};
     FBufferArchive ar;fixedInt->SerializeItem(ar,original);
     FBufferReader reader(ar);fixedInt->SerializeItem(reader,loadedValues);
     printf("G3_B06_REAL_ORIGINAL_FIXEDINT saved=%d cursor=%d input=%d,%d loaded=%d,%d hex=",
       ar.Num(),reader.Tell(),original[0],original[1],loadedValues[0],loadedValues[1]);
     for(INT i=0;i<ar.Num();i++)printf("%02X",(unsigned)ar(i));printf("\n");
     if(ar.Num()!=8||reader.Tell()!=8||loadedValues[0]!=123||loadedValues[1]!=-456)bad=1;
     UMapProperty* map=new(c,FName(TEXT("G3B06RealMap227k")),RF_Transient)UMapProperty;
     map->Key=intP;map->Value=intP;
     printf("G3_B06_C227K_REAL_MAP_ALLOC map=%p key=%p value=%p\n",map,map->Key,map->Value);
     TScriptMap<INT,INT> zero,multiple;
     multiple.Set(12,1200);multiple.Set(34,3400);
     for(INT i=0;i<2;i++){
       TScriptMap<INT,INT>* m=i?&multiple:&zero;
       printf("G3_B06_C227K_REAL_MAP_BEFORE idx=%d count=%d\n",i,m->Num());
       FBufferArchive ar;
       map->SerializeItem(ar,m);
       printf("G3_B06_C227K_REAL_MAP_SAVED idx=%d bytes=%d hex=",i,ar.Num());
       for(INT j=0;j<ar.Num();j++)printf("%02X",(unsigned)ar(j));
       printf("\n");
       TScriptMap<INT,INT> restored;
       FBufferReader rd(ar);map->SerializeItem(rd,&restored);
       printf("G3_B06_C227K_REAL_MAP_LOADED idx=%d count=%d tell=%d\n",i,restored.Num(),rd.Tell());
     }

   }


  }
 }
 appPreExit();appExit();return loaded&&!bad?0:5;
}
