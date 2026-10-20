#include "UE427OracleCommandlet.h"
#include "UObject/PackageFileSummary.h"
#include "UObject/UObjectGlobals.h"
#include "Serialization/MemoryWriter.h"
#include "HAL/FileManager.h"
#include "Misc/Paths.h"
#include "Misc/FileHelper.h"
#include "Misc/Parse.h"

UUE427OracleCommandlet::UUE427OracleCommandlet() {
 IsClient = false; IsServer = false; IsEditor = true; LogToConsole = true;
}
int32 UUE427OracleCommandlet::Main(const FString& Params) {
 FString FixturesDir;
 if (FParse::Value(*Params, TEXT("FixturesDir="), FixturesDir)) {
  IFileManager::Get().MakeDirectory(*FixturesDir, true);
  for (int32 Index=0; Index<3; ++Index) {
   const bool bSwapped = Index == 1;
   const bool bUnversioned = Index == 2;
   const FString FixtureName = Index == 0 ? TEXT("native-little.uasset") :
    (Index == 1 ? TEXT("native-swapped.uasset") : TEXT("native-unversioned.uasset"));
   FPackageFileSummary Summary;
   Summary.Tag = PACKAGE_FILE_TAG;
   Summary.SetFileVersions(522, 0, bUnversioned);
   Summary.TotalHeaderSize = 4096;
   TArray<uint8> Data;
   {
    FMemoryWriter Writer(Data, true);
    Writer.SetByteSwapping(bSwapped);
    Writer << Summary;
    if (Writer.IsError() || Data.Num() > 4096) {
     UE_LOG(LogTemp,Error,TEXT("UE427_FIXTURE_ERROR serialization %s %d"), *FixtureName, Data.Num());
     return 5;
    }
   }
   Data.SetNumZeroed(4096);
   const FString FixturePath = FPaths::Combine(FixturesDir, FixtureName);
   if (!FFileHelper::SaveArrayToFile(Data, *FixturePath)) {
    UE_LOG(LogTemp,Error,TEXT("UE427_FIXTURE_ERROR save %s"), *FixturePath);
    return 6;
   }
   UE_LOG(LogTemp,Display,TEXT("UE427_FIXTURE_WRITTEN|%s|%d|%d|%d"),
    *FixtureName, bSwapped ? 1 : 0, bUnversioned ? 1 : 0, Data.Num());
  }
  return 0;
 }
 FString ListPath;
 if (!FParse::Value(*Params, TEXT("List="), ListPath)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_ERROR list_missing")); return 2;
 }
 TArray<FString> Files;
 if (!FFileHelper::LoadFileToStringArray(Files,*ListPath)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_ERROR list_unreadable")); return 3;
 }
 const bool bAllow = FParse::Param(*Params,TEXT("AllowUnversionedInEditor"));
 const int32 PreviousAllow = GAllowUnversionedContentInEditor;
 GAllowUnversionedContentInEditor = bAllow ? 1 : 0;
 int32 Checked=0;
 for(const FString& Path:Files) {
  TUniquePtr<FArchive> Ar(IFileManager::Get().CreateFileReader(*Path));
  if(!Ar) { UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_ERROR unreadable %s"),*Path); continue; }
  FPackageFileSummary S;
  *Ar << S;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_SUMMARY|%s|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d"),
   *FPaths::GetCleanFilename(Path),S.GetFileVersionUE4(),S.GetFileVersionLicenseeUE4(),
   S.TotalHeaderSize,S.NameCount,S.NameOffset,S.ImportCount,S.ImportOffset,
   S.ExportCount,S.ExportOffset,S.DependsOffset,S.SoftPackageReferencesCount,
   S.SoftPackageReferencesOffset,S.PreloadDependencyCount,S.PreloadDependencyOffset,
   S.AssetRegistryDataOffset,S.ThumbnailTableOffset,S.CompressionFlags);
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_DIAGNOSTIC|%s|%d|%d|%d|%d|%lld|%d"),
   *FPaths::GetCleanFilename(Path), S.GetFileVersionUE4(), S.bUnversioned ? 1 : 0,
   Ar->ForceByteSwapping() ? 1 : 0, Ar->IsError() ? 1 : 0, Ar->Tell(), S.TotalHeaderSize);
  if (FParse::Param(*Params, TEXT("Strings"))) {
   const FTCHARToUTF8 Folder(*S.FolderName);
   const FString FolderHex = BytesToHex((const uint8*)Folder.Get(), Folder.Length());
   const FTCHARToUTF8 Localization(*S.LocalizationId);
   const FString LocalizationHex = BytesToHex((const uint8*)Localization.Get(), Localization.Length());
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_STRING|%d|%s|%s"), Checked, *FolderHex, *LocalizationHex);
  }
  const FCustomVersionArray& Versions = S.GetCustomVersionContainer().GetAllVersions();
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_CUSTOM_COUNT|%d|%d"), Checked, Versions.Num());
  for (const FCustomVersion& Version : Versions) {
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_CUSTOM|%d|%08X-%08X-%08X-%08X|%d"),
    Checked, Version.Key.A, Version.Key.B, Version.Key.C, Version.Key.D, Version.Version);
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_CUSTOM_FRIENDLY|%d|%08X-%08X-%08X-%08X|%s"),
    Checked, Version.Key.A, Version.Key.B, Version.Key.C, Version.Key.D,
    *Version.GetFriendlyName().ToString());
  }
  if (FParse::Param(*Params, TEXT("Names"))) {
   Ar->SetUE4Ver(S.GetFileVersionUE4());
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_NAME_COUNT|%d|%d"), Checked, S.NameCount);
   if (S.NameCount > 0 && S.NameOffset > 0 && S.NameOffset < Ar->TotalSize()) {
    Ar->Seek(S.NameOffset);
    FNameEntrySerialized NameEntry(ENAME_LinkerConstructor);
    TArray<FNameEntryId> MappedNames;
    MappedNames.Reserve(S.NameCount);
    for (int32 NameIndex = 0; NameIndex < S.NameCount; ++NameIndex) {
     const int64 EntryOffset = Ar->Tell();
     *Ar << NameEntry;
     if (Ar->IsError()) {
      UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_NAME_ERROR|%d|%d"), Checked, NameIndex);
      return 7;
     }
     MappedNames.Add(FName(NameEntry).GetDisplayIndex());
     const FString Name = NameEntry.GetPlainNameString();
     FTCHARToUTF8 UTF8(*Name);
     const FString Hex = BytesToHex((const uint8*)UTF8.Get(), UTF8.Length());
     UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_NAME|%d|%d|%lld|%s"),
      Checked, NameIndex, EntryOffset, *Hex);
     if (FParse::Param(*Params, TEXT("FNameSuffix")) && NameIndex < 3) {
      const FName Base(NameEntry);
      const FString Suffix = FName::CreateFromDisplayId(Base.GetDisplayIndex(), 43).ToString();
      const FTCHARToUTF8 SuffixUTF8(*Suffix);
      const FString SuffixHex = BytesToHex((const uint8*)SuffixUTF8.Get(), SuffixUTF8.Length());
      UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_SUFFIX|%d|%d|43|%s"), Checked, NameIndex, *SuffixHex);
     }
    }
    if (S.ImportCount > 0 && S.ImportOffset > 0) {
     Ar->Seek(S.ImportOffset);
     int32 NameIndex = -1;
     int32 InstanceNumber = 0;
     *Ar << NameIndex;
     *Ar << InstanceNumber;
     if (!MappedNames.IsValidIndex(NameIndex)) {
      UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_FNAME_WIRE_ERROR|%d|%d"),Checked,NameIndex);
      return 8;
     }
     const FString Rendered = FName::CreateFromDisplayId(MappedNames[NameIndex],InstanceNumber).ToString();
     const FTCHARToUTF8 UTF8(*Rendered);
     const FString NameHex = BytesToHex((const uint8*)UTF8.Get(), UTF8.Length());
     UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_FNAME_WIRE|%d|%d|%d|%s"),
      Checked,NameIndex,InstanceNumber,*NameHex);
    }
   }
  }
  Checked++;
 }
 GAllowUnversionedContentInEditor = PreviousAllow;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_COUNT %d"),Checked);
 return Checked>0?0:4;
}
