# Unreal II / UE2 Dependency and Import Resolution

## Scope

This specification documents dependency/import resolution for the supplied Unreal II source:

- Repository: `ardenee/unreal2src`
- Branch: `main`
- Tree: `Unreal II The Awakening [01-07-2003]/U2XMP_all/depot`

It is paired with `unreal2-ue2-package-format.md`.

Only behavior active in this Unreal II source is authoritative here. UT99, UT2003, UT2004, UE2.5, and other branches must not supply missing fallbacks.

## Authoritative source references

| Behavior | Source |
|---|---|
| import verification | `Core/Src/UnLinker.cpp: ULinkerLoad::VerifyImport` |
| verify pass | `Core/Src/UnLinker.cpp: ULinkerLoad::Verify` |
| export hash | `Core/Src/UnLinker.cpp: HashNames`, `ULinkerLoad::ULinkerLoad` |
| export class identity | `GetExportClassPackage`, `GetExportClassName` |
| explicit export search | `FindExportIndex` |
| import realization | `CreateImport` |
| object-index resolution | `IndexToObject` |
| provider package loading | `Core/Src/UnObj.cpp: UObject::GetPackageLinker` |
| package creation | `Core/Src/UnObj.cpp: UObject::CreatePackage` |

## Dependency model

The serialized import table establishes external object references. A top-level package import names the provider package. Nested imports form a negative-index parent chain underneath that package.

For catalog dependency extraction, the top-level package named by an import chain is the package dependency directly supported by the package bytes.

Engine verification goes further: it attempts to find the requested object in that provider package and may bind runtime-native objects. These are separate questions and UnrealDB should preserve that distinction.

## Verify pass

Unless `LOAD_NoVerify` is set, the linker calls `Verify()` after loading tables and constructing the export hash.

`Verify()`:

1. clears `PKG_BrokenLinks` on the linker-root package;
2. loops from `0` to `Summary.ImportCount - 1`;
3. calls `VerifyImport(i)`;
4. removes this linker from `GObjLoaders` and rethrows if verification throws;
5. marks `Verified = 1`.

The loop uses the serialized `Summary.ImportCount`, not an arbitrary scan.

## VerifyImport early return

Verification returns immediately when:

- both `SourceLinker` exists and `SourceIndex != INDEX_NONE`; or
- `ClassPackage == NAME_None`; or
- `ClassName == NAME_None`; or
- `ObjectName == NAME_None`.

The first condition is notably stricter than simply testing `SourceIndex`: this Unreal II implementation requires the provider linker to exist as well before treating that state as already verified.

## Top-level package imports

When `PackageIndex == 0`, source assertions require:

- `ClassName == Package`
- `ClassPackage == Core`

The engine creates/finds a top-level `UPackage` named by `Import.ObjectName`, then calls:

`GetPackageLinker(TmpPkg, NULL, LOAD_Throw | (LoadFlags & LOAD_Propagate), NULL, NULL)`

Any thrown error is simply rethrown.

### No UT99 UnrealI -> UnrealShare package retry

The reviewed Unreal II implementation contains **no active UnrealI-to-UnrealShare retry** here.

This is an important difference from the UT99 retail source. UnrealDB must not carry that UT99 fallback into the Unreal II reader.

## Provider filename resolution

With no explicit filename, `GetPackageLinker` resolves the package by `InOuter->GetName()` using `appFindPackageFile`.

It first reuses an already-loaded linker whose `LinkerRoot` is the requested package. Otherwise it resolves a file and creates a new `ULinkerLoad`.

A compatible GUID can be supplied by callers and is checked against the provider summary, but `VerifyImport` passes no compatible GUID.

Therefore ordinary import verification in this source resolves the provider by package name, not by an import-table GUID.

UnrealDB cannot reproduce the engine's filesystem search policy merely from package bytes. Catalog resolution should use its own known-file/package-name index while keeping the source semantics explicit.

## Nested imports

A non-top-level import must have `PackageIndex < 0`.

The engine:

1. recursively verifies the parent import at `-PackageIndex - 1`;
2. copies the parent's `SourceLinker`;
3. only if that linker exists, walks upward through negative parent indices;
4. counts the parent depth;
5. creates/finds a runtime package named by the top import's `ObjectName`.

Unlike the UT99 implementation previously documented, the source-linker assertion is commented out here:

`//check(Import.SourceLinker);`

Thus Unreal II explicitly tolerates the parent verification leaving no provider linker at this point.

## Export hash

The hash function in this Unreal II source is exactly:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

The 256-entry export hash uses:

- A = export object name
- B = export class name
- C = export class package

### No UnrealShare hash normalization

Unlike the UT99 retail source, Unreal II's `HashNames` does **not** rewrite `UnrealShare` to `UnrealI`.

UnrealDB must not import that UT99 compatibility normalization.

## Candidate matching

