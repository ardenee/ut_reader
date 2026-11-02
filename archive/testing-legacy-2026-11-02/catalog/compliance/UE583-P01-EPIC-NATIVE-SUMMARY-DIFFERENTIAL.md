# P01 — independent native Epic classic summary differential

Pinned UE5.8.3 build `H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe`, invoking `-j PackageInfo -p <asset> --summary`, independently decodes real `H:/UE5-583-runtime-probe/Engine/Content` `.uasset/.umap` files. Verifier: `catalog/bin/verify-ue5-583-epic-classic-native-summary.php` invokes native Epic process per package, obtains UnrealDB `UE5/UnrealPackageReader.php` decoded header and compares matching fields.

**Result 2026-10-19: 140 packages, 5,279 independently compared decoded summary fields, 0 mismatches.** Observed UE5 versions 1004 (109), 1006 (4), 1007 (1), 1009 (1), 1010 (1), 1012 (1), 1018 (23). Exit 0.

Comparison normalization only: Epic's JSON `Guid`/`PersistentGuid` omits separators versus UnrealDB; its JSON `SavedHash` stores a Base64 Data wrapper versus UnrealDB hex, converted to binary-equivalent hex. Epic's displayed `Tag` and `LegacyFileVersion` may reflect normalized in-memory values (a -8 source asset prints -9), not raw serialized wire values; these two fields are explicitly excluded from direct equality while their wire admission branches remain source-reviewed and covered by the existing reader contract.

Boundaries: this is independent **summary field** evidence, not equivalent decoded name/import/export rows, not a guarantee for all historical UE formats, and not proof of production workflow acceptance. The source-branch matrix retains supported-layout and editor/runtime policy distinctions. No production content was modified.
