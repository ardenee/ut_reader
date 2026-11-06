# Unreal II / UE2 Dependency and Import Resolution

## Scope and authority

This specification distinguishes **two complete local Unreal II game-specific UE2 import-verification sources**, independently of generic Warfare/UE2, UT2003 or UT2004:

- **9 December 2000:** `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`; `Core/Inc/UnObjVer.h` declares package version **69**, minimum loadable **60**, and licensee **0x7F**. The first set of sections below records this branch's behaviour.
- **7 January 2003:** `L:\Source\Games\Unreal II\Unreal II The Awakening [01-07-2003]\Core\Src\UnLinker.cpp`; `Core/Inc/UnObjVer.h` declares package version **126**, minimum loadable **60**, and licensee **0**. This is a complete UE2 `ULinkerLoad::VerifyImport` source, not UE4 code. Its differences are specified in the separate January 2003 section below.

The version-69 source supports older packages through its documented loadable range; the January 2003 source proves its own game-specific loader. The existence of a later engine does **not** automatically make every intermediate package/licensee combination source-proven. Unmatched combinations remain unverified for exact game-specific import resolution.

UT2003 and UT2004 have distinct game-specific source addenda; neither is used to fill missing Unreal II branches.

## Authoritative source references

| Behavior | Source |
|---|---|
| import verification | `Core\Src\UnLinker.cpp`: `ULinkerLoad::VerifyImport` |
| verify pass | `Core\Src\UnLinker.cpp`: `ULinkerLoad::Verify` |
| export hash | `Core\Src\UnLinker.cpp`: `HashNames`, `ULinkerLoad::ULinkerLoad` |
| export class identity | `GetExportClassPackage`, `GetExportClassName` |
| import realization | `ULinkerLoad::CreateImport` |
| provider loading | `UObject::GetPackageLinker` |
| package-version boundary | `Core\Inc\UnObjVer.h` |

## Verify pass and early return

Unless verification is disabled by loader flags, the linker verifies serialized imports. `VerifyImport(i)` returns immediately when the import is already resolved or when any of these FNames is `NAME_None`:

- `ClassPackage`
- `ClassName`
- `ObjectName`

A direct `NAME_None` import is therefore ignored by this operation. That does **not** create a generic ancestor exemption for children.

## Provider package resolution

For a top-level import (`PackageIndex == 0`) source assertions require `Core.Package`. The linker creates/finds a package from `Import.ObjectName` and calls `GetPackageLinker`.

### UnrealI -> UnrealShare package retry

This v69 source retains the historical compatibility path. If loading top-level package `UnrealI` throws, the loader creates `UnrealShare` and retries through the `SharewareKludge` label.

UnrealDB may reproduce this retry only for this source-backed profile. It is not a generic UE2 package alias.

## Nested imports and parent linker

For `PackageIndex < 0`, the source:

1. recursively verifies the parent import;
2. copies the parent `SourceLinker`;
3. asserts that `SourceLinker` exists;
4. walks the negative parent chain to determine depth and top package context.

This differs from UT2003 v2107, where the equivalent assertion is commented out and the following work is guarded by `if (Import.SourceLinker)`.

Therefore a child of a direct `NAME_None` parent reaches a different source boundary in Unreal II v69 than in UT2003.

## ExportHash identity and order

The hash function is:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

where A is object name, B is class name, and C is class package.

Exports are prepended into the hash chain. Matching traversal therefore visits later matching export indices before earlier entries in the same bucket. UnrealDB must preserve this source order rather than substitute catalogue row order.

### UnrealShare -> UnrealI hash normalization

Before hashing, this source changes class package `UnrealShare` to `UnrealI`. This compatibility is active in the v69 implementation.

## Candidate identity and class-package compatibility

An export first matches by:

- exact `ObjectName`;
- exact export class name;
- exact class package, except for the source-defined compatibility below.

