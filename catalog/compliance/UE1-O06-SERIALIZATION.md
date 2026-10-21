# UE1 O06 — object/property serialization acceptance

Updated 2026-10-21. **IMPLEMENTING — NOT ACCEPTED.**

This operation is concerned with bytes serialized by each source UE1 generation's object/property serializers. It is distinct from O07, which will validate/extract the pixel, audio, geometry or external/bulk contents that an O06 serializer locates, and O10, which checks actual runtime engine loading and end-to-end behaviour. Successful header parsing is NOT object/property acceptance.

## Fixed source-specific work and evidence

| Family | Required O06 behaviour | Current verified evidence | Acceptance state |
|---|---|---|---|
| Original resource v9/v10 (1996) | Decode native 32-bit per-resource header, class-specific header fields and object properties/data semantics, preserving process pointers as nonportable | Native common headers/physical header+data digests tested 20/20, UDatabase Num/Max and UPalette 1024-byte fixed record tests 12/12; v9/v10 derived UTexture dimensions, source header pointers, mip offsets and QueryMinSize tests 12/12; UTextBuffer/UBuffer/UArray/UEnum primitive and unresolved reference records 32/32; native USound header/data size, family-name and driver ID fields 10/10; staging stores source-typed native fields, never invented modern objects | **OPEN:** remaining original registered types (mesh/model/level/script/class/font/sound etc.), derived serializers and native runtime resource-reference fixup callbacks |
| Original Unrealfile v22/v23 (1997) | Decode UObjectBase, FExecStackMain header prefix, UClass::SerializeBin per-class object members, class-specific SerializeData, and 1-based references | Source-defined object bases 35/35; stack prefixes 18/18; class schema and binary SerializeBin Int/Bool/UObject and source-defined FArchive::String values 18/18; class/data byte ranges SHA-256; CLI and native unverified staging | **OPEN:** other original class-specific SerializeData and inheritance/external UClass schemas; the source-defined binary test includes Int, Bool and UObject references, not every original field type |
| Classic v35–69 (1998–1999) | Version-gated stack/db fields, FPropertyTag metadata, array sizes/indices, scalar/struct values, and source-defined native class serializers | All 92 SHA-pinned corpus files: 2,465/2,465 exports; 15,475 serialized tags; 805 palettes, 1,228 textures (including derived), 396 sounds, 22 fonts, 14 packages; no opaque tags or native serializer failures; malformed payload tests 9/9 | **PASSED for this named corpus only**, not all UE1 classes or arbitrary game-specific packages |
| Production diagnostics / unverified early staging | Surface exact decoded fields and explicit unsupported states, without successful runtime flags | CLI shows classic tagged/native preview and original range/stack records. Original staging/MySQL tests 29/29 and 16/16 | **PASS** for verified routes |

## Critical source-defined differences

- 1996 v9/v10 uses FGlobalResourceManager raw UResource structures. Resource data is not a classic UE1 tagged-property stream. Registered `UTexture` derived native fields and `UDatabase` subclasses UTextBuffer/UBuffer/UArray/UEnum are decoded using 32-bit layouts; on-disk resource-pointer and FName words are NOT interpreted as actual loaded pointers/names.
- 1997 v22/v23 uses UObject::SerializeHeader: FExecStackMain (optional node/code fields) then UClass::SerializeBin(PROPBIN_PerObject). The reader now extracts UClass::SerializeHeader member schema, FProperty descriptor definitions and native instance values where the class schema is locally available. Missing external/inherited class schemas or unsupported class-specific SerializeData remain explicitly unresolved.
- Classic Unreal v1.200 UObject::Serialize serializes GObj.TempNum/TempMax when version<40, MainFrame for version<47 even when RF_HasStack is clear; later versions serialize a frame only when flagged. Pre-v57 state and pre-v58 group FName fields follow tagged properties.
- Version 35 object property data uses a compact package reference in the genuine DProj corpus even when the tag size field is 4; this is recorded as a version-scoped corpus exception, not asserted to be a native-runtime trace.
- v400 FPropertyTag type/size/array rules and UTexture/UFont/USound/UPalette/FireTexture class serializers are implemented for records represented in the pinned corpus. Unprocessed color/audio/mip bulk bytes belong to O07, not a false property decode.

