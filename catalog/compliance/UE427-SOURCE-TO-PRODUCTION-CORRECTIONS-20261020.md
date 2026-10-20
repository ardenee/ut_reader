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
  232 original-source step/field/gate rows; **3** have bounded metadata
  mappings, and **229** remain `NOT_CHECKED`. The 48-row P07 trace
  only maps the PackageName metadata step, not its runtime action.
- No stock UE4.27 game source profile currently exists in the production
  `Uedb5GameSourceRegistry`; its registered UE4 game is UT4. This is
  not repaired by manufacturing an unregistered game ID or routing
  unrelated packages through a UT4 profile.
- Previously persisted UEDB4 rows may still lack PackageName.
- Older UE4 source branches and writer-specific format differences
  require their own pinned Epic sources, not guessed gates.

The correction is judged by the production behavior and source
trace, not a new claim of whole-engine parity.
