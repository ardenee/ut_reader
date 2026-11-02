// Disposable launcher into unmodified actual May 1998 Core.dll ABI.
// Original Epic FObjectManager::LoadPackage / appInit, not PHP, not source simulation.
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <cstdio>
#include <cstring>
typedef void (__cdecl *CoreInit)();
typedef void (__cdecl *CoreExit)();
typedef void* (__thiscall *OriginalLoad)(void*,void*,const char*,unsigned int);
int main(int argc,char** argv){
 setvbuf(stdout,NULL,_IONBF,0);
 printf("G3_T065_C98_REAL_ORIGINAL_DLL_LOADER_ENTER\n");
 HMODULE core=LoadLibraryA("Core.dll");
 if(!core){printf("G3_T065_C98_REAL_ORIGINAL_CORE_LOAD_FAILED=%lu\n",GetLastError());return 18;}
 HMODULE engine=LoadLibraryA("Engine.dll");
 printf("G3_T065_C98_ORIGINAL_NATIVE_ENGINE_DLL_PRELOAD=%p error=%lu\n",engine,GetLastError());
 if(!engine)return 24;
 auto sym=[&](const char* n)->FARPROC{return GetProcAddress(core,n);};
 CoreInit init=(CoreInit)sym("?appInit@@YAXXZ");
 CoreExit finish=(CoreExit)sym("?appExit@@YAXXZ");
 OriginalLoad load=(OriginalLoad)sym("?LoadPackage@FObjectManager@@UAEPAVUObject@@PAV2@PBDK@Z");
 void* objects=sym("?GObj@@3VFObjectManager@@A");
 void* platform=sym("?GTempPlatform@@3VFGlobalPlatform@@A");
 void** system=(void**)sym("?GSystem@@3PAVFGlobalPlatform@@A");
 char* name=(char*)sym("GPackage");
 int* started=(int*)sym("?GIsStarted@@3HA");
 int* guarded=(int*)sym("?GIsGuarded@@3HA");
 int* editor=(int*)sym("?GIsEditor@@3HA");
 int* server=(int*)sym("?GIsServer@@3HA");
 int* client=(int*)sym("?GIsClient@@3HA");
 printf("G3_T065_C98_GENUINE_ABI core=%p init=%p LoadPackage=%p GObj=%p GTempPlatform=%p GSystem=%p\n",
   core,init,load,objects,platform,system);
 if(!init||!finish||!load||!objects||!platform||!system||!name||!started||!guarded||!editor||!server||!client) return 19;
 strcpy_s(name,64,"Unreal");
 *system=platform;*started=1;*guarded=1;*editor=1;*server=0;*client=0;
 printf("G3_T065_C98_GENUINE_ABI_INIT_START original Core.appInit\n");
 try{init();}
 catch(...){printf("G3_T065_C98_GENUINE_ABI_INIT_EXCEPTION\n");return 21;}
 printf("G3_T065_C98_GENUINE_ABI_INIT_SUCCESS actual GObj.Init completed\n");
 const char* corePath="C:\\Temp\\UnrealDB\\O06\\Native-C98-1998-G304\\System\\Core.u";
 const char* enginePath="C:\\Temp\\UnrealDB\\O06\\Native-C98-1998-G304\\System\\Engine.u";
 printf("G3_T072_C98_ORIGINAL_NATIVE_LINKER_LOAD_CORE_ENTER path=%s\n",corePath);
 void* originalCore=nullptr;
 try{originalCore=load(objects,nullptr,corePath,0x0002);}
 catch(...){printf("G3_T072_C98_ORIGINAL_NATIVE_CORE_EXCEPTION\n");}
 printf("G3_T072_C98_ORIGINAL_NATIVE_LINKER_LOAD_CORE_RESULT=%p\n",originalCore);
 printf("G3_T072_C98_ORIGINAL_NATIVE_LINKER_LOAD_ENGINE_ENTER path=%s\n",enginePath);
 void* p=nullptr;
 try{p=load(objects,nullptr,enginePath,0x0002);}
 catch(...){printf("G3_T072_C98_ORIGINAL_NATIVE_ENGINE_EXCEPTION\n");}
 printf("G3_T072_C98_ORIGINAL_NATIVE_LINKER_LOAD_ENGINE_RESULT=%p\n",p);

 if(argc>=2 && p){
   for(int x=1;x<argc;x++){
     const char* physical=argv[x];
     printf("T065_NEW_ORIGINAL_1998_AFTER_CORE_ENGINE_REAL_FILE_START=%s\n",physical);
     void* filePkg=nullptr;
     try{filePkg=load(objects,nullptr,physical,0x0002);}
     catch(...){printf("T065_NEW_ORIGINAL_1998_PHYSICAL_FILE_THROW=%s\n",physical);}
     printf("T065_NEW_ORIGINAL_1998_AFTER_CORE_ENGINE_REAL_FILE_RESULT=%p source=%s\n",filePkg,physical);
   }
 }
 // Cleanup only after source call completes. Existing source/binaries untouched.
 try{finish();}catch(...){printf("G3_T065_C98_NATIVE_APP_EXIT_EXCEPTION\n");}
 return p?0:6;
}
