#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern CORE_API FGlobalPlatform GTempPlatform;
extern "C" { HINSTANCE hInstance; __declspec(dllexport) char GPackage[]="Unreal"; }
class G3NativeClassArchive:public FArchive {
 public:
 int Calls,Raw,Names,Refs,Cursor;
 G3NativeClassArchive(INT ver):Calls(0),Raw(0),Names(0),Refs(0),Cursor(0) {
  ArVer=ver;ArIsTrans=1;ArIsLoading=0;ArIsSaving=0;
 }
 virtual FArchive& Serialize(void* p,INT n) {++Calls;Raw+=n;Cursor+=n;return *this;}
 virtual FArchive& operator<<(FName& n){++Names;++Cursor;return *this;}
 virtual FArchive& operator<<(UObject*& n){++Refs;++Cursor;return *this;}
 virtual INT Tell(){return Cursor;}
};
int main() {
 GIsStarted=1;GIsServer=1;GIsClient=0;GIsEditor=1;GIsGuarded=1;GSystem=&GTempPlatform;
 printf("G3_C98_NATIVE_1998_UCLASS_BEGIN\n");fflush(stdout);
 try {
  appInit();
  UClass* cls[2]={UObject::StaticClass,UClass::StaticClass};
  for(int i=0;i<2;i++){
   UClass* x=cls[i];
   printf("G3_C98_UCLASS_POINTER CLASS=%d PTR=%p DEFAULTS=%d SCRIPT=%d\n",i,x,(int)x->Defaults.Num(),(int)x->Script.Num());fflush(stdout);
   for(int v=55;v<=58;v++){
    G3NativeClassArchive ar(v);
    try{
     x->Serialize(ar);
     printf("G3_C98_NATIVE_UCLASS CLASS=%d VER=%d NAMES=%d REFS=%d RAW=%d CURSOR=%d\n",i,v,ar.Names,ar.Refs,ar.Raw,ar.Cursor);
    }catch(char* e){printf("G3_C98_NATIVE_UCLASS_SOURCE_THROW CLASS=%d VER=%d ERROR=%s NAMES=%d REFS=%d RAW=%d\n",i,v,e?e:"NULL",ar.Names,ar.Refs,ar.Raw);}catch(...){printf("G3_C98_NATIVE_UCLASS_EXCEPTION CLASS=%d VER=%d NAMES=%d REFS=%d RAW=%d HISTORY=%s\n",i,v,ar.Names,ar.Refs,ar.Raw,GErrorHist);}
    fflush(stdout);
   }
  }
 }catch(...){printf("G3_C98_ORIGINAL_NATIVE_INIT_FAILED\n");fflush(stdout);}
 printf("G3_C98_NATIVE_UCLASS_DONE\n");fflush(stdout);
 return 0;
}