# UE1 O06 — source-backed remaining acceptance contracts

Updated 2026-10-22. This is a bounded unresolved list, NOT additional operation IDs. The canonical operation remains O06. Use the append-only UE1-O06-TEST-EVENTS.jsonl and latest UE1-O06-TEST-STATUS.json; do not rerun matching PASS tests just to reconstruct progress.

## Current checkpoints

- Source reader commit: 5a186773. PRD verification journal commit: 27f187f5.
- Original 1997 v22/v23: six additional archive headers source-decoded and verified 60/60; UVerts and UFont data records fully decoded. DEV and PRD source fixtures, unverified staging, isolated MySQL PASS; 16/16 deployed file SHA-256 matches.
- 1996 v9/v10: many native resource classes including actors/class/default actors, mesh, model, level, BSP, script, texture, sound and font have source-typed implementations and individual tests. Consult UE1-O06-SERIALIZATION.md and per-test status for boundaries.
- Later classic UE1: original pinned 92-file package corpus / 2,465 exports remains source-scoped evidence, not universal class coverage.

## Known remaining source serializer work (1997 v0.867)

| Source-native class/data | Epic source contract | Acceptance still required |
|---|---|---|
| UBspNodes / FBspNode | Src/Inc/UnObj.h, FBspNode operator<< (near 692-700) | **IMPLEMENTED:** 56-byte archive records, plane/zone mask/flags/indices; source fixtures and bounds tests 20/20 combined with FBspSurf; DEV staging/MySQL PASS; PRD pending |
| UBspSurfs / FBspSurf | Src/Inc/UnObj.h, FBspSurf operator<< (near 795-805) | **IMPLEMENTED:** 44-byte archive records, typed texture/brush/actor references and signed panning; same 20/20 combined source fixtures; PRD pending |
| ULightMesh / FLightMeshIndex | Src/Inc/UnObj.h, operator<< (near 929-944) | **IMPLEMENTED:** variable 8×INT + 3×BYTE + NumStaticLights+NumDynamicLights one-based actor refs, max16; 12/12 v22/v23 source tests and DEV staging/MySQL PASS; PRD pending |
| UPolys / FPoly | Src/Inc/UnObj.h, operator<< (near 1065-1078) | **IMPLEMENTED:** 82+12×NumVertices variable archive records, max16, signed panning, names/brush/texture references; 16/16 source v22/v23 tests plus DEV staging/MySQL PASS; PRD pending |
| UStackTree / FStackNode | Src/Inc/UnScript.h, FStackNode declaration (near 274-325), native operator<< implementation | Decode class scope, links, name and union branch and source data order; validate one-based references |
| UScript source tokens | Src/Engine/UnScript.cpp, SerializeData / SerializeExpr | Audit full supported serialized expression token inventory, not just the currently fixture-tested tokens; add exact negative and valid cases |

Additional source-defined class-specific handlers discovered through the pinned Epic tree must be mapped here, or explicitly excluded with a source citation/proof. v0.867 UCamera, UFont, UVerts, ULightMesh, UBspNodes and UStackTree headers are already decoded; do NOT count them as complete data serializers unless their data is verified.

## Acceptance and separation

O06 may be accepted only after every **saveable, source-required** versioned header, data record and property serializer in this inventory is either (a) implemented, source-typed and tested using at least one positive and one malformed-boundary case, or (b) explicitly proven non-serialized in the pinned source. Check both original v9/v10 and v22/v23 branches and the classic pinned corpus. Each affected DEV verifier and PRD integration test needs a finished PASS event plus matching deployed source/test SHA-256.

O07 owns interpretation/extraction of image, audio and other bulk payload; O10 owns runtime registration, pointer fixup, engine execution, Preload and end-to-end lifecycle. A source-defined fixture PASS must not be represented as proof from an original compiled engine oracle or as acceptance of every game's custom classes. Genuine early 1996/1997 compiled engine oracles remain unavailable.

**Current formal O06 status: IMPLEMENTING / NOT ACCEPTED.**
