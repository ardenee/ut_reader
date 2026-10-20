#pragma once
#include "UObject/LinkerLoad.h"
#include "UObject/UObjectGlobals.h"
#include "UObject/Package.h"

// Calls the *compiled* UE4.27.2 LoadPackage runtime on genuine engine assets.
// This probe does NOT invoke VerifyImportInner and does not claim which
// internal import-verification branches the engine traversed while loading.
// SourceLinker and SourceIndex are inspected only after a completed full load.
static bool UE427ProbeP07Runtime(const FString& PackageList)
{
 TArray<FString> Paths;
 PackageList.ParseIntoArray(Paths,TEXT(";"),true);
 int32 Checked=0,Imports=0,Linked=0,Loaded=0,Unlinked=0;
 for(const FString& Name:Paths){
  UPackage* Pkg=LoadPackage(nullptr,*Name,LOAD_None);
  if(!Pkg){
   UE_LOG(LogTemp,Warning,TEXT("UE427_NATIVE_P07_PACKAGE|%s|0|0"),*Name);
   continue;
  }
  FLinkerLoad* Linker=FLinkerLoad::FindExistingLinkerForPackage(Pkg);
  if(!Linker){
   UE_LOG(LogTemp,Warning,TEXT("UE427_NATIVE_P07_PACKAGE|%s|1|-1"),*Name);
   continue;
  }
  // Exercise actual compiled package load, then inspect post-load state.
  // This is NOT direct VerifyImportInner instrumentation or provider proof.
  Linker->LoadAllObjects(true);
  ++Checked;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P07_PACKAGE|%s|1|%d"),*Name,Linker->ImportMap.Num());
  for(int32 I=0;I<Linker->ImportMap.Num();++I){
   const FObjectImport& Imp=Linker->ImportMap[I];
   const bool bLinked=Imp.SourceLinker && Imp.SourceIndex>=0;
   const bool bLoaded=Imp.XObject!=nullptr;
   if(bLinked)++Linked;else ++Unlinked;
   if(bLoaded)++Loaded;
   const FString Provider=bLinked?Imp.SourceLinker->LinkerRoot->GetName():TEXT("");
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P07_IMPORT|%s|%d|%s|%s|%s|%d|%d|%d|%s"),
    *Name,I,*Imp.ClassPackage.ToString(),*Imp.ClassName.ToString(),
    *Imp.ObjectName.ToString(),Imp.OuterIndex.ForDebugging(),bLinked?Imp.SourceIndex:-1,
    bLoaded?1:0,*Provider);
   ++Imports;
  }
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P07_COUNT|%d|%d|%d|%d|%d"),Checked,Imports,Linked,Loaded,Unlinked);
 return Checked>0&&Imports>0;
}