If `Import.SourceLinker` exists, `VerifyImport` searches the provider's hash bucket.

A provider export is a candidate only when all three match exactly:

- `Source.ObjectName == Import.ObjectName`
- provider export class name == `Import.ClassName`
- provider export class package == `Import.ClassPackage`

### No UT99 class-package compatibility hack

There is no active rule accepting an `UnrealShare` provider class package when the import requests `UnrealI`.

Matching is exact in this Unreal II source.

## Parent/outer matching

For nested imports, after name/class matching, the requested parent import is examined.

If the parent has a source linker:

- if parent `SourceIndex == INDEX_NONE`, the candidate is rejected unless `Source.PackageIndex == 0`;
- otherwise, if `ParentImport.SourceIndex + 1 != Source.PackageIndex`, the candidate is rejected unless `Source.PackageIndex == 0`.

Thus a root export (`PackageIndex == 0`) is accepted as the fallback outer when the expected provider-side parent cannot be matched exactly.

This behavior is source-proven and may be reproduced where UnrealDB needs object-level resolution.

## RF_Public handling

When a matching provider export lacks `RF_Public`:

- with `LOAD_Forgiving`, the current package is marked `PKG_BrokenLinks`, a broken-import message is logged, and verification returns;
- without forgiving mode, the historical `FailedImportPrivate` throw is inside `#if 0` and is **disabled**.

Execution therefore continues and sets `Import.SourceIndex = j` even when the matched export is non-public, provided forgiving mode was not requested.

This differs materially from the UT99 retail implementation and must not be normalized to UT99 behavior.

## Active Mesh -> LodMesh compatibility fallback

After the provider search, the source tests:

`appStricmp(*Import.ClassName, TEXT("Mesh")) == 0`

If true, it mutates:

`Import.ClassName = FName(TEXT("LodMesh"))`

and jumps back to the export lookup.

This is active source behavior.

As written, this check is not guarded by `SourceIndex == INDEX_NONE`. Therefore a `Mesh` import is renamed to `LodMesh` and lookup is repeated even if the preceding `Mesh` lookup found a candidate.

UnrealDB should preserve the exact source flow if implementing engine-compatible object resolution rather than simplifying it into a conventional “only if missing” fallback.

## Disabled ClassRemap

A configuration-driven `ClassRemap` block exists in `VerifyImport`, but it is enclosed by:

`#if 0 //!!MERGE`

It is not compiled behavior.

Therefore:

- it is not an Unreal II fallback;
- it must not be implemented by UnrealDB;
- it must not be inferred from an INI file;
- it must not be used when resolving package dependencies.

## Runtime native/transient binding

If no provider export was selected and a nested package context exists, the engine attempts runtime object binding.

It finds:

1. the runtime package named by `Import.ClassPackage`;
2. the runtime class named by `Import.ClassName`;
3. an object of that class under the top-level package `Pkg`, named by `Import.ObjectName`.

If the object exists and has all of:

- `RF_Public`
- `RF_Native`
- `RF_Transient`

then it is assigned directly to `Import.XObject`.

This is runtime state, not a dependency derivable from package bytes alone.

## Unreal II SafeReplace behavior

If `FindClass` exists but the required native/transient object does not satisfy the test, Unreal II:

- reports an editor load error when appropriate;
- logs `Missing <class> <import>`;
- sets `SafeReplace = 1`.

Crucially, this source does **not** test `CLASS_SafeReplace` here. Merely finding the runtime class and failing to bind the native/transient object sets `SafeReplace`.

That is another material difference from the UT99 retail implementation and must remain Unreal II-specific.

## Disabled PackageRemap

A configuration-driven `PackageRemap` block is also present but enclosed by:

`#if 0 //!!MERGE`

It would dynamically create a replacement top-level package import, but it is disabled.

It is therefore not part of Unreal II dependency resolution and must not be implemented.

The disabled block contains a `goto SharewareHack`, but there is no active `SharewareHack` label/path in the compiled function. This is dead merge-era code, not evidence of an active fallback.

## Final unresolved-import handling

The final failure block is reached only inside the nested-runtime branch (`Import.SourceIndex == INDEX_NONE && Pkg != NULL`).

If there is still no `Import.XObject` and `SafeReplace == 0`:

1. it logs `Failed import`;
2. if `LOAD_Forgiving`:
   - sets `PKG_BrokenLinks`;
   - logs `Broken import`;
   - returns;
3. otherwise it throws `FailedImport`.

If `SafeReplace == 1`, this final failure is suppressed.

Because this block is nested beneath `Pkg != NULL`, UnrealDB must not rewrite it into a generic unconditional “all unresolved imports throw” rule.

## CreateImport

When an import object is requested:

