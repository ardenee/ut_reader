#pragma once
#include "AssetRegistry/AssetData.h"
#include "Misc/PackageName.h"
#include "Misc/Paths.h"
#include "UObject/PackageFileSummary.h"

static FString UE427P06Hex(const FString& Value)
{
 const FTCHARToUTF8 Text(*Value);
 return BytesToHex((const uint8*)Text.Get(),Text.Length());
}

// Run Epic's compiled FAssetData constructors and tag-map implementation.
// Filtering/legacy branching is source-shaped after PackageReader.cpp lines
// 632-751; it is not an invocation of that private non-exported function.
static bool UE427DumpP06SemanticAssetData(FArchive& Ar,const FPackageFileSummary& S,
 int32 Index,const FString& Filename)
{
 FString LongPackagePath=FPaths::ConvertRelativePathToFull(Filename);
 const FString EngineRoot=FPaths::ConvertRelativePathToFull(FPaths::EngineContentDir());
 const FString ProjectRoot=FPaths::ConvertRelativePathToFull(FPaths::ProjectContentDir());
 const FString FixtureRoot=FPaths::ConvertRelativePathToFull(
   FPaths::Combine(FPaths::ProjectSavedDir(),TEXT("UE427AssetFixtures/")));
 FPaths::NormalizeFilename(LongPackagePath);
 FString Relative;FString Mount;
 if(LongPackagePath.StartsWith(EngineRoot,ESearchCase::IgnoreCase)){
  Relative=LongPackagePath.Mid(EngineRoot.Len());Mount=TEXT("/Engine/");
 }else if(LongPackagePath.StartsWith(ProjectRoot,ESearchCase::IgnoreCase)){
  Relative=LongPackagePath.Mid(ProjectRoot.Len());Mount=TEXT("/Game/");
 }else if(LongPackagePath.StartsWith(FixtureRoot,ESearchCase::IgnoreCase)){
  Relative=LongPackagePath.Mid(FixtureRoot.Len());Mount=TEXT("/Game/UE427AssetFixtures/");
 }else{
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_P06_ASSET_ERROR|%d|unknown_root|%s"),Index,*LongPackagePath);
  return false;
 }
 Relative=FPaths::ChangeExtension(Relative,TEXT(""));
 const FString PackageName=Mount+Relative;
 const FString PackagePath=FPackageName::GetLongPackagePath(PackageName);
 const FString PackageAssetName=FPackageName::GetLongPackageAssetName(PackageName);
 if(S.AssetRegistryDataOffset<=0 || S.AssetRegistryDataOffset>=Ar.TotalSize())return false;
 Ar.Seek(S.AssetRegistryDataOffset);
 const bool bPre=S.GetFileVersionUE4()<VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS ||
   !!(S.PackageFlags & PKG_FilterEditorOnly);
 if(!bPre){int64 DepOffset=-1;Ar<<DepOffset;}
 int32 ObjectCount=0;Ar<<ObjectCount;
 if(Ar.IsError()||ObjectCount<0||ObjectCount>(Ar.TotalSize()-Ar.Tell())/4)return false;
 int32 OutCount=0;
 auto Emit=[&](int32 SourceIndex,FAssetData& Asset,bool Synth){
   FString ChunkCsv;
   for(int32 J=0;J<Asset.ChunkIDs.Num();++J){
    if(J)ChunkCsv+=TEXT(",");
    ChunkCsv+=FString::FromInt(Asset.ChunkIDs[J]);
   }
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_ASSET|%d|%d|%s|%s|%s|%s|%s|%s|%u|%d|%d"),
    Index,SourceIndex,*UE427P06Hex(Asset.PackageName.ToString()),
    *UE427P06Hex(Asset.PackagePath.ToString()),
    *UE427P06Hex(Asset.ObjectPath.ToString()),
    *UE427P06Hex(Asset.AssetName.ToString()),
    *UE427P06Hex(Asset.AssetClass.ToString()),
    *ChunkCsv,Asset.PackageFlags,Asset.TagsAndValues.Num(),Synth?1:0);
   for(const auto& T : Asset.TagsAndValues){
    UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_ASSET_TAG|%d|%d|%s|%s"),
     Index,SourceIndex,*UE427P06Hex(T.Key.ToString()),*UE427P06Hex(T.Value.AsString()));
   }
   ++OutCount;
 };
 const bool bMap=(S.PackageFlags & PKG_ContainsMap)!=0;
 if(bMap && (S.GetFileVersionUE4()<VER_UE4_PUBLIC_WORLDS||ObjectCount==0)){
  FAssetData World(FName(*PackageName),FName(*PackagePath),
    FName(*PackageAssetName),FName(TEXT("World")),FAssetDataTagMap(),
    S.ChunkIDs,S.PackageFlags);
  Emit(-1,World,true);
 }
 for(int32 I=0;I<ObjectCount;++I){
  FString Obj,Cls;int32 NumTags=0;
  Ar<<Obj;Ar<<Cls;Ar<<NumTags;
  if(Ar.IsError()||NumTags<0||NumTags>(Ar.TotalSize()-Ar.Tell())/8)return false;
  FAssetDataTagMap Tags;
  for(int32 T=0;T<NumTags;++T){
   FString K,V;Ar<<K;Ar<<V;
   if(Ar.IsError())return false;
   if(!K.IsEmpty()&&!V.IsEmpty())Tags.Add(FName(*K),V);
  }
  if(bMap && S.GetFileVersionUE4()<VER_UE4_PUBLIC_WORLDS && Obj!=PackageAssetName)continue;
  if(!Obj.StartsWith(TEXT("/"),ESearchCase::CaseSensitive)){
   if(Obj.Contains(TEXT("."),ESearchCase::CaseSensitive))continue;
   Obj=PackageName+TEXT(".")+Obj;
  }else if(S.GetFileVersionUE4()<VER_UE4_NON_OUTER_PACKAGE_IMPORT)continue;
  FAssetData Asset(PackageName,Obj,FName(*Cls),MoveTemp(Tags),S.ChunkIDs,S.PackageFlags);
  Emit(I,Asset,false);
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_ASSET_COUNT|%d|%d|%d|%d|%d"),
   Index,OutCount,ObjectCount,VER_UE4_PUBLIC_WORLDS,VER_UE4_NON_OUTER_PACKAGE_IMPORT);
 return true;
}

