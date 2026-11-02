# S001 — Epic FObjectExport RF_Load masking corrected (2026-10-19)

Pinned Epic `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp` (FObjectExport deserializer, `E.ObjectFlags = EObjectFlags(Save & RF_Load)`) and `Engine/Source/Runtime/CoreUObject/Public/UObject/ObjectMacros.h` define `RF_Load` to include RF_Public, RF_Standalone, RF_Transactional, RF_ClassDefaultObject, RF_ArchetypeObject, RF_GeneratePublicHash, RF_DefaultSubObject, RF_TextExportTransient, RF_InheritableComponentTemplate, RF_DuplicateTransient, RF_NonPIEDuplicateTransient, RF_MigratingAsset and RF_HasDynamicImports. Their 32-bit mask is **0xA2D4083B**.

**Before**: UnrealDB `UE5/UnrealPackageReader.php::readExports` stored raw 32-bit serialized flags under `objectFlags`, and its V5 snapshot asserted misleading `RF_Load` semantics without applying the mask.

**After**: `serializedObjectFlags` preserves raw u32; `objectFlags` holds `serializedObjectFlags & 0xA2D4083B`, following Epic. `Uedb5Ue5ClassicSnapshotBuilder.php` serializes effective `object_flags` and independent `object_flags_raw_serialized`, documenting exact semantics and width. Original asset is unchanged.

Independent mutation fixture `catalog/bin/verify-ue5-583-s001-export-rf-load.php`: UE5.8.3 real `Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset` serialized `0000000B`, injected non-RF_Load `RF_Transient` produces `0000004B`; effective flags remain `0000000B` and raw retained; **EXIT 0**. Also 119 real classic V5 roundtrips, no issues, EXIT 0. S001 resolved in production code. The remaining P02 incomplete native dependency identity projection is not cleared by this fix.
