# B001 — UE5.8.3 Cooked ScriptObjects diagnostics (source-defined operation)

**Original operation:** `UE5-583-SCRIPT-001` (B001; do not create another batch). **Disposition: verified for the source-defined cooked ScriptObjects diagnostic reader only.** No claim is made about Epic's live global import store, UClass/CDO registration, asynchronous loader, runtime Verse VNI, or all UE5 workflows; separate original SCRIPT-002–005, SCRIPT-HASH and B015-1 remain under audit.

## Source anchors (pinned to local Epic UE5.8.3)

| Epic source file, under Engine/Source/Runtime | SHA256 | Behavior verified |
| --- | --- | --- |
| CoreUObject/Private/Serialization/AsyncLoading2.cpp | `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9` | `FScriptObjectEntry` serializer lines 238–245, diagnostic loader 6983–7019, `Objects.Emplace`, compiled-in debug gate, `TrimDebugData`, runtime `AddScriptObject`, `FindScriptImportObject` / `GetImportName` |
| CoreUObject/Public/Serialization/AsyncLoading2.h | `088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2` | Fixed-size mapped-name, GlobalIndex, OuterIndex, CDOClassIndex record |
| Core/Public/IO/IoChunkId.h | `64ebf25a5cf436e1cc353a8fefbd16bbb231ef28ff914a4ef6296e27b20b30b1` | Global ScriptObjects chunk type 5, package ID 0 / index 0 |
| Core/Public/Serialization/MappedName.h | `5221b85d9a40d300607e13d7146900e88a23d0894650296497014ec4f0f2787a` | `FMappedName::GetIndex()` masks lower 30 bits; enum Package=0, Container=1, Global=2 |
| Core/Public/Containers/Map.h.inl | `8abbac8d11e3988601a7e226e3ed3a082cda2ceef783a90d0434fad32b1153d0` | `TMap::Emplace` delegates to `Pairs.Emplace` |
| Core/Public/Containers/SparseSet.h.inl | `946e30739b523bc7a20a3c78c95c9a9216d6a99fd5839b82b3d318cf66c88408` | `TSet::TryReplaceExisting` overwrites a prior value for the same key |

Epic `LoadDebugData()` checks that the global chunk exists, loads the NameBatch, reads `int32 NumObjects`, then interprets exactly that many contiguous `FScriptObjectEntry` records. It constructs `NameMap[Entry.Mapped.GetIndex()].ToName(Entry.Mapped.GetNumber())`, stores `GlobalIndex -> (Name, OuterIndex)` with `Objects.Emplace`, and does not require EOF at that point. The `CDOClassIndex` is serialized but not used as runtime resolution authority. The diagnostic store is built only when `ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING` and the compiled-in registration conditions allow it, and is trimmed against the separately assembled live `ScriptObjects`. Runtime registration is handled by `FGlobalImportStore::AddScriptObject` and different original operations; it is **not** inferred from the chunk.

## Actual differences found and corrected

- **UE5-D024 (commit `2ab7af16`):** UnrealDB previously required `remaining == NumObjects * 32`; Epic reads the declared records without requiring EOF. The correction keeps short-record and configured maximum-count errors but allows trailing bytes. The historical original rejects the new positive fixture.
- **UE5-D025 (commit `471b6d14`):** UnrealDB demanded mapped-name type `1` (Container). Epic's diagnostic path ignores the type bits and looks up `GetIndex()` only; authentic cooked ScriptObjects use `2` (Global). The correction checks valid name-map **index** without inventing a type gate. Executable historical tests show type 0 and type 2 failed previously and now decode.
- **UE5-D026 (commit `471b6d14`):** UnrealDB rejected repeated `GlobalIndex`; Epic's `Objects.Emplace` uses `TMap/TSet::Emplace`, which replaces the earlier entry for duplicate keys. New decoder preserves **last-entry-wins**; historical exception and corrected outcome verified. A duplicate is not converted into a runtime-object claim.

## Authentic real cooked-file acceptance

**Source:** Installed Satisfactory Dedicated Server, `FactoryGame/Content/Paks/global.utoc` and `global.ucas` on DEV, distinct from the main `FactoryGame-WindowsServer.utoc`. The test reads these files **read-only**, no production database writes, no mass rescan, no binary copyrighted fixture checked into GitHub.

- Global TOC SHA256: `0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6`
- Cooked ScriptObjects chunk SHA256: `46d8ca9171563208e1b1a83f853274f63a507bf9922cc6b9d4b9116f0d4c4e76`, length **3,540,748 bytes**
- `NameBatch`: **50,640** names; **54,968** cooked entries; **all 54,968 mapped names are type 2 (Global)**, none have out-of-range indices, none have duplicate global indices in **this** file.
- One global chunk at ID `000000000000000000000005`. Ordinary dedicated-server package TOC has no ScriptObjects chunk; `fromToc()` reports `present=false`. Script chunk returns `present=true`, `count=54968`, 54,968 diagnostic object records, and `runtime_authoritative=false`.
- Actual pre-fix `a3d159a3` reader rejects the **entire authentic chunk** due to incorrectly demanding mapped type 1; corrected reader succeeds and preserves `GetRecipes`, `AbstractInstanceDataObject` etc.

## Executable operation acceptance and consumer inventory

`catalog/bin/verify-uedb5-b001-operation-acceptance.php` executes **four independent drivers** and **12 operation assertions**: pinned Epic read-path/original trailing-byte regression (12 assertions), six source hashes and mapped-name/duplicate differential (19 assertions), authentic `global.utoc/global.ucas` (15 assertions) and malformed standalone fixtures (3 deliberate rejections).

`verify-uedb5-b001-mapped-name-source.php` traverses **all 559 production PHP files** beneath `catalog/src`, explicitly checks no direct production calls to `Uedb5ZenScriptObjectsReader::parse/fromToc`, and fails if an unexpected caller appears. The reader is a *bounded offline diagnostic service*, not a runtime-resolving dependency, and is **not** silently substituted for script registration. Existing `Uedb5IoStoreTocReader`, `Uedb5BinaryReader` and `Uedb5ZenPackageReader` are independently hash-guarded in the manifest. The packaged real-file fixture path can be overridden with `UNREALDB_B001_REAL_GLOBAL_TOC` / `UNREALDB_B001_REAL_PACKAGE_TOC`; the pinned contents must match, or acceptance fails. The public repository never contains the game binary.

**Explicit exclusions:** live `FGlobalImportStore`, actual registration events or UHT/Verse restrictions, Epic loader execution, runtime editor flags, opaque debug-only compile configuration, package-store backend availability, game-profile completeness and any UE1–UE4 audit. These belong to their already registered operations and must not be marked accepted by B001's offline parser tests.
