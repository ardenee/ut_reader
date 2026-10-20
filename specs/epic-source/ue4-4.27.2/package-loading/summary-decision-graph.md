# UE4.27.2: Epic summary decision-path view

Source-only selected branches from `PackageFileSummary.cpp`.
This is a focused decision graph, **not** the complete FPackageFileSummary
serialization function. Skipped serialization fields remain in
`source-reference.md` and the original pinned Epic source.

Source SHA-256: `8FF60DD29D5A0E50EB1222DA0D9C5DF71E088A1F2641E8512E6F6CE05CBA4146`

| Node | Source location | Condition/action | Outgoing branch |
|---|---|---|---|
| `B01` | `PackageFileSummary.cpp:48` | `void operator<<(FStructuredArchive::FSlot Slot, FPackageFileSummary& Sum)` | `B02` |
| `B02` | `PackageFileSummary.cpp:71` | `if (Sum.Tag == PACKAGE_FILE_TAG \|\| Sum.Tag == PACKAGE_FILE_TAG_SWAPPED)` | yes: `B03`, no: `B90` |
| `B03` | `PackageFileSummary.cpp:75` | `if (Sum.Tag == PACKAGE_FILE_TAG_SWAPPED)` | yes: `B04`, no: `B00` |
| `B04` | `PackageFileSummary.cpp:86` | `BaseArchive.SetByteSwapping(true);` | `B00` |
| `B00` | `PackageFileSummary.cpp:106` | `if (BaseArchive.IsLoading())` | loading: `B05`, saving: `B99` |
| `B05` | `PackageFileSummary.cpp:108` | `if (LegacyFileVersion < 0) // means we have modern version numbers` | yes: `B06`, no: `B91` |
| `B06` | `PackageFileSummary.cpp:110` | `if (LegacyFileVersion < CurrentLegacyFileVersion)` | yes: `B92`, no: `B07` |
| `B07` | `PackageFileSummary.cpp:119` | `if (LegacyFileVersion != -4)` | yes: `B08`, no: `B09` |
| `B08` | `PackageFileSummary.cpp:122` | `Record << SA_VALUE(TEXT("LegacyUE3Version"), LegacyUE3Version);` | `B09` |
| `B09` | `PackageFileSummary.cpp:124` | `Record << SA_VALUE(TEXT("FileVersionUE4"), Sum.FileVersionUE4);` | `B10` |
| `B10` | `PackageFileSummary.cpp:127` | `if (LegacyFileVersion <= -2)` | yes: `B11`, no: `B12` |
| `B11` | `PackageFileSummary.cpp:129` | `Sum.CustomVersionContainer.Serialize(Record.EnterField(SA_FIELD_NAME(TEXT("CustomVersions"))), GetCustomVersionFormatForArchive(LegacyFileVersion));` | `B12` |
| `B12` | `PackageFileSummary.cpp:132` | `if (!Sum.FileVersionUE4 && !Sum.FileVersionLicenseeUE4)` | yes: `B13`, no: `B14` |
| `B13` | `PackageFileSummary.cpp:135` | `if (!GAllowUnversionedContentInEditor)` | yes: `B93`, no: `B96` |
| `B96` | `PackageFileSummary.cpp:144` | `Sum.FileVersionUE4 = GPackageFileUE4Version;` | `B14` |
| `B14` | `PackageFileSummary.cpp:180` | `Record << SA_VALUE(TEXT("TotalHeaderSize"), Sum.TotalHeaderSize);` | `B15` |
| `B15` | `PackageFileSummary.cpp:212` | `if (BaseArchive.IsLoading() && (Sum.FileVersionUE4 < VER_UE4_OLDEST_LOADABLE_PACKAGE \|\| Sum.FileVersionUE4 > GPackageFileUE4Version))` | yes: `B94`, no: `B16` |
| `B16` | `PackageFileSummary.cpp:320` | `Record << SA_VALUE(TEXT("CompressionFlags"), Sum.CompressionFlags);` | `B97` |
| `B97` | `PackageFileSummary.cpp:321` | `if (!FCompression::VerifyCompressionFlagsValid(Sum.CompressionFlags))` | valid: `B17`, invalid: `B98` |
| `B17` | `PackageFileSummary.cpp:331` | `if (CompressedChunks.Num())` | yes: `B95`, no: `B18` |
| `B18` | `PackageFileSummary.cpp:354` | `Record << SA_VALUE(TEXT("AssetRegistryDataOffset"), Sum.AssetRegistryDataOffset);` | Terminal |
| `B90` | `PackageFileSummary.cpp:391` | `}` | Terminal |
| `B91` | `PackageFileSummary.cpp:152` | `Sum.FileVersionUE4 = 0;` | `B14` |
| `B92` | `PackageFileSummary.cpp:116` | `return;` | Terminal |
| `B93` | `PackageFileSummary.cpp:139` | `return;` | Terminal |
| `B94` | `PackageFileSummary.cpp:214` | `return; // we can't safely load more than this because the below was different in older files.` | Terminal |
| `B95` | `PackageFileSummary.cpp:336` | `return; // we can't safely load more than this because we just changed the version to something it is not.` | Terminal |
| `B99` | `PackageFileSummary.cpp:171` | `int32 LegacyUE3Version = 864;` | Terminal |
| `B98` | `PackageFileSummary.cpp:325` | `return;` | Terminal |
