#pragma once
#include "Misc/ObjectThumbnail.h"
#include "Misc/SecureHash.h"
#include "UObject/PackageFileSummary.h"
#include "Serialization/Archive.h"

// Positive payload checks use Epic's compiled FObjectThumbnail::Serialize,
// not a reimplementation of its compressed image record.
static bool UE427DumpP06ThumbnailPayloads(FArchive& Ar,const FPackageFileSummary& S,int32 Index)
{
 if(S.ThumbnailTableOffset<=0)return true;
 if(S.ThumbnailTableOffset>Ar.TotalSize()-4)return false;
 Ar.Seek(S.ThumbnailTableOffset);
 int32 Num=0;Ar<<Num;
 if(Ar.IsError()||Num<0||Num>(Ar.TotalSize()-Ar.Tell())/12)return false;
 TArray<int32> Offsets;
 Offsets.Reserve(Num);
 for(int32 I=0;I<Num;++I){
  FString Class,Path;int32 Offset=0;
  Ar<<Class;Ar<<Path;Ar<<Offset;
  if(Ar.IsError()||Offset<0||Offset>=Ar.TotalSize())return false;
  Offsets.Add(Offset);
 }
 for(int32 I=0;I<Offsets.Num();++I){
  Ar.Seek(Offsets[I]);
  FObjectThumbnail Thumb;
  Thumb.Serialize(Ar);
  if(Ar.IsError())return false;
  const TArray<uint8>& Bytes=Thumb.AccessCompressedImageData();
  uint8 Digest[FSHA1::DigestSize]={0};
  const uint8 Empty=0;
  FSHA1::HashBuffer(Bytes.Num()?Bytes.GetData():&Empty,Bytes.Num(),Digest);
  const FString Hash=BytesToHex(Digest,FSHA1::DigestSize);
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_IMAGE|%d|%d|%d|%d|%d|%d|%s|%lld"),
   Index,I,Offsets[I],Thumb.GetImageWidth(),Thumb.GetImageHeight(),
   Bytes.Num(),*Hash,Ar.Tell());
 }
 return true;
}
