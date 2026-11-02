#pragma once
#include "Serialization/MemoryReader.h"
#include "Serialization/BulkDataCommon.h"
#include "Misc/FileHelper.h"
#include "Misc/Paths.h"

// Reads verified candidate raw bulk headers in Epic's genuine cooked .uexp.
// This is a bounded byte-pattern probe; full object ownership/load semantics
// require the separately tested real cooker/runtime path.
static bool UE427ProbeCookedBulkHeaders(const FString& UAsset, int32 FileIndex)
{
 const FString Stem=FPaths::GetBaseFilename(UAsset,false);
 const FString Exp=Stem+TEXT(".uexp");
 const FString Bulk=Stem+TEXT(".ubulk");
 const int64 BulkSize=IFileManager::Get().FileSize(*Bulk);
 if (BulkSize<0) {
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_BULK_COUNT|%d|0|-1"),FileIndex);
  return true;
 }
 TArray<uint8> Bytes;
 if(!FFileHelper::LoadFileToArray(Bytes,*Exp)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_BULK_ERROR|%d|uexp_missing"),FileIndex);
  return false;
 }
 FMemoryReader Reader(Bytes,true);
 int32 Rows=0;
 for(int64 P=0;P+20<=Bytes.Num();++P) {
  if(Bytes[P]!=0x01||Bytes[P+1]!=0x05||Bytes[P+2]!=0x01||Bytes[P+3]!=0x00)continue;
  Reader.Seek(P);
  EBulkDataFlags Flags=BULKDATA_None;
  int32 Count=0,Size=0;
  int64 Offset=0;
  Reader << Flags;
  Reader << Count;
  Reader << Size;
  Reader << Offset;
  if(Reader.IsError())return false;
  if((uint32)Flags!=0x10501 || Count<=0 || Count!=Size ||
      Offset<0 || Offset>BulkSize || Size>BulkSize-Offset)continue;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_BULK|%d|%d|%lld|%u|%d|%d|%lld"),
   FileIndex,Rows,P,(uint32)Flags,Count,Size,Offset);
  ++Rows;
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_BULK_COUNT|%d|%d|%lld"),
  FileIndex,Rows,BulkSize);
 return true;
}

// Exact cooker-observed optional-mip branch (0x10D01). Scan the real
// cooker output for candidate records, then deserialize all four wire fields
// through compiled Epic FMemoryReader archive operators. The accompanying
// PHP acceptance checks exact coverage and physical bytes separately.
static bool UE427ProbeCookedOptionalHeaders(const FString& UAsset, int32 FileIndex)
{
 const FString Stem=FPaths::GetBaseFilename(UAsset,false);
 const FString Exp=Stem+TEXT(".uexp");
 const FString Bulk=Stem+TEXT(".uptnl");
 const int64 BulkSize=IFileManager::Get().FileSize(*Bulk);
 if(BulkSize<0){
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_OPTIONAL_COUNT|%d|0|-1"),FileIndex);
  return true;
 }
 TArray<uint8> Bytes;
 if(!FFileHelper::LoadFileToArray(Bytes,*Exp)){
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_OPTIONAL_ERROR|%d|uexp_missing"),FileIndex);
  return false;
 }
 FMemoryReader Reader(Bytes,true);
 int32 Rows=0;
 for(int64 P=0;P+20<=Bytes.Num();++P){
  if(Bytes[P]!=0x01||Bytes[P+1]!=0x0D||Bytes[P+2]!=0x01||Bytes[P+3]!=0x00)continue;
  Reader.Seek(P);
  EBulkDataFlags Flags=BULKDATA_None;
  int32 Count=0,Size=0;
  int64 Offset=0;
  Reader<<Flags;Reader<<Count;Reader<<Size;Reader<<Offset;
  if(Reader.IsError())return false;
  if((uint32)Flags!=0x10D01 || Count<=0 || Count!=Size ||
    Offset<0 || Offset>BulkSize || Size>BulkSize-Offset)continue;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_OPTIONAL|%d|%d|%lld|%u|%d|%d|%lld"),
    FileIndex,Rows,P,(uint32)Flags,Count,Size,Offset);
  ++Rows;
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_OPTIONAL_COUNT|%d|%d|%lld"),FileIndex,Rows,BulkSize);
 return true;
}
