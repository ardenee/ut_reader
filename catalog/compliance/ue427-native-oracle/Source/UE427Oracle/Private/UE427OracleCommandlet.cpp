#include "UE427OracleCommandlet.h"
#include "UObject/PackageFileSummary.h"
#include "UObject/UObjectGlobals.h"
#include "Serialization/MemoryWriter.h"
#include "HAL/FileManager.h"
#include "Misc/Paths.h"
#include "Misc/FileHelper.h"
#include "Misc/Parse.h"
#include "Serialization/ArchiveProxy.h"
#include "UObject/ObjectResource.h"
#include "UE427OracleP04.h"
#include "UE427OracleP05.h"
#include "UE427OracleP05Policy.h"
#include "UE427OracleP05Cook.h"

// Preserve Epic's actual FObjectImport / FObjectExport operator<< and supply
// the package-local FName mapping that FLinkerLoad::operator<<(FName&) uses.
// The byte reader, primitive serializer and record field gates are Epic code.
class FUE427RecordArchive final : public FArchiveProxy
{
public:
 explicit FUE427RecordArchive(FArchive& In, const TArray<FNameEntryId>& InNames)
  : FArchiveProxy(In), Names(InNames) {}
 using FArchiveProxy::operator<<;
 TArray<TPair<int32,int32>> WireNames;
 virtual FArchive& operator<<(FName& Name) override
 {
  int32 NameIndex = -1;
  int32 Number = 0;
  FArchive& Base = *this;
  Base << NameIndex;
  Base << Number;
  if (!Names.IsValidIndex(NameIndex)) {
   SetCriticalError();
   Name = FName();
  } else {
   Name = FName::CreateFromDisplayId(Names[NameIndex],Number);
  }
  WireNames.Emplace(NameIndex,Number);
  return *this;
 }
private:
 const TArray<FNameEntryId>& Names;
};