1. if `XObject` already exists, return it;
2. if no `SourceLinker`, bracket a `VerifyImport(Index)` call with `BeginLoad()` / `EndLoad()`;
3. if `SourceIndex != INDEX_NONE`, call the provider linker's `CreateExport(SourceIndex)`;
4. assign the resulting object to `XObject`;
5. increment `GImportCount`;
6. return `XObject`.

Thus import verification and actual provider-export object construction are separate stages.

## FindExportIndex is not VerifyImport

`FindExportIndex` is used when locating a requested object in a linker and has additional behavior that must not be incorrectly attributed to import verification.

It first performs an exact hashed search by:

- object name;
- requested package index, unless `INDEX_NONE`;
- class package;
- class name.

If no exact result is found, Unreal II then scans all exports with the requested object name/package and resolves each export's runtime class. It walks that class's superclass chain and returns the export if any parent class name equals the requested `ClassName`.

Only after that subclass search does it apply the active `Mesh -> LodMesh` retry.

This subclass search is source-proven Unreal II behavior for `FindExportIndex`, but `VerifyImport` itself does not perform it.

## Dependency extraction for UnrealDB

For package-level dependency discovery, UnrealDB should derive dependencies from the serialized import hierarchy rather than trying to emulate the complete runtime loader.

For each import:

- follow negative `PackageIndex` parents until reaching the top-level import;
- the top-level `ObjectName` is the provider package name when the chain satisfies the package-import structure;
- preserve the complete import path, class name, and class package for object-level analysis;
- distinguish “declared dependency” from “resolved provider file”;
- do not require runtime-native object creation to report the declared package dependency.

Provider matching can then use UnrealDB's catalogue of actual files/packages. Engine filesystem search configuration is not serialized in the package.

## Exact active compatibility behavior

For this source revision, the compatibility behavior proven active in the relevant resolution paths is:

1. `Mesh -> LodMesh` in `VerifyImport`;
2. root-export outer acceptance when nested parent matching does not line up exactly;
3. runtime public/native/transient object binding;
4. Unreal II's broad `SafeReplace` suppression after finding the runtime class;
5. `LOAD_Forgiving` broken-link handling;
6. `Mesh -> LodMesh` in `FindExportIndex`;
7. subclass matching in `FindExportIndex`.

The following UT99 behaviors are **not present** and must not be inherited:

- `UnrealI -> UnrealShare` provider retry;
- `UnrealShare -> UnrealI` hash normalization;
- UnrealI/UnrealShare class-package matching hack;
- dynamic UnrealShare reparenting;
- UT99's `CLASS_SafeReplace` test.

The following Unreal II code is present but **disabled** and therefore not behavior:

- `ClassRemap`;
- `PackageRemap`.

## UnrealDB conformance requirements

For Unreal II dependency resolution:

1. derive package dependencies from import parent chains;
2. preserve exact serialized class/package/object identity;
3. do not import UT99 UnrealI/UnrealShare compatibility behavior;
4. implement `Mesh -> LodMesh` only where source-compatible object resolution requires it;
5. preserve exact parent/outer candidate rules;
6. distinguish `VerifyImport` from `FindExportIndex`;
7. do not implement disabled `ClassRemap` or `PackageRemap`;
8. do not infer runtime-native objects from package bytes;
9. do not require engine filesystem configuration for declaring a dependency;
10. keep forgiving-mode results distinct from successful provider resolution;
11. model Unreal II's non-public-export behavior exactly if emulating the runtime verifier;
12. model Unreal II's broad `SafeReplace` behavior exactly if emulating runtime verification;
13. keep these rules scoped to the supplied Unreal II source revision.

## Source-reference matrix

| Rule | Source symbol |
|---|---|
| verify loop | `ULinkerLoad::Verify` |
| early-return state | `ULinkerLoad::VerifyImport` |
| top-level package structure | `ULinkerLoad::VerifyImport` |
| parent recursion | `ULinkerLoad::VerifyImport` |
| hash formula | `HashNames` |
| exact import candidate matching | `ULinkerLoad::VerifyImport` |
| outer matching | `ULinkerLoad::VerifyImport` |
| non-public behavior | `ULinkerLoad::VerifyImport` |
| Mesh/LodMesh | `VerifyImport`, `FindExportIndex` |
| disabled remaps | `VerifyImport` `#if 0 //!!MERGE` blocks |
| runtime native/transient binding | `VerifyImport` |
| SafeReplace behavior | `VerifyImport` |
| forgiving broken links | `VerifyImport` |
| provider package resolution | `UObject::GetPackageLinker` |
| import realization | `ULinkerLoad::CreateImport` |
| subclass export lookup | `ULinkerLoad::FindExportIndex` |
| signed object lookup | `ULinkerLoad::IndexToObject` |

## Next specification

The next source target should be **UE2.5 package format and reading**, using only the supplied `ardenee/UE2.5` source as authority. Its rules must be established independently rather than assuming that Unreal II's UE2 behavior carried forward unchanged.
