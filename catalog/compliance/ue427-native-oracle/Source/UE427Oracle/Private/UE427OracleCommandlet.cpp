#include "UE427OracleCommandlet.h"
#include "UObject/PackageFileSummary.h"
#include "HAL/FileManager.h"
#include "Misc/Paths.h"
#include "Misc/FileHelper.h"
#include "Misc/Parse.h"

UUE427OracleCommandlet::UUE427OracleCommandlet() {
 IsClient = false; IsServer = false; IsEditor = true; LogToConsole = true;
}
int32 UUE427OracleCommandlet::Main(const FString& Params) {
 FString ListPath;
 if (!FParse::Value(*Params, TEXT("List="), ListPath)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_ERROR list_missing")); return 2;
 }
 TArray<FString> Files;
 if (!FFileHelper::LoadFileToStringArray(Files,*ListPath)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_ERROR list_unreadable")); return 3;
 }
 int32 Checked=0;
 for(const FString& Path:Files) {
  TUniquePtr<FArchive> Ar(IFileManager::Get().CreateFileReader(*Path));
  if(!Ar) continue;
  FPackageFileSummary S;
  *Ar << S;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_SUMMARY|%s|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d|%d"),
   *FPaths::GetCleanFilename(Path),S.GetFileVersionUE4(),S.GetFileVersionLicenseeUE4(),
   S.TotalHeaderSize,S.NameCount,S.NameOffset,S.ImportCount,S.ImportOffset,
   S.ExportCount,S.ExportOffset,S.DependsOffset,S.SoftPackageReferencesCount,
   S.SoftPackageReferencesOffset,S.PreloadDependencyCount,S.PreloadDependencyOffset,
   S.AssetRegistryDataOffset,S.ThumbnailTableOffset,S.CompressionFlags);
  Checked++;
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_COUNT %d"),Checked);
 return Checked>0?0:4;
}