static bool UE427DumpRecordMaps(FArchive& Source, const FPackageFileSummary& S,
 const TArray<FNameEntryId>& MappedNames, int32 FileIndex)
{
 const bool bFiltered = (S.PackageFlags & PKG_FilterEditorOnly) != 0;
 Source.SetFilterEditorOnly(bFiltered);
 FUE427RecordArchive Reader(Source, MappedNames);
 Reader.SetUE4Ver(S.GetFileVersionUE4());
 // FArchiveProxy::SetFilterEditorOnly delegates to InnerArchive without
 // updating its copied proxy state; explicitly initialize both states so
 // FObjectImport's actual Epic operator sees the intended editor filter.
 Reader.ArIsFilterEditorOnly = bFiltered;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_FILTER_STATE|%d|%u|%d|%d|%d"),
  FileIndex,S.PackageFlags,bFiltered ? 1 : 0,
  Source.IsFilterEditorOnly() ? 1 : 0,Reader.IsFilterEditorOnly() ? 1 : 0);
 const bool HasPackageName = Reader.UE4Ver() >= VER_UE4_NON_OUTER_PACKAGE_IMPORT
  && !Reader.IsFilterEditorOnly();
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_RECORD_COUNT|%d|%d|%d|%d|%d"),
  FileIndex,S.ImportCount,S.ExportCount,HasPackageName ? 1 : 0,Reader.UE4Ver());
 if (S.ImportCount > 0) {
  Reader.Seek(S.ImportOffset);
  for (int32 Index = 0; Index < S.ImportCount; ++Index) {
   Reader.WireNames.Reset();
   const int64 Start = Reader.Tell();
   FObjectImport Record;
   Reader << Record;
   if (Reader.IsError() || Reader.WireNames.Num() != (HasPackageName ? 4 : 3)) {
    UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_RECORD_ERROR|%d|import|%d"),FileIndex,Index);
    return false;
   }
   const int64 End = Reader.Tell();
   const auto& Names = Reader.WireNames;
   UE_LOG(LogTemp,Display,
    TEXT("UE427_NATIVE_IMPORT|%d|%d|%lld|%lld|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d"),
    FileIndex,Index,Start,End,Names[0].Key,Names[0].Value,Names[1].Key,
    Names[1].Value,Record.OuterIndex.ForDebugging(),Names[2].Key,Names[2].Value,
    HasPackageName ? 1 : 0,HasPackageName ? Names[3].Key : -1,
    HasPackageName ? Names[3].Value : -1);
  }
 }
 if (S.ExportCount > 0) {
  Reader.Seek(S.ExportOffset);
  for (int32 Index = 0; Index < S.ExportCount; ++Index) {
   Reader.WireNames.Reset();
   const int64 Start = Reader.Tell();
   FObjectExport Record;
   Reader << Record;
   if (Reader.IsError() || Reader.WireNames.Num() != 1) {
    UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_RECORD_ERROR|%d|export|%d"),FileIndex,Index);
    return false;
   }
   const int64 End = Reader.Tell();
   UE_LOG(LogTemp,Display,
    TEXT("UE427_NATIVE_EXPORT|%d|%d|%lld|%lld|%d|%d|%d|%d|%d|%d|%u|%lld|%lld|%d|%d|%d|%u|%u|%u|%u|%u|%d|%d|%d|%d|%d|%d|%d"),
    FileIndex,Index,Start,End,Record.ClassIndex.ForDebugging(),
    Record.SuperIndex.ForDebugging(),Record.TemplateIndex.ForDebugging(),
    Record.OuterIndex.ForDebugging(),Reader.WireNames[0].Key,
    Reader.WireNames[0].Value,(uint32)Record.ObjectFlags,
    Record.SerialSize,Record.SerialOffset,
    Record.bForcedExport ? 1 : 0,Record.bNotForClient ? 1 : 0,
    Record.bNotForServer ? 1 : 0,
    Record.PackageGuid.A,Record.PackageGuid.B,
    Record.PackageGuid.C,Record.PackageGuid.D,(uint32)Record.PackageFlags,
    Record.bNotAlwaysLoadedForEditorGame ? 1 : 0,Record.bIsAsset ? 1 : 0,
    Record.FirstExportDependency,Record.SerializationBeforeSerializationDependencies,
    Record.CreateBeforeSerializationDependencies,
    Record.SerializationBeforeCreateDependencies,
    Record.CreateBeforeCreateDependencies);
  }
 }
 return true;
}

