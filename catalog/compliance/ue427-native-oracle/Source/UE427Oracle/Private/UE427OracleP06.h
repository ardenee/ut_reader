#pragma once
#include "UObject/PackageFileSummary.h"
#include "Serialization/Archive.h"

static bool UE427DumpP06ThumbnailIndex(FArchive& Ar,const FPackageFileSummary& S,int32 FileIndex)
{
 const int64 Offset=S.ThumbnailTableOffset;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_THUMB_COUNT|%d|%lld|%d"),FileIndex,Offset,
  Offset>0 ? -1:0);
 if(Offset<=0)return true;
 if(Offset>Ar.TotalSize()-(int64)sizeof(int32))return false;
 Ar.Seek(Offset);
 int32 Num=0;Ar<<Num;
 if(Ar.IsError()||Num<0||Num>(Ar.TotalSize()-Ar.Tell())/12)return false;
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_THUMB_TOTAL|%d|%d"),FileIndex,Num);
 for(int32 I=0;I<Num;++I){
  const int64 RecordOffset=Ar.Tell();
  FString Cls,Path;int32 DataOffset=0;
  Ar<<Cls;Ar<<Path;Ar<<DataOffset;
  if(Ar.IsError())return false;
  FTCHARToUTF8 ClsUtf8(*Cls),PathUtf8(*Path);
  const FString ClsHex=BytesToHex((const uint8*)ClsUtf8.Get(),ClsUtf8.Length());
  const FString PathHex=BytesToHex((const uint8*)PathUtf8.Get(),PathUtf8.Length());
  UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_P06_THUMB|%d|%d|%lld|%s|%s|%d"),
   FileIndex,I,RecordOffset,*ClsHex,*PathHex,DataOffset);
 }
 return true;
}
