// Original shipped 2026 C227k Core.dll native loaded CDO and real UAnyProperty values, disposable package source created by original shipped UCC make.
#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern "C" { HINSTANCE hInstance; }
extern "C" { const TCHAR * GPackage=TEXT("G3AnyOriginalRealNative"); }
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
static void showText(const TCHAR* label,const TCHAR* string){
 if(!string){printf("ORIGINAL_C227K_ANY_NATIVE_TEXT label=%ls value=NULL\n",label);return;}
 char utf8[8192];int n=WideCharToMultiByte(CP_UTF8,0,string,-1,utf8,sizeof(utf8),NULL,NULL);
 printf("ORIGINAL_C227K_ANY_NATIVE_TEXT label=%ls value=%s\n",label,n>0?utf8:"<utf8_err>");
}
int main(){
 GIsStarted=1;GIsGuarded=1;
 TCHAR CmdLine[64]=TEXT("");
 appInit(TEXT("Unreal"),CmdLine,&Malloc,&Log,&Error,&Warn,&FileManager,FConfigCacheIni::Factory,1);
 UObject::SetLanguage(TEXT("int"));
 GIsClient=0;GIsServer=0;GIsEditor=1;GIsScriptable=1;GLazyLoad=0;
 UBOOL bad=0;UObject* loaded=NULL;
 try{loaded=UObject::LoadPackage(NULL,TEXT("G3Any227K.u"),LOAD_NoFail);}
 catch(...){bad=1;Error.HandleError();}
 printf("ORIGINAL_C227K_ANY_NATIVE_LOAD package=%p bad=%d\n",loaded,bad);fflush(stdout);
 UClass* cls=NULL;
 if(loaded&&!bad)for(FObjectIterator It;It;++It){
  UObject* o=*It;
  if(o && o->IsA(UClass::StaticClass())
    &&appStrcmp(o->GetFullName(),TEXT("Class G3Any227K.G3Any227KActor"))==0){cls=(UClass*)o;break;}
 }
 printf("ORIGINAL_C227K_ANY_NATIVE_CLASS %p\n",cls);
 if(cls){
  UObject* def=cls->GetDefaultObject();
  printf("ORIGINAL_C227K_ANY_NATIVE_CDO %p\n",def);
  if(def)for(TFieldIterator<UProperty> It(cls);It;++It){
   UProperty* p=*It;
   if(!p || !p->IsA(UAnyProperty::StaticClass()))continue;
   const TCHAR* field=*p->GetFName();
   FAnyProperty** adr=(FAnyProperty**)((BYTE*)def+p->Offset);
   FAnyProperty* val=adr?*adr:NULL;
   printf("ORIGINAL_C227K_ANY_NATIVE_FIELD field=%ls offset=%d storage=%p any=%p type=%d\n",
     field,p->Offset,(void*)adr,(void*)val,val?val->GetType():-1);
   if(val && val->GetType()==5 && val->GetName()){
    const TCHAR* nt=**val->GetName();
    showText(field,nt);
   }
   if(val && val->GetType()==7 && val->GetObject()){
    showText(field,val->GetObject()->GetFullName());
   }
   fflush(stdout);
  }
 }
 appPreExit();appExit();return loaded&&!bad&&cls?0:5;
}