**1996 native UFont update:** Epic `UnRes.h::FFontCharacter` stores four 32-bit signed glyph coordinates/dimensions. `UFont` has 88-byte header (UResource+UDatabase+one texture pointer) with `Num*16` bytes of glyph data. Reader and native staging decode glyph records but leave texture pointer fixup not-evaluated. Source-defined v9/v10 tests: 14/14. The 1996 sound/header and original resource database verifiers remain independent.

**1997 native inheritance test:** `verify-ue1-original-unrealfile-inheritance.php` verifies that `UClass::SerializeBin` processes the current class's `PROPBIN_PerObject` properties before its parent class, matching `FPropertyIterator` (v0.867 `UnClass.h`). Dedicated source-defined v22/v23 tests: **12/12**, including local inheritance, invalid ResMap, cycle rejection and unresolved/non-class parent refusal. External UClass provider loading remains an O06 gap.

**1996 geometry update:** Source-defined `UVectors` (3 float32), `UVertPool` (two signed INT32 indexes and `NumSharedSides`), and `UTerrain` (four signed INT32 grid bounds, 608-byte fixed derived header) are decoded by `CatalogUE1OriginalResourceGeometryReader`. On-disk `HeightMaps`, `TileMaps` and `TileRefs` remain raw unresolved words, not live resource pointers. Verifier `verify-ue1-original-resource-geometry.php` passes **30/30** v9/v10 positive/negative/staging cases, followed by native staging and isolated MySQL tests. These are source-defined binary fixtures, not original compiled 1996 oracle results.

## Durable test journal and timeout recovery

Every O06 test is invoked through `catalog/bin/run-ue1-o06-tests.ps1 -Tests <named verifier>` rather than from an unrecorded terminal loop. `UE1-O06-TEST-EVENTS.jsonl` is append-only: it writes `STARTED` before invoking PHP, then `PASS`/`FAIL`, exit code, elapsed time, Git commit, source/test SHA-256 fingerprint and the persisted full stdout/stderr file under `evidence/o06/`. `UE1-O06-TEST-STATUS.json` is refreshed before and after **each** test, and identifies the latest state. A STARTED row with no later terminal event is **interrupted**, not passed. Previous failures remain in the event history; fixed tests get a separate new run entry. Tests can be skipped only when the *latest* run for the same target and exact source/test hashes passed; `-Force` re-executes. Use `-TargetRoot 'Z:\Apache24\htdocs\unrealdb'` to journal PRD acceptance separately. Do not assume historical pre-journal passes were logged by this runner; those remain in prior acceptance documentation.

## Repeatable commands

`php catalog/bin/verify-ue1-classic-tagged-properties.php` — pinned 92-file property/class-native corpus, counts and SHA-256; reject loss of existing coverage.

`php catalog/bin/verify-ue1-o06-payload-negatives.php` — genuine-file mutation tests; CLI/staging source scope.

`php catalog/bin/verify-ue1-original-resource-headers.php`, `verify-ue1-original-resource-database.php`, `verify-ue1-original-resource-texture.php` and `verify-ue1-original-simple-database.php`, `verify-ue1-original-resource-sound.php` — v9/v10 source-defined native header/digest, UDatabase fields, UTexture and primitive database records (20/20 + 12/12 + 12/12 + 32/32 + 10/10).

`php catalog/bin/verify-ue1-original-unrealfile-object-bases.php`, `verify-ue1-original-unrealfile-stack-prefix.php` and `verify-ue1-original-unrealfile-class-schemas.php` — v22/v23 independent class/data ranges, FExecStackMain, UClass FProperty schema and decoded instance field values (35/35 + 18/18 + 18/18).

Do **not** mark O06 accepted until the outstanding 1996 derived-class and 1997 binary class-member serialization are implemented and acceptance tests demonstrate their decoded values. Do not infer genuine early-format binary evidence from text .u class declarations in source trees. Any additional genuine corpus classes discovered must be audited under O06 (not as new operation IDs).
