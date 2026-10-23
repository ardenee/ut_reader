# UE1 O06 G2 — original 1997 FStackNode source branch

## Epic source of truth

| Source family | Local Epic authority | Saved FStackNode links |
|---|---|---|
| May v0.86x (1997-05-25) | Src/Engine/UnScript.cpp::operator<<(FStackNode), Src/Inc/UnScript.h | Parent, ChildFunctions, ChildStates, Next (4 FStackNodeLink) |
| July v0.867 (1997-07-25) | Same methods in July tree | ParentItem, ParentNest, ChildFunctions, ChildStates, Next (5 FStackNodePtr) |

Both builds serialize original Unrealfile versions 22/23; the version field **does not distinguish the layout**.

Each link archives DWORD Class and only when Class is nonzero, signed DWORD iNode. Five subsequent WORD values: Name, iCode, CodeLabelOffset, Line, Pos. Four BYTE values, in EXACT Epic order: NestType, StackNodeFlags, NumParms, OperPrecedence. Class/state union writes two QWORD masks (16 bytes), while function union writes five WORDs (10 bytes). Therefore the smallest function record with null links is **40 bytes in May and 44 bytes in July**; non-null Class links add four bytes each.

## Corrected production path

CatalogUE1OriginalUnrealfileStackTreeDataReader.php now decodes the correct four- or five-link source branch, using explicitly declared May/July lineage when available. Without explicit lineage it independently attempts both source branches and accepts only a **single** structurally valid, completely consumed on-disk archive layout. Identical/ambiguous streams (including zero records) stay explicitly unverified; strict mode rejects ambiguity. Invalid references, names, nest types, incomplete or trailing bytes fail closed. Neither the package version nor a runtime object pointer is fabricated.

An unrelated but source-confirmed decoder error was corrected: NestType and StackNodeFlags were previously reversed. The original July fixture has been corrected to actually serialize those fields in Epic order. Its initially failed run and subsequent successful rerun remain in the append-only journal.

## Verified DEV outcomes (2026-10-23)

- Source-pinned full-package verifier verify-ue1-o06-g2-stacknode-may-july.php: **50/50 PASS**, including both versions 22/23 and both 1997 source families, variable link counts, class/function union fields, unique inferred versus explicit source, wrong source, bad maps, short stream and ambiguous zero-node negatives.
- Original 1997 stack-tree fixture: **PASS** with Epic-correct byte ordering.
- Shared original special-header fixture: **PASS**.
- Scoped G2 gate: **3/3 selected valid**, with two immutable matching PASS results reused rather than unnecessarily rerunning them.

**Not proved:** genuine compiled original-engine source-versus-package oracle, in-memory object pointer linking, global source-family provenance, and full G2/G5 acceptance. UE1 O06 remains IMPLEMENTING / NOT ACCEPTED.