/** Actual Epic FPackageFileSummary and FMemoryWriter source-shaped v522
 * package fixtures with a standalone serialized AssetRegistry main section.
 * Written solely inside disposable C:/Temp/UE427Oracle/Content.
 */
static bool UE427MakeP06SemanticFixtures(const FString& Dir)
{
 IFileManager::Get().MakeDirectory(*Dir,true);
 struct FCase{const TCHAR* Name;uint32 Flags;int32 Mode;};
 const FCase Cases[]={
  {TEXT("WorldWithoutRows"),PKG_ContainsMap,0},
  {TEXT("WorldWithRows"),PKG_ContainsMap,1},
  {TEXT("RelativeSubObject"),0,2}
 };
 for(const FCase& C:Cases){
  FPackageFileSummary S;
  S.Tag=PACKAGE_FILE_TAG;
  S.SetFileVersions(522,0,false);
  S.TotalHeaderSize=4096;S.AssetRegistryDataOffset=1024;
  S.PackageFlags=C.Flags;
  S.ChunkIDs.Add(3);S.ChunkIDs.Add(7);
  TArray<uint8> Data;
  FMemoryWriter Ar(Data,true);Ar.SetUE4Ver(522);Ar<<S;
  if(Ar.IsError()||Ar.Tell()>1024)return false;
  Data.SetNumZeroed(4096);Ar.Seek(1024);
  int64 DepOffset=-1;Ar<<DepOffset;
  int32 Count=C.Mode==0?0:(C.Mode==1?2:1);Ar<<Count;
  auto WriteRow=[&](const FString& Obj,const FString& Cls,bool WithTags){
   FString O=Obj,K=Cls;Ar<<O;Ar<<K;
   int32 Tags=WithTags?3:0;Ar<<Tags;
   if(WithTags){
    FString Name=TEXT("Title");
    FString Value=TEXT("NSLOCTEXT(\"\", \"ExampleId\", \"Native Example\")");
    Ar<<Name;Ar<<Value;
    Name=TEXT("Skip");Value=TEXT("");
    Ar<<Name;Ar<<Value;
    Name=TEXT("");Value=TEXT("Skip");
    Ar<<Name;Ar<<Value;
   }
  };
  if(C.Mode==1){
   WriteRow(TEXT("WorldWithRows"),TEXT("World"),true);
   WriteRow(TEXT("/Game/External/ExternalObject.ExternalObject"),TEXT("Material"),false);
  }else if(C.Mode==2){
   WriteRow(TEXT("Inner.Object"),TEXT("Actor"),false);
  }
  if(Ar.IsError()||Ar.Tell()>4096)return false;
  const FString File=FPaths::Combine(Dir,FString(C.Name)+TEXT(".uasset"));
  if(!FFileHelper::SaveArrayToFile(Data,*File))return false;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_ASSET_FIXTURE|%s|%lld|%d"),*File,Ar.Tell(),Count);
 }
 return true;
}
