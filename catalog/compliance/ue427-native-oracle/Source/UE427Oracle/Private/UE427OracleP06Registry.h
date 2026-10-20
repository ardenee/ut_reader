#pragma once
#include "UObject/PackageFileSummary.h"
#include "UObject/ObjectVersion.h"
#include "Serialization/Archive.h"

// Mirrors Epic AssetRegistry/Private/PackageReader.cpp::ReadPackageDataMain,
// using the engine's actual FArchive/FString operator<< for every field.
// Does not synthesize FAssetData paths, map-world fallbacks or decoded tags.
static bool UE427DumpP06AssetRegistryRaw(FArchive& Ar,const FPackageFileSummary& S,int32 FileIndex)
{
 if(FileIndex==0)UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_REG_VERSION|%d"),(int32)VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS);
 const int64 Offset=S.AssetRegistryDataOffset;
 if(Offset<=0){
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_REG_HEADER|%d|%lld|1|-1|0"),FileIndex,Offset);
  return true;
 }
 if(Offset>Ar.TotalSize()-4)return false;
 Ar.Seek(Offset);
 const bool bPre=S.GetFileVersionUE4()<VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS
                || !!(S.PackageFlags & PKG_FilterEditorOnly);
 int64 Dependency=-1;
 if(!bPre)Ar<<Dependency;
 int32 Num=0;Ar<<Num;
 if(Ar.IsError()||Num<0||Num>(Ar.TotalSize()-Ar.Tell())/12)return false;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_REG_HEADER|%d|%lld|%d|%lld|%d"),
  FileIndex,Offset,bPre?1:0,Dependency,Num);
 for(int32 I=0;I<Num;++I){
  const int64 Position=Ar.Tell();
  FString Path,Class;int32 Tags=0;
  Ar<<Path;Ar<<Class;Ar<<Tags;
  if(Ar.IsError()||Tags<0||Tags>(Ar.TotalSize()-Ar.Tell())/8)return false;
  FTCHARToUTF8 PathU(*Path),ClassU(*Class);
  const FString PathHex=BytesToHex((const uint8*)PathU.Get(),PathU.Length());
  const FString ClassHex=BytesToHex((const uint8*)ClassU.Get(),ClassU.Length());
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_REG_OBJECT|%d|%d|%lld|%s|%s|%d"),
   FileIndex,I,Position,*PathHex,*ClassHex,Tags);
  for(int32 J=0;J<Tags;++J){
   const int64 TagPosition=Ar.Tell();
   FString Key,Value;Ar<<Key;Ar<<Value;
   if(Ar.IsError())return false;
   FTCHARToUTF8 KeyU(*Key),ValueU(*Value);
   const FString KeyHex=BytesToHex((const uint8*)KeyU.Get(),KeyU.Length());
   const FString ValueHex=BytesToHex((const uint8*)ValueU.Get(),ValueU.Length());
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_REG_TAG|%d|%d|%d|%lld|%s|%s"),
    FileIndex,I,J,TagPosition,*KeyHex,*ValueHex);
  }
 }
 return true;
}
