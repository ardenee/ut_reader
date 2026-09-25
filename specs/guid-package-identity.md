# GUID and Package Identity

## Scope and authority

This is a cross-generation UnrealDB semantic specification. It does **not** flatten UE1, UE2/2.5, UE3 and UE4 into one binary layout. The exact per-generation package specifications remain authoritative for serialized order and version gates.

Authoritative source families used by this specification:

- UT99 retail v1.400: `ardenee/UT99src`, especially `Core/Inc/UnArc.h`, `UnObjBas.h` and package/linker source.
- Unreal II / UE2 and UE2.5: `ardenee/unreal2src` and `ardenee/UE2.5`; behavior is used only where present in those source trees.
- UT2003/UT2004: their supplied game source trees for revision-specific UE2 branches.
- UE3 UDKUltimate: `ardenee/UE3src`, especially `Development/Src/Core/Inc/UnLinker.h`, `UnFile.h`, `UnObjBas.h`, and `Core/Src/UnLinker.cpp`.
- UE4 4.27.2: `ardenee/UnrealEngine4`, especially `Core/Public/Serialization/Archive.h`, `CoreUObject/Public/UObject/PackageFileSummary.h`, `ObjectResource.h`, and their implementations.

Rules:

1. use the package's own engine/revision rules;
2. never use a later generation's field layout to repair an earlier package;
3. version/licensee/custom-version gates are format rules, not optional hints;
4. byte ranges and counts must be checked against the actual file without imposing arbitrary UnrealDB format limits;
5. runtime/config behavior not serialized in the file must not be guessed.

## GUID semantics

Package GUIDs are serialized identity metadata, not content hashes. Preserve the four 32-bit GUID components with archive byte-order semantics.

Earlier Unreal summaries use package GUID plus generation information as package identity/version lineage. UE4 retains package GUID-related summary/export metadata while also introducing custom versions and other package metadata.

## Matching

A GUID match is strong serialized identity evidence but does not prove byte-for-byte equality. MD5/SHA values maintained by UnrealDB are separate catalog hashes and must not be substituted for the engine GUID.

Zero/invalid GUID values must remain zero/invalid; do not synthesize a GUID from filename, path or hash.

## Dependencies

When an engine revision uses GUID/generation information during package compatibility or import verification, follow that revision exactly. Do not project such matching into revisions whose source does not use it.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed identity behavior |
|---|---|
| Unreal II | Summary GUID/generation lineage is retained; its linker additionally computes `QuickMD5` over structural byte ranges. QuickMD5 is neither the GUID nor a whole-file hash. |
| UE2.5 | Retains GUID/generation identity and QuickMD5-era structural identity behavior. |
| UT2003 | Retains GUID/generation summary identity and computes QuickMD5 from two raw structural ranges. |
| UT2004 | Retains GUID/generation identity, but its reviewed QuickMD5 algorithm hashes explicit structural fields/names rather than the UT2003 raw-range algorithm. |
| UE3 | Retains summary GUID/generation concepts and adds export-side `PackageGuid`/package metadata and additional GUID-table information. |
| UE4 4.27.2 | Retains package GUID/generation-related summary metadata while custom versions, engine versions and other modern metadata provide additional compatibility identity. |

UnrealDB must keep engine GUID, generation data, QuickMD5 and catalog MD5/SHA as separate identity domains.
