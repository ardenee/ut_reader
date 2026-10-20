#pragma once
// Source-pinned P04 oracle. All primitive, FString, TArray and FPackageIndex
// deserialization below executes Epic UE4.27.2 compiled FArchive operators.
#include "UObject/PackageFileSummary.h"
#include "UObject/ObjectResource.h"
#include "Containers/StringConv.h"
#include "Misc/StringBuilder.h"

static bool UE427DumpP04(FArchive& Ar, const FPackageFileSummary& S,
 const TArray<FNameEntryId>& Names, int32 FileIndex)
{
 const int32 Version = S.GetFileVersionUE4();
 Ar.SetUE4Ver(Version);
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P04_COUNT|%d|%d|%d|%d|%d|%d|%d|%d"),
  FileIndex,S.ExportCount,S.DependsOffset,S.SoftPackageReferencesCount,
  S.SoftPackageReferencesOffset,S.PreloadDependencyCount,S.PreloadDependencyOffset,Version);
 if (S.DependsOffset > 0 && S.ExportCount > 0) {
  Ar.Seek(S.DependsOffset);
  for (int32 Row=0; Row<S.ExportCount; Row++) {
   const int64 Offset=Ar.Tell();
   TArray<FPackageIndex> Refs;
   Ar << Refs;
   if (Ar.IsError()) {
    UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_P04_ERROR|%d|depends|%d"),FileIndex,Row);
    return false;
   }
   FString CSV;
   for (const FPackageIndex& Ref: Refs) {
    if (!CSV.IsEmpty()) CSV+=TEXT(",");
    CSV+=FString::FromInt(Ref.ForDebugging());
   }
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_DEPENDS|%d|%d|%lld|%d|%s"),
    FileIndex,Row,Offset,Refs.Num(),*CSV);
  }
 }
 if (S.SoftPackageReferencesCount > 0 && S.SoftPackageReferencesOffset > 0) {
  Ar.Seek(S.SoftPackageReferencesOffset);
  for (int32 Row=0; Row<S.SoftPackageReferencesCount; Row++) {
   const int64 Offset=Ar.Tell();
   FString Name;
   int32 NameIndex=-1;
   int32 Number=0;
   const bool bLegacy=Version<VER_UE4_ADDED_SOFT_OBJECT_PATH;
   if (bLegacy) {
    Ar << Name;
   } else {
    Ar << NameIndex;
    Ar << Number;
    if (!Names.IsValidIndex(NameIndex)) {
     UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_P04_ERROR|%d|soft_fname_index|%d|%d"),
       FileIndex,Row,NameIndex);
     return false;
    }
    Name=FName::CreateFromDisplayId(Names[NameIndex],Number).ToString();
   }
   if (Ar.IsError()) {
    UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_P04_ERROR|%d|soft|%d"),FileIndex,Row);
    return false;
   }
   const FTCHARToUTF8 UTF8(*Name);
   const FString Hex=BytesToHex((const uint8*)UTF8.Get(),UTF8.Length());
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_SOFT|%d|%d|%lld|%d|%d|%d|%s"),
     FileIndex,Row,Offset,bLegacy?1:0,NameIndex,Number,*Hex);
  }
 }
 if (S.PreloadDependencyCount > 0 && S.PreloadDependencyOffset > 0) {
  Ar.Seek(S.PreloadDependencyOffset);
  for (int32 Row=0; Row<S.PreloadDependencyCount; Row++) {
   const int64 Offset=Ar.Tell();
   FPackageIndex Ref;
   Ar << Ref;
   if (Ar.IsError()) {
    UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_P04_ERROR|%d|preload|%d"),FileIndex,Row);
    return false;
   }
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_PRELOAD|%d|%d|%lld|%d"),
     FileIndex,Row,Offset,Ref.ForDebugging());
  }
 }
 return true;
}
