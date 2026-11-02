#pragma once
#include "Serialization/BulkData.h"
#include "Serialization/MemoryWriter.h"
#include "Serialization/BulkDataCommon.h"
#include "Misc/FileHelper.h"
#include "Misc/Paths.h"
#include "UObject/UObjectGlobals.h"
#include "UObject/LinkerLoad.h"

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

static bool UE427ProbeP05OwnerLinker()
{
 UObject* Owner=StaticLoadObject(UObject::StaticClass(),nullptr,TEXT("/Game/UE427PayloadProbe/Cube.Cube"));
 if(!Owner) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_OWNER_ERROR|missing_cube"));
  return false;
 }
 FLinkerLoad* Linker=Owner->GetLinker();
 if(!Linker) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_OWNER_ERROR|linker_missing|%s"),*Owner->GetPathName());
  return false;
 }
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_OWNER|%s|%s|%lld|%d"),
  *Owner->GetPathName(),*Linker->Filename,Linker->Summary.BulkDataStartOffset,
  Owner->GetLinkerIndex());
 return true;
}

class FUE427OwnerMemoryReader final : public FMemoryReader
{
public:
 FUE427OwnerMemoryReader(const TArray<uint8>& Data,FArchive* InCache)
  :FMemoryReader(Data,true),Cache(InCache) { ArAllowLazyLoading=true; }
 virtual FArchive* GetCacheableArchive() override {return Cache;}
private:
 FArchive* Cache;
};