UUE427OracleCommandlet::UUE427OracleCommandlet() {
 IsClient = false; IsServer = false; IsEditor = true; LogToConsole = true;
}
int32 UUE427OracleCommandlet::Main(const FString& Params) {
 FString OptionalSourceDir;
 if(FParse::Value(*Params,TEXT("P05OptionalTextureDir="),OptionalSourceDir))
  return UE427GenerateCookableOptionalTexture(OptionalSourceDir)?0:23;
 if(FParse::Param(*Params,TEXT("P05OwnerProbe")))return UE427ProbeP05OwnerLinker()?0:21;
 if(FParse::Param(*Params,TEXT("P05OwnerRuntime")))return UE427ProbeP05OwnerRuntime()?0:22;
 FString BulkPolicyDir;
 if (FParse::Value(*Params,TEXT("P05PolicyDir="),BulkPolicyDir)) {
  if(!UE427WriteP05PolicyFixtures(BulkPolicyDir))return 19;
  return UE427ProbeP05NativeRuntimeLoad(BulkPolicyDir)?0:20;
 }
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
  // Positive WITH_EDITORONLY_DATA filtered UE4.27 import branch.
  // Header is written by Epic FPackageFileSummary; name and import
  // wire records use Epic's FMemoryWriter primitive serialization.
  {
   FPackageFileSummary Filtered;
   Filtered.Tag = PACKAGE_FILE_TAG;
   Filtered.SetFileVersions(522,0,false);
   Filtered.TotalHeaderSize = 4096;
   Filtered.PackageFlags = (uint32)PKG_FilterEditorOnly;
   Filtered.NameCount = 1;
   Filtered.NameOffset = 512;
   Filtered.ImportCount = 1;
   Filtered.ImportOffset = 1024;
   TArray<uint8> Data;
   {
    FMemoryWriter Writer(Data,true);
    Writer << Filtered;
    if (Writer.IsError() || Data.Num() > 512) return 10;
    Data.SetNumZeroed(4096);
    Writer.Seek(512);
    int32 NameLength = 7;
    Writer << NameLength;
    const ANSICHAR NameBytes[7] = {'O','b','j','e','c','t',0};
    Writer.Serialize((void*)NameBytes,7);
    uint16 ZeroHash = 0;
    Writer << ZeroHash;
    Writer << ZeroHash;
    Writer.Seek(1024);
    int32 ZeroWord = 0;
    for (int32 Word=0;Word<7;++Word) Writer << ZeroWord;
    if (Writer.IsError()) return 11;
   }
   const FString Path = FPaths::Combine(FixturesDir,TEXT("native-filtered-import.uasset"));
   if (!FFileHelper::SaveArrayToFile(Data,*Path)) return 12;
   UE_LOG(LogTemp,Display,TEXT("UE427_FIXTURE_WRITTEN|native-filtered-import.uasset|0|0|4096"));
  }
  // Positive signed FPackageIndex preload arrays in both archive endians.
  for (int32 Swap=0;Swap<2;++Swap) {
   FPackageFileSummary Summary;
   Summary.Tag=PACKAGE_FILE_TAG;
   Summary.SetFileVersions(522,0,false);
   Summary.TotalHeaderSize=4096;
   Summary.PreloadDependencyCount=3;
   Summary.PreloadDependencyOffset=512;
   TArray<uint8> Data;
   {
    FMemoryWriter Writer(Data,true);
    Writer.SetByteSwapping(Swap!=0);
    Writer << Summary;
    if (Writer.IsError() || Data.Num()>512) return 14;
    Data.SetNumZeroed(4096);
    Writer.Seek(512);
    FPackageIndex Import=FPackageIndex::FromImport(0);
    FPackageIndex Null;
    FPackageIndex Export=FPackageIndex::FromExport(0);
    Writer << Import;
    Writer << Null;
    Writer << Export;
    if (Writer.IsError()) return 15;
   }
   const FString Name=Swap?TEXT("native-swapped-preload.uasset"):TEXT("native-little-preload.uasset");
   const FString Path=FPaths::Combine(FixturesDir,Name);
   if (!FFileHelper::SaveArrayToFile(Data,*Path)) return 16;
   UE_LOG(LogTemp,Display,TEXT("UE427_FIXTURE_WRITTEN|%s|%d|0|4096"),*Name,Swap);
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
  if (FParse::Param(*Params,TEXT("P05Bulk")) && !UE427ProbeCookedBulkHeaders(Path,Checked)) return 17;
  if (FParse::Param(*Params,TEXT("P05Optional")) && !UE427ProbeCookedOptionalHeaders(Path,Checked)) return 24;
  if (FParse::Param(*Params,TEXT("P05"))) {
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_HEADER|%d|%d|%lld|%d"),
    Checked,S.TotalHeaderSize,S.BulkDataStartOffset,S.ExportCount);
  }
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
   if (S.NameCount == 0) {
    const TArray<FNameEntryId> EmptyNameMap;
    if (FParse::Param(*Params,TEXT("Records")) &&
      !UE427DumpRecordMaps(*Ar,S,EmptyNameMap,Checked)) return 9;
    if (FParse::Param(*Params,TEXT("P04")) &&
      !UE427DumpP04(*Ar,S,EmptyNameMap,Checked)) return 13;
   }
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
    if (FParse::Param(*Params,TEXT("Records"))) {
     if (!UE427DumpRecordMaps(*Ar,S,MappedNames,Checked)) return 9;
    }
    if (FParse::Param(*Params,TEXT("P04"))) {
     if (!UE427DumpP04(*Ar,S,MappedNames,Checked)) return 13;
    }
    if (S.ImportCount > 0 && S.ImportOffset > 0 && !FParse::Param(*Params,TEXT("Records"))) {
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
