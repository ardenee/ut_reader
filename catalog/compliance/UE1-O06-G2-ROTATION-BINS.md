# O06 G2 — 1997 per-property FRotation archive-width correction

**Status:** Source-scoped implementation and affected DEV verification complete, **not G2 accepted**.
The original May 1997 v0.86x source and July 1997 v0.867 source both use original Unrealfile v22/v23 archive envelopes, but have incompatible rotation serialization.

## Real production issue fixed

Epic source `Src/Inc/UnMath.h::operator<<(FArchive&, FRotation&)`:

- May v0.86x line 545: writes three WORDs, exactly **6 bytes**, and copies each WORD back to INT fields (zero extended).
- July v0.867 line 567: writes three signed INTs, exactly **12 bytes**.

The rotation correction previously applied to UMesh/UModel native header records and UScript opcode constants did **not** cover original `UClass::SerializeBin` per-object instance properties or the independent `UProperties::SerializeData` object. These two actual production readers previously unconditionally consumed 12 bytes. May instance archives would consume too many bytes and shift following property boundaries; `UProperties` complete-size branch inference treated both sources as 12 bytes, potentially fabricating lineage.

Corrected production components:

1. `CatalogUE1OriginalUnrealfileBinaryPropertyReader`: May explicit family consumes three little-endian WORDs and returns zero-extended integer values. July explicit family consumes three signed little-endian INTs. If an eligible rotation occurs without documented source lineage in a general UObject header, decoding returns `source_1997_property_lineage_required` (strict mode throws) before reading any potentially misleading values. File version alone cannot choose the source. Additional native members can follow the property stream.
2. `CatalogUE1OriginalUnrealfilePropertiesReader`: Decodes the same family-specific rotation. When its separate `UProperties` persisted data segment is entirely fixed-width and uniquely matches 6-byte May or 12-byte July records, the existing exact-byte-length classifier may infer the source, independently of the numeric archive version.
3. `CatalogUE1OriginalUnrealfilePropertySerializationGate`: Source-aware exact widths used for UProperties inference; active rotation requires source provenance when not otherwise inferable. Existing `CPF_Intrinsic` May/July flag handling remains intact.

**Contract acceptance:** `verify-ue1-o06-g2-rotation-bin-lineage.php`, authentic source-derived complete `UClass`, class-defined `Instance` and `UProperties` fixtures for both version 22 and 23 and both source families, confirms following integer properties remain aligned, wrong-family strict rejection, and unknown-family fail-closed behavior. **30/30 focused DEV checks PASS** after original pre-fix 6B May overflow was journaled. The affected scope **5/5 PASS**: new verifier, class schemas, native UProperties, class inheritance and external parent source-schemas. The two preexisting fixture files now explicitly declare their existing **July** source lineage instead of relying on archive version 22/23 to select a 12-byte rotation.

All tests ran only on DEV and were journalled in the isolated worktree:
`C:\Temp\UnrealDB\O06\g2-rotation-worktree`. This separates the change from an unrelated concurrently active G2 bounds contract in the main checkout.

## Unresolved scope

G2 acceptance stays OPEN: other original 1997 archive methods require complete source-specific parity and original genuine-file/compiled evidence under G5. These are source-generated fixtures, **not** a 1997 Epic engine runtime oracle. Runtime class constructors, transient-property memory zeroing, C++ pointer fixups and VM execution are not simulated; archive values are not claims about C++ runtime state. The known May-vs-July `CPF_Intrinsic` persisted-byte condition is an independent already corrected discrepancy.
