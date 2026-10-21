# Original Unreal v0.867: pre-package Unrealfile entry and summary

Source root on DEV: `L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]`. All rules below come from this branch's original C++ source, not UnrealDB.

## Pinned source evidence (SHA-256)

| Source file | SHA-256 |
|---|---|
| `Src\Inc\UnBuild.h` | `087EEABC2FF10AD851384EFF746E71DAD521B499705E5B81FD33BB3C8D111078` |
| `Src\Inc\UnObjVer.h` | `BF3247D93E9B9A2DAA19E66EB68DE315690A460878C4F9C5445088A646DE737F` |
| `Src\Inc\UnLinker.h` | `D02F727406ED2148C285C64D8AAB2DEB69E648CB388F00034404D5F071AF2F1C` |

## Engine-defined entry sequence

1. `UnBuild.h:108` defines `UNREALFILE_TAG` as the null-terminated string `Unrealfile\x1A`.
2. `UnObjVer.h:19-23` defines file version **23** and minimum version **22**. These names are `UNREAL_FILE_VERSION` and `UNREAL_MIN_VERSION`, not later `PACKAGE_FILE_VERSION` constants.
3. `UnLinker.h:258-310` defines `FUnrealfileSummary`; the serializer writes a **fixed 32-byte character Tag**, then 32-bit `UnrealFileVersion`, `NumObjects`, `NumNames`, `NamesOffset`, `ObjectsOffset`, `NumDependencies`; then dependency filename strings. A maximum of 16 dependencies is defined. This is **not** the later `FPackageFileSummary` with separate import/export/name maps.
4. `UnLinker.h:413-426`: `ULinkerLoad` opens the original file archive and deserializes this summary.
5. `UnLinker.h:428-439`: `strcmp(Summary.Tag, UNREALFILE_TAG)` must equal zero; on mismatch the loader reports a bad file and aborts.
6. `UnLinker.h:441-454`: when the file version is older than 22, the engine asks whether to attempt loading; refusal aborts.
7. `UnLinker.h:456-497`: initialize archive flags and load the name map; `LoadAllObjects` (from line 501 onward) uses an object map with imports/exports distinguished by object state.

## Version identity and source caveat

The sibling `Unreal [v0.86x] [1997-05-25]` tree also defines `UNREALFILE_TAG = "Unrealfile\x1A"`, `UNREAL_FILE_VERSION = 23`, and `UNREAL_MIN_VERSION = 22`. Its original loader must be checked independently before asserting full equivalence.

Do not mix the older `UnrInfo.c` utility's header parser with the linker serializer without checking actual matching revisions: the available utility's header fields differ from `FUnrealfileSummary` in this engine tree.

This specification is bounded to **file identification and summary entry**, not a completed specification of every name/object/dependency operation. Older v0.82/v0.83/v0.84a directories and other supported releases require independent classification.
