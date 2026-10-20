#pragma once
#include "Containers/BitArray.h"
#include "UObject/PackageFileSummary.h"
#include "Serialization/Archive.h"

// This invokes compiled Epic TBitArray<FDefaultBitArrayAllocator>::operator<<
// exactly as AssetRegistry/Private/PackageReader.cpp::ReadPackageDataDependencies.
static bool UE427DumpP06RegistryDependencies(FArchive& Ar,const FPackageFileSummary& S,int32 Index)
{
 const int64 At=S.AssetRegistryDataOffset;
 if(At<=0 || At>Ar.TotalSize()-4)return true;
 const bool bPre=S.GetFileVersionUE4()<VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS
                 || !!(S.PackageFlags & PKG_FilterEditorOnly);
 if(bPre)return true;
 Ar.Seek(At);
 int64 DepOffset=-1;Ar<<DepOffset;
 if(Ar.IsError())return false;
 if(DepOffset==-1){
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_DEP|%d|-1|-1||-1||-1"),Index);
  return true;
 }
 if(DepOffset<=0 || DepOffset>Ar.TotalSize()-8)return false;
 Ar.Seek(DepOffset);
 TBitArray<> Imported,Soft;
 Ar<<Imported;
 Ar<<Soft;
 if(Ar.IsError())return false;
 FString IB,SB;
 IB.Reserve(Imported.Num());SB.Reserve(Soft.Num());
 for(int32 I=0;I<Imported.Num();++I)IB.AppendChar(Imported[I]?TEXT('1'):TEXT('0'));
 for(int32 I=0;I<Soft.Num();++I)SB.AppendChar(Soft[I]?TEXT('1'):TEXT('0'));
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_DEP|%d|%lld|%d|%s|%d|%s|%lld"),
   Index,DepOffset,Imported.Num(),*IB,Soft.Num(),*SB,Ar.Tell());
 return true;
}
