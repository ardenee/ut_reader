#pragma once
#include "Serialization/BulkData.h"
#include "Serialization/MemoryWriter.h"
#include "Serialization/BulkDataCommon.h"
#include "Misc/FileHelper.h"
#include "Misc/Paths.h"

// Artificial branch fixtures, NOT cooked packages. The flags and archive
// primitive serializers below are compiled Epic UE4.27.2 code. The policy
// oracle exposes true FBulkData getters and Epic BulkDataExt file extensions;
// owner/linker behavior in BulkData.cpp still needs genuine runtime acceptance.
static bool UE427WriteP05PolicyFixtures(const FString& Root)
{
 struct FCase {
  const TCHAR* Name;
  uint32 Flags;
  int64 RawOffset;
  int64 Fixup;
  int32 PayloadOffset;
  bool bOptionalFile;
  bool bSwapped=false;
 };
 const FCase Cases[] = {
  {TEXT("inline"),(uint32)BULKDATA_ForceInlinePayload,20,0,20,false},
  {TEXT("tail"),(uint32)BULKDATA_PayloadAtEndOfFile,48,0,48,false},
  {TEXT("ordinary"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_NoOffsetFixUp),16,0,16,false},
  {TEXT("fixed"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile),-1008,1024,16,false},
  {TEXT("optional"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_OptionalPayload|BULKDATA_NoOffsetFixUp),16,0,16,false},
  {TEXT("mapped"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_MemoryMappedPayload|BULKDATA_NoOffsetFixUp),16,0,16,false},
  {TEXT("large64"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_Size64Bit|BULKDATA_NoOffsetFixUp),16,0,16,false},
  {TEXT("duplicate_optional"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_DuplicateNonOptionalPayload|BULKDATA_NoOffsetFixUp),16,0,16,true},
  {TEXT("duplicate_fallback"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_DuplicateNonOptionalPayload|BULKDATA_NoOffsetFixUp),16,0,16,false},
  {TEXT("swapped"),(uint32)(BULKDATA_PayloadAtEndOfFile|BULKDATA_PayloadInSeperateFile|BULKDATA_NoOffsetFixUp),16,0,16,false,true},
 };
 if(!IFileManager::Get().MakeDirectory(*Root,true))return false;
 for(const FCase& C:Cases) {
  FByteBulkData Bulk;
  Bulk.SetBulkDataFlags(C.Flags);
  const bool bInline=Bulk.IsInlined();
  const bool bSeparate=Bulk.IsInSeparateFile();
  const bool bOptional=Bulk.IsOptional();
  const bool bFixup=(C.Flags&BULKDATA_NoOffsetFixUp)==0 && !bInline;
  FString Ext;
  if(bInline || !bSeparate && !bOptional)Ext=TEXT(".uexp");
  else if((C.Flags&BULKDATA_DuplicateNonOptionalPayload)&&C.bOptionalFile)Ext=TEXT(".uptnl");
  else if((C.Flags&BULKDATA_DuplicateNonOptionalPayload)&&!C.bOptionalFile)Ext=TEXT(".ubulk");
  else if(bOptional)Ext=TEXT(".uptnl");
  else if((C.Flags&BULKDATA_MemoryMappedPayload)&&bSeparate)Ext=TEXT(".m.ubulk");
  else Ext=TEXT(".ubulk");
  TArray<uint8> Header;
  {
   FMemoryWriter Writer(Header,true);
   Writer.SetByteSwapping(C.bSwapped);
   EBulkDataFlags NativeFlags=(EBulkDataFlags)C.Flags;
   Writer << NativeFlags;
   if(C.Flags&BULKDATA_Size64Bit){
    int64 N=16;Writer<<N;Writer<<N;
   }else{
    int32 N=16;Writer<<N;Writer<<N;
   }
   int64 Raw=C.RawOffset; Writer<<Raw;
   if(Writer.IsError())return false;
  }
  const FString Name(C.Name);
  const FString Prefix=FPaths::Combine(Root,Name);
  const FString HeaderPath=Prefix+TEXT(".bulkmeta");
  if(!FFileHelper::SaveArrayToFile(Header,*HeaderPath))return false;
  const FString PayloadPath=FPaths::ChangeExtension(Prefix+TEXT(".uasset"),Ext);
  TArray<uint8> Data;
  const int32 N=C.PayloadOffset+16;
  Data.AddZeroed(N);
  for(int32 I=0;I<16;I++)Data[C.PayloadOffset+I]=(uint8)(I+1);
  if(!FFileHelper::SaveArrayToFile(Data,*PayloadPath))return false;
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_POLICY|%s|%u|%d|%d|%d|%d|%lld|%lld|%d|%s|%d"),
    C.Name,C.Flags,bInline?1:0,bSeparate?1:0,bOptional?1:0,bFixup?1:0,
    C.RawOffset,C.Fixup,C.PayloadOffset,*Ext,C.bSwapped?1:0);
 }
 return true;
}

static bool UE427ProbeP05NativeRuntimeLoad(const FString& Root)
{
 const TCHAR* Names[]={TEXT("inline"),TEXT("tail")};
 for(const TCHAR* Name:Names) {
  const FString Prefix=FPaths::Combine(Root,Name);
  TArray<uint8> Data,Header;
  if(!FFileHelper::LoadFileToArray(Data,*(Prefix+TEXT(".uexp"))) ||
     !FFileHelper::LoadFileToArray(Header,*(Prefix+TEXT(".bulkmeta"))))return false;
  if(Data.Num()<Header.Num() || Header.Num()!=20)return false;
  FMemory::Memcpy(Data.GetData(),Header.GetData(),Header.Num());
  FMemoryReader Ar(Data,true);
  Ar.SetIsPersistent(true);
  FByteBulkData Bulk;
  Bulk.Serialize(Ar,nullptr);
  if(Ar.IsError()||Bulk.GetBulkDataSize()!=16 || !Bulk.IsBulkDataLoaded())return false;
  const uint8* Ptr=(const uint8*)Bulk.LockReadOnly();
  if(!Ptr)return false;
  int32 Sum=0;
  for(int32 I=0;I<16;I++) {
   if(Ptr[I]!=(uint8)(I+1)){Bulk.Unlock();return false;}
   Sum+=Ptr[I];
  }
  Bulk.Unlock();
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_RUNTIME|%s|%lld|%d|%u|%lld"),
   Name,Bulk.GetBulkDataSize(),Sum,(uint32)Bulk.GetBulkDataFlags(),Bulk.GetBulkDataOffsetInFile());
 }
 return true;
}
