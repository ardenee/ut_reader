# UE4.27.2 — source-to-production corrections (2026-10-20)

**Authority:** pinned Epic revision `d94b38ae3446da52224bedd2568c078f828b4039`,
`L:/Source/Engine/UE4/UE 4.27.2`. The Epic-only technical reference
is under `specs/epic-source/ue4-4.27.2/`; implementation status here
does **not** alter those source references.

## Confirmed and corrected in production code

| Source operation | Epic reference | Actual PHP correction | Scope |
|---|---|---|---|
| File-version constants | `Core/Public/UObject/ObjectVersion.h`, `EUnrealEngineObjectUE4Version` | Removed 25 manually transcribed version constants from `UE4/UnrealPackageReader.php`; added `UE4/EpicUE427VersionConstants.php`, generated from the entire original Epic enum. The reader uses the generated trait. | Exact numeric equality for 25/25 currently used UE4 reader version constants. |
| `FObjectImport::PackageName` wire field | `CoreUObject/Private/UObject/ObjectResource.cpp:283–306`, especially v520 gate at 294 and field at 296 | `CatalogParsedPackageMetadataSnapshotBuilder::buildParsedSections` now preserves the serialized PackageName identity, including index, FName number, text, serialized-presence and meaningful override flags. For an explicit PackageName the snapshot's dependency provider root now selects that package; the original outer-derived root is kept separately as `outer_root_package`, and the identity `full_path` remains unchanged. | **Newly built parsed snapshots only.** Existing database rows are not silently rewritten or assumed to contain this information. |
| `FObjectImport::HasPackageName()`-equivalent missing/None distinction | `CoreUObject/Private/UObject/LinkerLoad.cpp::VerifyImportInner` and `FObjectImport` | Parsed snapshots do not treat serialized `NAME_None` or an absent field as an explicit provider override. | Metadata classification, **not** full `VerifyImportInner` runtime-linker equivalence. |
| Incorrect written boundary | `ObjectVersion.h` = `VER_UE4_NON_OUTER_PACKAGE_IMPORT` at **520** | Corrected prose that said 519+ or pre-519 in `specs/ue4-4.27.2-verifyimport-conformance-audit.md` and `specs/ue4-4.27.2-dependency-resolution.md`. | Documentation; earlier persisted records still need reparsing. |

## Change in acceptance method

The old reader contract tested hard-coded PHP text such as
`VER_NON_OUTER_PACKAGE_IMPORT = 520`; this could not demonstrate the
number was obtained from Epic. Its replacement reads
`specs/epic-source/ue4-4.27.2/package-loading/epic-version-enum.json`
(extracted from the original C++ with its pinned source SHA-256),
reflects the production PHP trait constants, and requires the complete
25/25 mapping to match. On DEV:

```powershell
py -3 catalog/bin/sync-ue427-epic-version-gates.py
```

Generation of the PHP trait after an explicitly pinned Epic source change:

```powershell
py -3 catalog/bin/sync-ue427-epic-version-gates.py --write
```

The ordinary check **fails on a changed/generated trait** rather than
quietly overwriting it. This is not a replacement for matching behavior
or branch completeness.

The `verify-ue4-package-name-boundary-contract.php` checks actual
metadata snapshots, including nonempty, `NAME_None` and absent field
cases. Former assertions that merely checked whether the old audit
prose existed were removed. The older soft reference contract assertion
was reclassified as a structural check; the historical v513 and earlier
wire rule **is not certified from UE4.27 writer source** and requires
the original historical Epic source if that rule is to be accepted.

## Outstanding, explicitly not accepted

- UE4.27 `VerifyImportInner` in-production native/runtime linker and
  redirected destination behavior; P07 remains ACTIVE, **not** accepted.
- Source-path completeness: the Epic-only package checklist started with
  232 source points and three bounded metadata mappings; the expanded
  250-point checklist and its current status are given below. The 48-row P07
  trace still maps PackageName metadata only, **not** native linker behavior.
- No stock UE4.27 game source profile currently exists in the production
  `Uedb5GameSourceRegistry`; its registered UE4 game is UT4. This is
  not repaired by manufacturing an unregistered game ID or routing
  unrelated packages through a UT4 profile.
- Previously persisted UEDB4 rows may still lack PackageName.
- Older UE4 source branches and writer-specific format differences
  require their own pinned Epic sources, not guessed gates.

## Subsequent Epic summary path corrections

| Original Epic C++ | Production implementation | Independent scope verified |
|---|---|---|
| `CoreUObject/Private/UObject/PackageFileSummary.cpp:33-45` | `UnrealPackageReader4::fixCorruptEngineVersion` | Exact FEngineVersion 4.26.0 licensee-flag repair, `ObjectVersion < VER_UE4_CORRECT_LICENSEE_FLAG` (**522**) and changelist >= 12740027. Checks cover either side of the version and changelist boundaries. |
| `Core/Private/Misc/EngineVersion.cpp:20-30,90-97` | `UnrealPackageReader4::readEngineVersion` | Changelist getter masks bit 31; `IsLicenseeVersion` reads bit 31. Serialized and effective 32-bit values are preserved separately. |
| `CoreUObject/Private/UObject/PackageFileSummary.cpp:184-189`, `ObjectMacros.h:106` | `UnrealPackageReader4::parse` | The editor clears transient `PKG_NewlyCreated` (bit 0) after reading the serialized package flags. A bit-mutated copy of a genuine UE4.27 package confirms both serialized and effective values. |
| `CoreUObject/Private/UObject/PackageFileSummary.cpp:346-351` | `UnrealPackageReader4::parse` | Legacy negative marker > -7 now rejects nonzero deprecated `NumTextureAllocations` in a source-derived -3 header fixture, instead of silently ignoring the value. |