static bool UE427ProbeP05OwnerRuntime()
{
 UObject* Owner=StaticLoadObject(UObject::StaticClass(),nullptr,TEXT("/Game/UE427PayloadProbe/Cube.Cube"));
 if(!Owner||!Owner->GetLinker())return false;
 FLinkerLoad* Linker=Owner->GetLinker();
 const int64 BulkFixup=Linker->Summary.BulkDataStartOffset;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_OWNER_HEADER|%s|%s|%lld"),*Owner->GetPathName(),*Linker->Filename,BulkFixup);
 struct FCase {
  const TCHAR* Name; uint32 Flags;
  const TCHAR* ExpectedExtension; bool bOptionalExists;
  bool bTryMap; bool bExpectPayload;
 };
 const FCase Cases[]={
  {TEXT("ordinary"),0x10101,TEXT(".ubulk"),false,false,false},
  {TEXT("fixed"),0x101,TEXT(".ubulk"),false,false,false},
  {TEXT("optional"),0x10801,TEXT(".uptnl"),true,false,false},
  {TEXT("mapped"),0x11101,TEXT(".m.ubulk"),false,false,false},
  {TEXT("mapped_fixed"),0x1101,TEXT(".m.ubulk"),false,false,false},
  {TEXT("large64"),0x12101,TEXT(".ubulk"),false,false,false},
  {TEXT("duplicate_optional"),0x14101,TEXT(".uptnl"),true,false,false},
  {TEXT("duplicate_fallback"),0x14101,TEXT(".ubulk"),false,false,false},
 };
 const FString UAsset=Linker->Filename;
 const FString DefaultFile=FPaths::ChangeExtension(UAsset,TEXT(".ubulk"));
 const FString OptionalFile=FPaths::ChangeExtension(UAsset,TEXT(".uptnl"));
 const FString MappedFile=FPaths::ChangeExtension(UAsset,TEXT(".m.ubulk"));
 if(IFileManager::Get().FileExists(*DefaultFile)||
    IFileManager::Get().FileExists(*OptionalFile)||
    IFileManager::Get().FileExists(*MappedFile)) {
  UE_LOG(LogTemp,Error,TEXT("UE427_NATIVE_OWNER_ERROR|existing_sidecars"));
  return false;
 }
 bool Success=true;
 int32 Passed=0;
 for(const FCase& C:Cases) {
  const FString Expected=FPaths::ChangeExtension(UAsset,C.ExpectedExtension);
  TArray<uint8> Payload;
  Payload.AddZeroed(64);
  const int64 PhysicalOffset=C.bTryMap?0:16;
  for(int32 I=0;I<16;I++)Payload[PhysicalOffset+I]=(uint8)(I+1);
  if(!FFileHelper::SaveArrayToFile(Payload,*Expected)) {Success=false;break;}
  TArray<uint8> Header;
  {
   FMemoryWriter Writer(Header,true);
   EBulkDataFlags Flags=(EBulkDataFlags)C.Flags;
   Writer << Flags;
   const bool Wide=(C.Flags&BULKDATA_Size64Bit)!=0;
   if(Wide) {int64 N=16;Writer<<N;Writer<<N;}
   else {int32 N=16;Writer<<N;Writer<<N;}
   int64 Offset=PhysicalOffset-(((C.Flags&BULKDATA_NoOffsetFixUp)==0)?BulkFixup:0);
   Writer << Offset;
   if(C.Flags&BULKDATA_DuplicateNonOptionalPayload) {
    uint32 DuplicateFlags=0x10801;
    int32 DuplicateSize=16;
    int64 DuplicateOffset=PhysicalOffset;
    Writer<<DuplicateFlags;
    Writer<<DuplicateSize;
    Writer<<DuplicateOffset;
   }
  }
  if(FCString::Strcmp(C.ExpectedExtension,TEXT(".m.ubulk"))==0) {
   IMappedFileHandle* H=FPlatformFileManager::Get().GetPlatformFile().OpenMapped(*Expected);
   IMappedFileRegion* Region=H?H->MapRegion(PhysicalOffset,16,true):nullptr;
   const uint8* P=Region?(const uint8*)Region->GetMappedPtr():nullptr;
   bool BytesMatch=P!=nullptr;
   if(P)for(int32 I=0;I<16;I++)if(P[I]!=(uint8)(I+1))BytesMatch=false;
   const uint32 Alignment=FPlatformProperties::GetMemoryMappingAlignment();
   const bool Aligned=P!=nullptr && (!Alignment || IsAligned(P,Alignment));
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_MAP_PROBE|%s|%u|%d|%d"),
      C.Name,Alignment,BytesMatch?1:0,Aligned?1:0);
   delete Region;delete H;
   // Native MapRegion is an optional diagnostic; streaming checks below
   // are separately required and must all succeed.
  }
  {
   FUE427OwnerMemoryReader Reader(Header,Linker);
   FByteBulkData Bulk;
   Bulk.Serialize(Reader,Owner,INDEX_NONE,C.bTryMap);
   const FString Actual=Bulk.GetFilename();
   const int64 Offset=Bulk.GetBulkDataOffsetInFile();
   const bool MapLoaded=Bulk.IsBulkDataLoaded();
   bool Good=(!Reader.IsError() && Actual==Expected && Offset==PhysicalOffset);
   if(C.bExpectPayload && Good) {
    if(!MapLoaded)Good=false;
    else {
     const uint8* Data=(const uint8*)Bulk.LockReadOnly();
     if(!Data)Good=false;
     else for(int32 I=0;I<16;I++)if(Data[I]!=I+1)Good=false;
     Bulk.Unlock();
    }
   }
   // Exercise Epic's actual asynchronous I/O request against the filename
   // and corrected offset selected by the real Owner/FLinkerLoad path.
   bool Streamed=false;
   if(Good) {
    uint8 Buffer[16]={};
    FBulkDataIORequestCallBack Callback=[](bool,IBulkDataIORequest*){};
    TUniquePtr<IBulkDataIORequest> Request(
      Bulk.CreateStreamingRequest(0,16,AIOP_Normal,&Callback,Buffer));
    if(Request && Request->WaitCompletion(10.0f)) {
     Streamed=true;
     for(int32 I=0;I<16;I++)if(Buffer[I]!=(uint8)(I+1))Streamed=false;
    }
    Good=Good&&Streamed;
   }
   UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_OWNER_RUNTIME|%s|%u|%lld|%lld|%d|%d|%d|%s"),
      C.Name,C.Flags,Offset,BulkFixup,MapLoaded?1:0,Streamed?1:0,Good?1:0,*Actual);
   if(!Good)Success=false;
   else ++Passed;
   // The genuine FLinkerLoad receives AttachBulkData and provides the
   // source-defined DetachBulkData cleanup when Bulk is destroyed.
  }
  IFileManager::Get().Delete(*Expected);
  if(!Success)break;
 }
 IFileManager::Get().Delete(*DefaultFile);
 IFileManager::Get().Delete(*OptionalFile);
 IFileManager::Get().Delete(*MappedFile);
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P05_OWNER_COMPLETE|%d|%d"),Passed,(int32)UE_ARRAY_COUNT(Cases));
 return Success && Passed==(int32)UE_ARRAY_COUNT(Cases);
}
