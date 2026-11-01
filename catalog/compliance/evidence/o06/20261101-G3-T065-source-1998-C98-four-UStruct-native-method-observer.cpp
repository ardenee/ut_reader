#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern CORE_API FGlobalPlatform GTempPlatform;
extern "C" { HINSTANCE hInstance; __declspec(dllexport) char GPackage[]="Unreal"; }
class G3C98NativeArchive : public FArchive {
 public:
  int Names, RefReads, RawBytes, Calls, Cursor, Version;
  BYTE Info;
  G3C98NativeArchive(int ver,int kind): Names(0),RefReads(0),RawBytes(0),Calls(0),Cursor(0),Version(ver),Info((BYTE)(kind|0x20)){
    ArVer=ver;ArIsLoading=1;ArIsSaving=0;
  }
  virtual FArchive& operator<<(FName& n){
    n=Names++==0?FName("G3T065AbsentPropertyNeverDeclared",FNAME_Add):FName(NAME_None);
    ++Cursor;return *this;
  }
  virtual FArchive& operator<<(UObject*& o){
    ++RefReads;o=NULL;++Cursor;return *this;
  }
  virtual FArchive& Serialize(void* dest,INT n){
    BYTE* b=(BYTE*)dest;
    for(INT i=0;i<n;i++)b[i]=Calls==0&&i==0?Info:0;
    ++Calls;RawBytes+=n;Cursor+=n;return *this;
  }
  virtual INT Tell(){return Cursor;}
};
int main(){
 GIsStarted=1;GIsServer=1;GIsClient=0;GIsEditor=1;GIsGuarded=1;GSystem=&GTempPlatform;
 printf("G3_T065_C98_REAL_CORE_METHOD_ENTRY\n");fflush(stdout);
 try{
  appInit();
  UClass* cls=UObject::StaticClass;
  printf("G3_T065_C98_ORIGINAL_NATIVE_UCLASS=%p\n",cls);fflush(stdout);
  if(!cls)return 13;
  const int tests[4][2]={{34,5},{35,5},{35,8},{36,5}};
  for(int i=0;i<4;i++){
   G3C98NativeArchive ar(tests[i][0],tests[i][1]);
   BYTE zeros[512]={0};
   try{
    cls->SerializeTaggedProperties(ar,zeros,NULL);
    printf("G3_T065_REAL_1998_NATIVE_METHOD_VER=%d TYPE=%d REFS=%d RAW=%d NAMES=%d CURSOR=%d\n",
      tests[i][0],tests[i][1],ar.RefReads,ar.RawBytes,ar.Names,ar.Cursor);
   }catch(char* err){
    printf("G3_T065_REAL_NATIVE_SOURCE_THROW_VER=%d TYPE=%d ERROR=%s\n",tests[i][0],tests[i][1],err);
   }catch(...){
    printf("G3_T065_REAL_NATIVE_METHOD_EXCEPTION_VER=%d TYPE=%d\n",tests[i][0],tests[i][1]);
   }
   fflush(stdout);
  }
 }catch(...){printf("G3_T065_REAL_NATIVE_GLOBAL_EXCEPTION=%s\n",GErrorHist);}
 printf("G3_T065_REAL_C98_METHOD_END\n");fflush(stdout);
 return 0;
}