The active `ClassHack` accepts a provider export whose class package is `UnrealShare` when the import requests `UnrealI`.

This compatibility is source-backed for Unreal II v69. It must not be inferred for later Unreal II packages or UT2003.

## Parent / outer matching

For nested imports, the matched export is checked against the resolved parent import.

- If the parent has no `SourceIndex`, a candidate with nonzero provider `PackageIndex` is rejected.
- If the parent has a `SourceIndex`, a nonzero provider `PackageIndex` must equal `Parent.SourceIndex + 1`.
- In either case, provider `PackageIndex == 0` is accepted as the root-export fallback.

## RF_Public handling

A matching export that lacks `RF_Public` is **not** accepted as a normal resolved import.

- With `LOAD_Forgiving`, the source marks broken links/logs and returns.
- Otherwise it throws `FailedImportPrivate`.

The earlier UnrealDB assumption that the reviewed Unreal II path accepted private exports was incorrect for this latest complete v69 source.

## Mesh -> LodMesh Rehack

After the Mesh hash pass, the source checks whether `Import.ClassName` is `Mesh`. If so it changes the class name to `LodMesh` and jumps back to the hash lookup through `Rehack`.

This happens **regardless of whether the Mesh pass already found a public match**.

Consequences:

- a LodMesh match can replace an earlier public Mesh match;
- a private LodMesh match can fail the import after a public Mesh match;
- if the LodMesh pass finds nothing, an earlier public Mesh `SourceIndex` remains in place.

This is not accurately modeled as a simple “retry only when Mesh is missing.”

## Runtime native/transient binding

When file-backed lookup does not produce a source export and a runtime package context exists, the loader can search runtime state for:

- the class package;
- the class;
- an object under the top-level package with the requested object name.

A found object must be public, native and transient to bind directly. This runtime state is not derivable from package bytes alone; static UnrealDB reports the residual branch as runtime-unavailable rather than fabricating a hard result.

## SafeReplace behavior

In this v69 source the active `#if 1 //NEW` path sets `SafeReplace = 1` when the runtime class exists but the requested object does not satisfy native/transient binding. It does not require the older `CLASS_SafeReplace` test in the inactive `#else` branch.

This runtime behavior must not be invented from package metadata.

## Depth-specific UnrealI / UnrealShare reparenting

The v69 source also retains an active shareware compatibility path after runtime lookup. When the top runtime package is `UnrealI` and import depth is 1, it:

1. appends a new top-level `Core.Package` import for `UnrealShare`;
2. reparents the current import to that new import;
3. verifies the new import;
4. jumps back to `SharewareHack` and retries the current import.

This is active behavior in the audited source, not dead merge-era code. Static UnrealDB must keep it scoped to the v69 profile and must not infer it for later Unreal II packages.

## Configuration/remap boundary

No administrator-defined UnrealDB `ClassRemap` mapping is part of this source-backed profile. The authoritative v69 path does not consume UnrealDB's generic ClassRemap table.

Any runtime/configuration transformation not represented by this exact implementation and available serialized/runtime state remains outside deterministic package-only resolution.

## Final unresolved handling

If no export/native object resolves and SafeReplace does not suppress failure, the source logs a failed import and then either:

- marks a broken link and returns under forgiving load; or
- throws `FailedImport`.

Because these outcomes depend on load flags and runtime object state, UnrealDB preserves the static result as unresolved/runtime-derived when the deterministic file-backed path is exhausted.

## UnrealDB conformance requirements

For the December 2000 Unreal II v69 source branch, the reader must:

