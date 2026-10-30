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
   UStrProperty* stringP=NULL;
   for(TFieldIterator<UProperty> It(c);It;++It){
     UProperty* p=*It;
     if(p->IsA(UStrProperty::StaticClass())&&
       appStricmp(*p->GetFName(),TEXT("OneText"))==0){
       stringP=(UStrProperty*)p;break;
     }
   }
   printf("G3_NATIVE_UTF16_ORIGINAL_STR_PROPERTY %p\n",stringP);
   if(stringP){
    const TCHAR emoji[]={0xD83D,0xDE80,0};
    const TCHAR mixed[]={L'A',0,L'B',0};
    const TCHAR ascii[]={L'A',0};
    const TCHAR wide[]={0x03A9,0};
    const TCHAR* strings[]={emoji,mixed,ascii,wide};
    const INT sizes[]={2,3,1,1};
    const char* labels[]={"nonBMP_surrogate_pair","embeddedNul_A0B","ascii_A","unicode_Omega"};
    for(INT k=0;k<4;k++){
      FString original(strings[k],strings[k]+sizes[k]);
      FBufferArchive writer;
      stringP->SerializeItem(writer,&original);
      FString reloaded;
      FBufferReader reader(writer);
      stringP->SerializeItem(reader,&reloaded);
      printf("G3_NATIVE_UTF16_ORIGINAL_ENTRY label=%s inputLength=%d byteLength=%d readerPos=%d loadedLength=%d originalWords=",
        labels[k],original.Len(),writer.Num(),reader.Tell(),reloaded.Len());
      for(INT i=0;i<original.Len()+1;i++)printf("%04X,", (unsigned)(WORD)original[i]);
      printf(" loadedWords=");
      for(INT i=0;i<reloaded.Len()+1;i++)printf("%04X,", (unsigned)(WORD)reloaded[i]);
      printf(" archivedHex=");
      for(INT i=0;i<writer.Num();i++)printf("%02X", (unsigned)writer(i));
      printf("\n");
      if(reader.Tell()!=writer.Num())bad=1;
    }
   }


  }
 }
 appPreExit();appExit();return loaded&&!bad?0:5;
}
