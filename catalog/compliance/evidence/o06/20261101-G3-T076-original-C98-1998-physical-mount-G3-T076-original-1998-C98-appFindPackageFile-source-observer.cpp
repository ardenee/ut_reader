#include <windows.h>
#include <stdio.h>
#include "Core.h"
extern CORE_API FGlobalPlatform GTempPlatform;
extern "C" {HINSTANCE hInstance;__declspec(dllexport)char GPackage[]="Unreal";}
int main(int argc,char** argv){
 GIsStarted=1;GIsServer=1;GIsClient=0;GIsEditor=1;GIsGuarded=1;GSystem=&GTempPlatform;
 try{
  appInit();
  for(int i=0;i<96;i++)GSys->Paths[i][0]=0;
  strcpy(GSys->Paths[0],"../System/*.u");
  strcpy(GSys->Paths[1],"../Tex/*.utx");
  char found[256]={0};
  UBOOL exists=appFindPackageFile("G3T076C98Probe",NULL,found);
  printf("G3_T076_ORIGINAL_C98_1998_NATIVE_PhysicalResult=%d File=%s\n",(int)exists,exists?found:"");
  printf("G3_T076_ORIGINAL_C98_1998_NATIVE_SearchPath0=%s SearchPath1=%s\n",GSys->Paths[0],GSys->Paths[1]);
 }catch(char* why){printf("G3_T076_ORIGINAL_C98_1998_THROW=%s\n",why?why:"null");}
 catch(...){printf("G3_T076_ORIGINAL_C98_1998_NATIVE_EXCEPTION\n");}
 printf("G3_T076_ORIGINAL_C98_1998_NATIVE_END\n");fflush(stdout);
 return 0;
}
