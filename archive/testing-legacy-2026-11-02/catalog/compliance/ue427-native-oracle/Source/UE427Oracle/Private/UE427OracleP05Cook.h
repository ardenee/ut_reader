#pragma once
#include "Engine/Texture2D.h"
#include "Engine/Texture.h"
#include "UObject/Package.h"
#include "UObject/UObjectGlobals.h"
#include "Misc/PackageName.h"
#include "Misc/Paths.h"
#include "HAL/FileManager.h"

static bool UE427GenerateCookableOptionalTexture(const FString& Root)
{
 const FString Name=TEXT("/Game/UE427PayloadProbe/OptionalTexture");
 UPackage* Pkg=CreatePackage(*Name);
 if(!Pkg)return false;
 UTexture2D* Texture=NewObject<UTexture2D>(Pkg,TEXT("OptionalTexture"),RF_Public|RF_Standalone);
 if(!Texture)return false;
 constexpr int32 Dim=2048;
 Texture->Source.Init(Dim,Dim,1,1,TSF_BGRA8,nullptr);
 uint8* Pixels=Texture->Source.LockMip(0,0,0);
 if(!Pixels)return false;
 for(int32 Y=0;Y<Dim;Y++) {
  for(int32 X=0;X<Dim;X++) {
   const int32 P=4*(Y*Dim+X);
   Pixels[P+0]=(uint8)(X&255);
   Pixels[P+1]=(uint8)(Y&255);
   Pixels[P+2]=(uint8)((X^Y)&255);
   Pixels[P+3]=255;
  }
 }
 Texture->Source.UnlockMip(0,0,0);
 Texture->MipGenSettings=TMGS_SimpleAverage;
 Texture->LODGroup=TEXTUREGROUP_World;
 Texture->NeverStream=false;
 Texture->CompressionSettings=TC_Default;
 Texture->SRGB=true;
 Texture->PostEditChange();
 Pkg->MarkPackageDirty();
 const FString File=FPaths::Combine(Root,TEXT("OptionalTexture.uasset"));
 if(!IFileManager::Get().MakeDirectory(*Root,true))return false;
 const bool Good=UPackage::SavePackage(Pkg,Texture,RF_Public|RF_Standalone,*File,GError,
   nullptr,false,true,SAVE_NoError);
 UE_LOG(LogTemp,Display,TEXT("UE427_NATIVE_OPTIONAL_SOURCE|%d|%d|%s"),
   Good?1:0,Dim,*File);
 return Good;
}
