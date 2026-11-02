# G5 T99 UTextBuffer::Serialize — first full-method acceptance candidate

Status: **OPEN — not FULLY_EPIC_VERIFIED** (2026-10-24).
Selected scope: UT99 v432 compiled Core.dll and source fallback UT99 v400 Core/Src/UnObj.cpp:3588-3593 and Core/Src/UnMisc.cpp:173-200, genuine version-69 Engine.u. Other UE1 source families are independent.

## Acceptance requirements and independent observations

| Requirement | Result | Evidence |
|---|---|---|
| Compiled native method and field order | PASS | Core.dll UTextBuffer::Serialize RVA 0x31bb -> 0x5ec10, calls UObject::Serialize then reads Pos, Top and FString |
| Genuine object references / outer paths | PASS | Native v432 exports 88 Engine.u TextBuffer full paths matching source ExportMap paths exactly (prior 22/22) |
| Full genuine loaded values | PASS | All 88 original v69 Engine.u TextBuffer objects match compiled Pos, Top and UTF-16 text contents, 1,007,796 bytes of live native text storage captured via 43.9 MB Memory64 MiniDump |
| Nondefault Pos/Top | PASS, controlled native fixture | Size-preserving Pos=3 Top=10 loaded and exported by real compiled v432 Core.dll; production PHP matches |
| UTF-16 FString branch | PASS, controlled native fixture | 548 code units (547 Omega characters + NUL), full 1,096 bytes of compiled memory match archived Unicode payload and production UTF-16 decode |
| Missing terminal NUL | **PRODUCT DEFECT FOUND AND FIXED** | Native v432 loads a size-preserving one-byte mutation of Engine.ClipMarker.ScriptText NUL to X, exports successfully. Previous UnrealDB PHP rejected it. Corrected production reader retains raw hash and declared export bounds, decodes FString::Len logical character count minus one, records text_terminator_is_nul=false. |
| Oversized ANSI FString | PARTIAL | Mutated source count 831 to 900: native compiled v432 exits 1 with Serial size mismatch Got 912 Expected 843; PHP rejects with TextBuffer ANSI FString exceeds export bounds before reading beyond declared extent. Both reject, but consumed-byte / diagnostic paths differ. |
| Inherited UObject::Serialize | OPEN | Parent serializer's full transitive independent G5 gate has not been accepted. |
| All selected native source-version cases and error semantics | OPEN | Other genuine loader families, empty/invalid-Unicode native cases, and additional malformed boundaries remain unverified. |

## Focused source repair and evidence

The pinned manifest is catalog/compliance/UE1-O06-G5-T99-TEXTBUFFER-FULL-VALUES.json. It identifies source/compiled code, original Engine.u SHA-256, original full-memory CDB dump, runtime events, four controlled cases, compiled logs and semantic limits. Full snapshots remain on DEV in C:\Temp\UnrealDB\O06 and are not uploaded to GitHub or PRD.

Current focused tests:
- verify-ue1-classic-textbuffer-native.php: 13/13 PASS
- verify-ue1-o06-g5-textbuffer-native-all-values.php: 813/813 PASS
- verify-ue1-o06-native-ut99-textbuffer-exports.php: 22/22 PASS (affected, previous current cases automatically skipped)

**848/848 focused checks PASS**, but this is not full end-to-end method acceptance. It is the completed method-specific genuine native loaded-value stage plus selected errors and source-defined branches.

## Required before a FULL method count increases

Close the inherited UObject::Serialize dependency with exact genuine/native evidence; define and verify the malformed-error compatibility policy (semantic rejection versus identical native read-overrun/diagnostics); verify outstanding selected native variants. Only then may the reconciliation mark this exact method ACCEPTED. Do not replay the passing tests without invalidated inputs. **Overall FULL remains 0/256; no unrelated methods accepted.**

## Production deployment safeguard

The verified native-reader fix is committed on DEV, but its PRD deployment is **BLOCKED pending a coordinated source/dependency sync**. The PRD reader at Z:\Apache24\htdocs\unrealdb\catalog\src\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader.php is a **420-line older source generation** (Git blob b176b79c5aae1a8eaabfc863d974df14823214bc, ancestor 76c3a0d6); the previous DEV reader had Git blob 54bef0c946cab9ded9e665ae8476eca80fa12c19, and the fixed DEV version is d9d4f28976034ac1a4ba80d0434d221e2ae09f62. The PRD copy lacks later loader-lineage parameters, skeletal/animation dispatch, source-family overlays and other dependencies. Blindly copying only the new reader risks incompatibilities with old PRD companion readers; blindly replacing PRD's file also discards an unresolved deployment generation difference. No PRD file was overwritten and no PHP tests were run on PRD. Resume deployment only through a dependency-aware versioned sync and SHA-256 verification.
