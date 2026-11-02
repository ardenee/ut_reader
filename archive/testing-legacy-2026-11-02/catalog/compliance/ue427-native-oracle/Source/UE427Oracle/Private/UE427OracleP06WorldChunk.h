#pragma once
#include "UObject/PackageFileSummary.h"
#include "UObject/FortniteMainBranchObjectVersion.h"
#include "Misc/WorldCompositionUtility.h"
#include "Serialization/MemoryWriter.h"
#include "Serialization/MemoryReader.h"
#include "Misc/FileHelper.h"
#include "Misc/Paths.h"

static bool UE427MakeP06WorldChunkFixtures(const FString& Dir)
{
 IFileManager::Get().MakeDirectory(*Dir,true);
 for(int32 Swap=0;Swap<2;++Swap){
  FCustomVersionContainer Versions;
  Versions.SetVersion(FFortniteMainBranchObjectVersion::GUID,
    FFortniteMainBranchObjectVersion::LatestVersion,TEXT("FortniteMainBranch"));
  FPackageFileSummary S;
  S.Tag=PACKAGE_FILE_TAG;S.SetFileVersions(522,0,false);
  // The setter is declared but not exported from the installed UE4.27 DLL.
  // This is an isolated writer fixture, not an engine binary/source edit.
  const_cast<FCustomVersionContainer&>(S.GetCustomVersionContainer()).SetVersion(
    FFortniteMainBranchObjectVersion::GUID,
    FFortniteMainBranchObjectVersion::LatestVersion,TEXT("FortniteMainBranch"));
  S.TotalHeaderSize=4096;
  S.WorldTileInfoDataOffset=1024;
  S.ChunkIDs.Add(3);S.ChunkIDs.Add(7);S.ChunkIDs.Add(19);
  TArray<uint8> Data;
  FMemoryWriter Writer(Data,true);
  Writer.SetByteSwapping(Swap!=0);
  Writer.SetUE4Ver(522);Writer.SetCustomVersions(Versions);
  Writer<<S;
  if(Writer.IsError()||Data.Num()>1024)return false;
  Data.SetNumZeroed(4096);Writer.Seek(1024);
  FWorldTileInfo Tile;
  Tile.Position=FIntVector(12,-34,56);
  Tile.Bounds=FBox(FVector(-100,-200,-300),FVector(100,200,300));
  Tile.Layer.Name=TEXT("TestLayer");
  Tile.Layer.StreamingDistance=75000;
  Tile.Layer.DistanceStreamingEnabled=false;
  Tile.ParentTilePackageName=TEXT("/Game/Map/Parent");
  Tile.bHideInTileView=true;
  Tile.ZOrder=-7;
  FWorldTileLODInfo LOD; LOD.RelativeStreamingDistance=12345;Tile.LODList.Add(LOD);
  Writer<<Tile;
  if(Writer.IsError()||Writer.Tell()>4096)return false;
  const FString Name=Swap?TEXT("world-chunk-swapped.uasset"):TEXT("world-chunk-little.uasset");
  const FString Path=FPaths::Combine(Dir,Name);
  if(!FFileHelper::SaveArrayToFile(Data,*Path))return false;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_WORLD_FIXTURE|%s|%d|%d|%d"),*Name,Swap,Data.Num(),(int32)Writer.Tell());
 }
 return true;
}

static bool UE427ReadP06WorldChunk(FArchive& Ar,const FPackageFileSummary& S,int32 Index)
{
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_WORLD_HEAD|%d|%d|%d"),Index,S.WorldTileInfoDataOffset,S.ChunkIDs.Num());
 for(int32 I=0;I<S.ChunkIDs.Num();++I)
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_CHUNK|%d|%d|%d"),Index,I,S.ChunkIDs[I]);
 if(S.WorldTileInfoDataOffset<=0)return true;
 if(S.WorldTileInfoDataOffset>=Ar.TotalSize())return false;
 Ar.SetUE4Ver(S.GetFileVersionUE4());Ar.SetCustomVersions(S.GetCustomVersionContainer());
 Ar.Seek(S.WorldTileInfoDataOffset);
 FWorldTileInfo Tile; Ar<<Tile;
 if(Ar.IsError())return false;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_WORLD|%d|%d|%d|%d|%s|%d|%d|%s|%d|%d|%lld"),
  Index,Tile.Position.X,Tile.Position.Y,Tile.Position.Z,*Tile.Layer.Name,
  Tile.Layer.StreamingDistance,Tile.Layer.DistanceStreamingEnabled?1:0,
  *Tile.ParentTilePackageName,Tile.bHideInTileView?1:0,Tile.ZOrder,Ar.Tell());
 return true;
}