After extending the pinned source-only handbook to the additional C++ callers,
the package-loader checklist contains **250** individual source points:
**222 NOT_CHECKED**, **25 source-mapped (24 passing a targeted/corpus check,
1 with a remaining legacy boundary check)** and **3 earlier bounded mappings
whose native-runtime equivalence remains unverified**. These are individual
source points, **not** 28 independently complete end-to-end operations.

Focused verification:
`catalog/bin/verify-ue427-epic-summary-corrections.php`: **6/6**;
`catalog/bin/verify-ue4-427-reader-contract.php`: **15/15**;
genuine 1,749-package table corpus, P04 malformed-record checks, P07
import-branch corpus, and P05 acceptance suite all exited successfully.

The legacy pre-336 `EngineChangelist` compatibility boundary, complete
`VerifyImportInner` provider selection, object payload reading and remaining
source paths are **not** accepted as full Epic-equivalent implementations.

## Export record preservation and Epic flag source (subsequent batch)

Original Epic source authority:

- `CoreUObject/Public/UObject/ObjectMacros.h:483-524`: `EObjectFlags`,
  `RF_Load` and `EPackageFlags`.
- `CoreUObject/Private/UObject/ObjectResource.cpp:105-171`:
  serialized `FObjectExport` fields and version conditions.
- `CoreUObject/Public/UObject/Linker.h:528-553`:
  `FLinker::FilterExport` including build-conditional editor treatment
  and dedicated-server/client filtering.

The existing PHP reader applied the numerically correct, but **manually
copied**, `RF_Load = 0x02D4003B` mask. It now uses generated
`EPIC_RF_LOAD_MASK`, `EPIC_PKG_NEWLY_CREATED`, and
`EPIC_PKG_FILTER_EDITOR_ONLY` constants. These values are computed from
the original `ObjectMacros.h` enum assignments and `RF_Load`
expression, with source SHA-256 enforcement; this change is mechanical,
not an invented replacement mask.

The UEDB4 parsed-snapshot builder previously discarded export record
inputs required by the native filter (including `bNotForClient`,
`bNotForServer`, `bNotAlwaysLoadedForEditorGame`). Newly constructed
**in-memory** parsed snapshots now retain these plus serialized vs.
effective object flags, forced-export status, FName number, asset status,
package GUID/flags, serial width, and preload dependency subfields.
The targeted metadata check passes for deliberately distinct values.

**Important limit:** preserving data does **not** execute native
`FLinker::FilterExport`, nor does it establish that all new fields survive
the existing UEDB4 SQL persistence schema. Native export filtering needs
actual `WITH_EDITOR`, `FilterClientButNotServer`,
`FilterServerButNotClient`, `bWasFiltered`, and
`bExportLoadFailed` state. Those branches remain **NOT_CHECKED** for
runtime behavioral equivalence; no provider has been filtered on an
assumed environment. The source-only reference now includes `Linker.h`
and a tenth diagram tab.

The revised source comparison list contains **263** indexed original Epic
source items: 221 `NOT_CHECKED`; 22 targeted-behavior mapped, 2
corpus-mapped, 1 pending boundary, 3 limited source mappings, and 14
export fields preserved in parsed metadata but not runtime certified.

## Epic export defaults and actual load-filter order (next batch)

Epic source `CoreUObject/Private/UObject/ObjectResource.cpp:29-58`
constructs `FObjectExport` with
`bNotAlwaysLoadedForEditorGame=true`, `bIsAsset=false`,
`FirstExportDependency=-1`, and all four dependency counters zero.
These in-memory defaults exist **even when a version gate omits their
serialized fields**. The UE4 reader had previously exposed null or
empty structures for omitted fields. It now reproduces the constructor
defaults while keeping the existing `...Present` indicators to show
whether the value came from disk.

Epic source `CoreUObject/Public/UObject/Linker.h:528-553`
implements `FLinker::FilterExport` in this order:

1. A previously failed/filtered export is filtered.
2. Under `WITH_EDITOR`, `!bNotAlwaysLoadedForEditorGame` is not filtered.
3. `FilterClientButNotServer && bNotForServer` is filtered.
4. `FilterServerButNotClient && bNotForClient` is filtered.
5. A null `ThisIndex` is filtered; otherwise it is not.

The UE4 reader now exposes
`UnrealPackageReader4::filterExportForLinkerContext` with those
exact conditions and **requires all five caller-owned linker/build
state values**. It rejects omitted values rather than assuming a
server/client/editor environment. Its 19 targeted checks passed, including direct v214 reader deserialization of omitted version-gated fields.
`SerializeExportMap` assigns non-null `FromExport` indices to
genuine loaded exports; the method preserves that source condition.

**Still outstanding:** catalog dependency processing does not yet call
this operation with the engine runtime context. There is **no
assertion of complete production/native load equivalence**.
This batch establishes the correct function when context is actually
available; it does not fabricate missing UObject/linker state.

The pinned Epic-only reference includes a source-anchored constructor
defaults diagram and a separate true/false `FilterExport` decision
diagram. The expanded 275-step source comparison explicitly separates
the 12 constructor defaults and 13 callable-policy steps from the
remaining 208 unchecked items.

The correction is judged by the production behavior and source
trace, not a new claim of whole-engine parity.
