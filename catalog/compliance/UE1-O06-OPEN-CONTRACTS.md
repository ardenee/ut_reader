# UE1 O06 â€” source-backed remaining acceptance contracts

Updated 2026-10-22. This is a bounded unresolved list, NOT additional operation IDs. The canonical operation remains O06. Use the append-only UE1-O06-TEST-EVENTS.jsonl and latest UE1-O06-TEST-STATUS.json; do not rerun matching PASS tests just to reconstruct progress.

## Current checkpoints

- Latest source checkpoint before SerializeExpr token correction: fd2d1a4f. New token correction and exhaustive coverage currently DEV passed, PRD pending; see journal for current HEAD.
- Original 1997 v22/v23: six additional archive headers source-decoded and verified 60/60; UVerts and UFont data records fully decoded. DEV and PRD source fixtures, unverified staging, isolated MySQL PASS; 16/16 deployed file SHA-256 matches.
- 1996 v9/v10: many native resource classes including actors/class/default actors, mesh, model, level, BSP, script, texture, sound and font have source-typed implementations and individual tests. Consult UE1-O06-SERIALIZATION.md and per-test status for boundaries.
- Later classic UE1: original pinned 92-file package corpus / 2,465 exports remains source-scoped evidence, not universal class coverage.

## Known remaining source serializer work (1997 v0.867)

| Source-native class/data | Epic source contract | Acceptance still required |
|---|---|---|
| UBspNodes / FBspNode | Src/Inc/UnObj.h, FBspNode operator<< (near 692-700) | **IMPLEMENTED:** 56-byte archive records, plane/zone mask/flags/indices; source fixtures and bounds tests 20/20 combined with FBspSurf; DEV and PRD staging/MySQL PASS; hashes matched |
| UBspSurfs / FBspSurf | Src/Inc/UnObj.h, FBspSurf operator<< (near 795-805) | **IMPLEMENTED:** 44-byte archive records, typed texture/brush/actor references and signed panning; same 20/20 combined source fixtures; PRD PASS |
| ULightMesh / FLightMeshIndex | Src/Inc/UnObj.h, operator<< (near 929-944) | **IMPLEMENTED:** variable 8Ã—INT + 3Ã—BYTE + NumStaticLights+NumDynamicLights one-based actor refs, max16; 12/12 v22/v23 source tests and DEV and PRD staging/MySQL PASS; hashes matched |
| UPolys / FPoly | Src/Inc/UnObj.h, operator<< (near 1065-1078) | **IMPLEMENTED:** 82+12Ã—NumVertices variable archive records, max16, signed panning, names/brush/texture references; 16/16 source v22/v23 tests plus DEV and PRD staging/MySQL PASS; hashes matched |
| UStackTree / FStackNode | Src/Engine/UnScript.cpp operator<< (near 5167-5197), Src/Inc/UnScript.h FStackNodePtr serializer | **IMPLEMENTED:** five conditional source links, Class/State QWORD masks or Function WORD fields, source names/flags; 16/16 v22/v23 checks and DEV and PRD staging/MySQL PASS; hashes matched |
| UScript source tokens | Src/Engine/UnScript.cpp (source switch), Src/Inc/UnScript.h (enum and FStackNodePtr) | **ALL 50/50 distinct Epic explicit source cases mapped** by executable source-vs-PHP token inventory; **24/24** inventory assertions PASS and **24/24** script expression fixtures DEV PASS (plus staging/MySQL). Nullable EX_FinalFunction and EX_BoolVariable source guards corrected; PRD pending. Individual fixtures do not cover every value of conversion/intrinsic ranges; dispatcher and source branches are statically verified. |

Additional source-defined class-specific handlers discovered through the pinned Epic tree must be mapped here, or explicitly excluded with a source citation/proof. v0.867 UCamera, UFont, UVerts, ULightMesh, UBspNodes and UStackTree headers are already decoded; do NOT count them as complete data serializers unless their data is verified.

## Final mesh and bounds source inventory additions (2026-10-22)

Epic `v0.867 Src/Inc/UnMesh.h` declares five independent saveable `UDatabase` serializers referenced by `UMesh`: `UMeshVerts` (`FMeshVert`, 4-byte packed signed 11:11:10 vertex), `UMeshTris` (`FMeshTri`, 16-byte vertex/UV/flags), `UMeshAnimSeqs` (`FMeshAnimSeq`, 14 bytes), `UMeshAnimNotifys` (`FMeshAnimNotify`, 6 bytes), and `UMeshVertConnects` (`FMeshVertConnect`, 8 bytes). Additionally, `UBounds` in `UnObj.h` uses `UnMath.h::operator<<(FBoundingBox)` (2 FVector + BYTE IsValid = 25 archive bytes). All six are source-typed in `CatalogUE1OriginalUnrealfileDatabaseDataReader`, **64/64 v22/v23 individual checks PASS DEV**, including invalid Num/Max, stream size and typed-invalid reference/IsValid cases; original database regression and staging/MySQL PASS. PRD verification pending. This source-derived inventory prevents false acceptance based solely on the parent UMesh header.

## UT99 v400 editor serialization checkpoint

`UExporter`, `UFactory`, `ULevelFactoryNew`, `UClassFactoryNew`, and `UTextureFactoryNew` now have source-typed **direct-class** file serialization or proven GC-only exclusion for UFactory native refs. DEV synthetic package checks **79/79 PASS** and 3 affected regression tests PASS. Do not infer the same result for other UFactory/UExporter subclasses, the older 1998 source, or genuine packages until their own archive contracts are mapped. These five T99 sightings are PARTIAL and O06 remains NOT ACCEPTED. PRD deployment is hash-checked, never a duplicate test round.

## Acceptance and separation

O06 may be accepted only after every **saveable, source-required** versioned header, data record and property serializer in this inventory is either (a) implemented, source-typed and tested using at least one positive and one malformed-boundary case, or (b) explicitly proven non-serialized in the pinned source. Check both original v9/v10 and v22/v23 branches and the classic pinned corpus. Each affected verifier runs ONCE on DEV with a finished PASS event. PRD receives tested files; compare deployed SHA-256 instead of running duplicate verification. Historical PRD test events remain preserved.

O07 owns interpretation/extraction of image, audio and other bulk payload; O10 owns runtime registration, pointer fixup, engine execution, Preload and end-to-end lifecycle. A source-defined fixture PASS must not be represented as proof from an original compiled engine oracle or as acceptance of every game's custom classes. Genuine early 1996/1997 compiled engine oracles remain unavailable.

**Current formal O06 status: IMPLEMENTING / NOT ACCEPTED.** Original 1997 May/July source branches and classic source-native serializers still need remaining source-contract review and independent genuine-package/native parity; no additional PRD PHP test round is required. Classic UBitArray/UBitMatrix source-defined DEV checks 41/41 PASS, affected regressions 3/3 PASS; earlier duplicated PRD tests are history only.