1. apply this profile only to package versions 60-69;
2. derive provider identity from the serialized import hierarchy;
3. preserve exact FName object/class/package identity;
4. preserve ExportHash visit order;
5. implement the v69 `UnrealI` -> `UnrealShare` package retry only within this profile;
6. implement the UnrealI/UnrealShare hash and class-package compatibility only within this profile;
7. preserve the source parent-linker assertion boundary;
8. reject non-public export matches according to source;
9. model Mesh -> LodMesh as the unconditional Rehack pass;
10. distinguish deterministic file-backed matches from runtime native/transient/SafeReplace behavior;
11. preserve the active depth-specific UnrealShare reparenting boundary;
12. not consume administrator ClassRemap as authoritative source behavior;
13. keep the v69-specific retries and private-export rejection confined to the December 2000 branch, without extending them to January 2003 v126.

## January 2003 game-source branch — package v126, licensee 0

The complete local January 2003 Unreal II `Core/Src/UnLinker.cpp` defines `ULinkerLoad::Verify`, `VerifyImport`, `FindExportIndex`, `CreateImport`, and the export-hash/class-identity helpers. Its `Core/Inc/UnObjVer.h` declares engine version `1226+DEMO_VERSION_OFFSET`, package version `126`, licensee `0`, and minimum loadable package version `60`. The executable source rules are:

1. `VerifyImport` exits early for an import already associated with a source linker/index or having `NAME_None` in the class package, class name or object name. This is a direct-import exemption, **not** an ancestor-import exemption.
2. A root `Core.Package` import calls `GetPackageLinker` for the named package root. A nested import recursively verifies its negative `PackageIndex` parent and inherits that parent's `SourceLinker`. If a parent linker is absent, the January 2003 implementation can continue without the December 2000-only parent-linker assertion; this does not establish a file-backed provider.
3. The export lookup hashes `ObjectName`, `ClassName`, `ClassPackage`, walks the selected linker's export-hash chain, and checks exact class/name identity. With a nested import, it compares the parent source export index to `Source.PackageIndex`, allowing a source-root `PackageIndex==0` fallback. The export hash prepends entries, so later matching export indices are visited first.
4. **Private-export difference:** a matching export without `RF_Public` causes a forgiving load to flag/log a broken link and return. In an ordinary **non-forgiving** load the `FailedImportPrivate` throw is inside an inactive `#if 0` block, so the code can still retain that source export index. This is **not** the December 2000 v69 private-export policy.
5. If the import class name is `Mesh`, the code changes it to `LodMesh` and re-enters the hash lookup unconditionally, even if the Mesh pass had previously matched. A LodMesh result may replace it; if there is no LodMesh match, an earlier source index remains.
6. `ClassRemap` and `PackageRemap` configuration retries are inside inactive `#if 0 //!!MERGE` blocks; they do **not** create active alias or package-root behaviour. The December 2000 `UnrealI` / `UnrealShare` retry must not be imported into this source branch.
7. File-backed lookup failure can fall through to an active runtime search for public/native/transient objects. Finding a class but not a matching runtime object may set `SafeReplace`. The final forgiving/error outcome depends on runtime flags/state. A metadata-only file reader cannot assert that these runtime-only fallbacks succeeded.
8. `FindExportIndex` and `CreateImport` are separate runtime operations; their subclass or materialization behaviour must not be substituted for `VerifyImport`'s exact selected-linker matching.
9. No multiple-physical-provider export-superset search is performed by this branch. It verifies imports in the already chosen `SourceLinker` rather than searching every same-name package in a catalogue.

These are game-specific facts from the January 2003 Unreal II source, not inferred from the generic Warfare version-126 tree. They establish the precise v126/licensee-0 source-specific verification branch. Other version/licensee combinations without a matching verified game branch remain explicitly unproven for identical import-verification behaviour.

## Source-version boundary

The December 2000 source proves a minimum loadable version of 60 and exact emitted version 69/licensee `0x7F`, with earlier licensee-zero packages loadable through the same linker. The January 2003 source proves an emitted package version of 126/licensee zero. Original older/custom packages may be structurally valid without an independent, exact game-specific import-verification claim. In all cases a physical package's on-disk metadata alone cannot recreate the original runtime mount/search context.
