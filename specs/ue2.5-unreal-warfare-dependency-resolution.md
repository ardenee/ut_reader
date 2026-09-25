# UE2.5 / Unreal Warfare Dependency and Import Resolution

## Scope

This specification documents dependency and import resolution from the supplied UE2.5 source only:

- Repository: `ardenee/UE2.5`
- Branch: `main`
- Source tree: `Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`
- Package version in this tree: 126

It is paired with `ue2.5-unreal-warfare-package-format.md`.

No Unreal II, UT2003, UT2004, UT99, or other implementation is used to supply behavior absent from this source.

## Authoritative source references

| Behavior | Source |
|---|---|
| verify pass | `Core/Src/UnLinker.cpp: ULinkerLoad::Verify` |
| import verification | `Core/Src/UnLinker.cpp: ULinkerLoad::VerifyImport` |
| hash formula | `Core/Src/UnLinker.cpp: HashNames` |
| class identity | `GetExportClassPackage`, `GetExportClassName` |
| explicit export lookup | `FindExportIndex` |
| import realization | `CreateImport` |
| signed object resolution | `IndexToObject` |
| provider package loading | `Core/Src/UnObj.cpp: UObject::GetPackageLinker` |
| import path | `ULinker::GetImportFullName` |

## Declared dependency model

Serialized imports establish the package's external references.

A top-level package import has `PackageIndex == 0` and identifies a provider package by `ObjectName`. Nested imports use negative `PackageIndex` values to point to parent imports.

For UnrealDB, this serialized hierarchy is sufficient to report declared package dependencies. Runtime verification of a particular imported object is a separate operation.

## Verify pass

Unless `LOAD_NoVerify` was supplied, linker construction calls `Verify()`.

`Verify()`:

1. clears `PKG_BrokenLinks` on the linker-root package;
2. loops exactly `Summary.ImportCount` imports;
3. calls `VerifyImport(i)`;
4. if verification throws, removes this linker from `GObjLoaders` and rethrows;
5. sets `Verified = 1`.

## VerifyImport early return

The import is skipped when:

- `SourceLinker` exists **and** `SourceIndex != INDEX_NONE`;
- `ClassPackage == NAME_None`;
- `ClassName == NAME_None`;
- `ObjectName == NAME_None`.

A non-null SourceIndex alone is not the source condition; SourceLinker must also exist.

## Top-level provider imports

For `PackageIndex == 0`, source checks require:

- `ClassName == Package`;
- `ClassPackage == Core`.

The engine creates/finds a package named by `ObjectName` and calls:

`GetPackageLinker(TmpPkg, NULL, LOAD_Throw | (LoadFlags & LOAD_Propagate), NULL, NULL)`

A caught error is rethrown unchanged.

There is no active alternate provider retry in this function.

## Provider package lookup

With no explicit filename, `GetPackageLinker` resolves the provider from the requested package name through `appFindPackageFile`.

It can reuse an already loaded linker for the same `LinkerRoot`.

Ordinary `VerifyImport` supplies no compatible GUID, so this import path is not resolving providers from a GUID serialized in the import.

Filesystem search configuration is runtime state and is not reconstructable from the package's import table. UnrealDB should use its catalogue to associate the declared package name with known files.

## Nested imports

For nonzero `PackageIndex`, the source requires it to be negative.

It:

1. recursively verifies parent import `-PackageIndex - 1`;
2. copies the parent's `SourceLinker`;
3. does not assert that the linker must exist — the former check is commented out;
4. if a SourceLinker exists, walks negative parents to the top;
5. counts the depth;
6. creates/finds runtime package `Pkg` from the top import's `ObjectName`.

The computed `Depth` has no later active use in this function.

## Export hash

The exact UE2.5 hash is:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

The linker builds 256 buckets.

Import lookup hashes:

- import object name;
- import class name;
- import class package.

There is no UnrealShare/UnrealI normalization in this source.

## Exact provider export match

Within the provider hash bucket, an export matches when:

- `Source.ObjectName == Import.ObjectName`;
- provider `GetExportClassName(j) == Import.ClassName`;
- provider `GetExportClassPackage(j) == Import.ClassPackage`.

No class-package alias or generic compatibility map is present.

## Export class identity

`GetExportClassName`:

- negative ClassIndex -> referenced import ObjectName;
- positive ClassIndex -> referenced export ObjectName;
- zero -> `Class`.

`GetExportClassPackage`:

- negative ClassIndex -> class import's parent import ObjectName;
- positive ClassIndex -> current linker-root package name;
- zero -> `Core`.

For a negative ClassIndex, source checks that the class import has a negative PackageIndex.

## Parent/outer matching

For a nested import, when its parent import has a SourceLinker:

- if parent `SourceIndex == INDEX_NONE`, a candidate with nonzero `Source.PackageIndex` is rejected;
- otherwise, if `ParentImport.SourceIndex + 1 != Source.PackageIndex`, a candidate with nonzero `Source.PackageIndex` is rejected.

Consequently a candidate at provider root (`Source.PackageIndex == 0`) remains acceptable when the provider-side parent does not match exactly.

This is an explicit source rule, not a generalized fuzzy outer match.

## RF_Public

A matching provider export must normally carry `RF_Public`.

If it does not:

- under `LOAD_Forgiving`, the linker-root package is marked `PKG_BrokenLinks`, a broken-import message is logged, and verification returns;
- otherwise the source throws `FailedImportPrivate`.

If public, `Import.SourceIndex` is set to the matching export index.

## Mesh -> LodMesh

After the provider search, if `Import.ClassName` compares case-insensitively equal to `Mesh`, the source changes it to `LodMesh` and jumps back to repeat the lookup.

This is active hardcoded behavior.

It is not guarded by `SourceIndex == INDEX_NONE`; therefore the source flow performs the LodMesh retry even after a Mesh candidate was assigned.

This must not be generalized into arbitrary class remapping.

## Runtime public/native/transient binding

When no provider export has been selected and `Pkg != NULL`, the engine searches runtime state:

1. find package named by `Import.ClassPackage`;
2. find class named by `Import.ClassName` within it;
3. find object named by `Import.ObjectName` under `Pkg`.

The runtime object satisfies the import only if it has all three flags:

- `RF_Public`;
- `RF_Native`;
- `RF_Transient`.

It is then assigned to `Import.XObject` and `GImportCount` is incremented.

This cannot be inferred from package bytes alone.

## SafeReplace behavior

If the runtime class exists but no acceptable public/native/transient object is found, this source:

- optionally records an editor resource-load error;
- logs the missing resource;
- sets local `SafeReplace = 1`.

There is **no `CLASS_SafeReplace` test** in this implementation.

Therefore the presence of the runtime class is enough for this branch to suppress the later fatal missing-import path.

This is runtime behavior, not a serialized package rule.

## Final unresolved import

Inside the `Import.SourceIndex == INDEX_NONE && Pkg != NULL` branch, if there is no `XObject` and `SafeReplace == 0`:

- log failed import;
- with `LOAD_Forgiving`, mark `PKG_BrokenLinks`, log broken import, and return;
- otherwise throw `FailedImport`.

This failure block is conditional on `Pkg != NULL`; it must not be rewritten as an unconditional rule applying to every unresolved serialized import.

## No active ClassRemap or PackageRemap

The reviewed UE2.5 `UnLinker.cpp` contains no `ClassRemap` or `PackageRemap` implementation in `VerifyImport`.

It also contains no `UnrealI`, `UnrealShare`, or `CLASS_SafeReplace` compatibility logic.

Therefore none of those mechanisms belongs in the UE2.5 dependency resolver documented by this source.

This is stronger than merely saying a remap is unavailable from package bytes: for this supplied source tree, the relevant linker implementation itself does not contain those remap paths.

## FindExportIndex

`FindExportIndex` is distinct from `VerifyImport`.

First it performs hashed exact matching on:

- ObjectName;
- PackageIndex, unless requested PackageIndex is `INDEX_NONE`;
- ClassPackage;
- ClassName.

If no exact result is found, it scans all exports with matching object name and package constraint.

For each, it resolves the export's runtime class and walks its superclass chain. If any class in that chain has the requested ClassName, that export is returned.

After that subclass search, if requested class is `Mesh`, it changes it to `LodMesh` and repeats.

Thus subclass matching is active for `FindExportIndex`, but it is not part of `VerifyImport`'s provider candidate test.

## CreateImport

When an import object is requested:

1. return existing `XObject` if present;
2. if `SourceLinker` is absent, bracket `VerifyImport(Index)` with `BeginLoad()` / `EndLoad()`;
3. if `SourceIndex != INDEX_NONE`, call `SourceLinker->CreateExport(SourceIndex)`;
4. assign the result to `XObject`;
5. increment `GImportCount`;
6. return `XObject`.

Verification and provider export construction are therefore separate phases.

## Signed object resolution

`IndexToObject` interprets object references as:

- positive -> local export at index `Index - 1`;
- negative -> import at index `-Index - 1`;
- zero -> null.

It bounds-checks the selected table before calling `CreateExport` or `CreateImport`.

## Import path construction

`GetImportFullName(i)` walks from one import through negative `PackageIndex` parent references until zero, prepending each ObjectName and separating levels with dots.

The returned display value is:

`<starting ClassName> <outer.object.path>`

For UnrealDB, the same parent chain should be retained structurally rather than depending only on this formatted string.

## Declared dependency versus resolvability

UnrealDB should keep these concepts separate:

**Declared package dependency:** proven by the serialized import chain and its root package import.

**Provider candidate:** a catalogue file/package that corresponds to the root package name.

**Object-level source resolution:** requires exact class/object matching, outer matching, visibility rules, and active source compatibility behavior.

**Runtime satisfaction:** can depend on loaded packages, native classes/objects, flags, and filesystem/package-loader state unavailable from the file alone.

A declared dependency must not disappear merely because UnrealDB cannot reproduce the runtime environment required to prove object-level resolution.

## Active compatibility inventory

The reviewed UE2.5 source proves these relevant behaviors:

1. exact class/object/provider matching;
2. provider-root acceptance when parent/outer matching fails;
3. `Mesh -> LodMesh` in `VerifyImport`;
4. public/native/transient runtime object binding;
5. SafeReplace suppression when a runtime class exists but its object is missing/ineligible;
6. forgiving broken-link handling;
7. subclass matching in `FindExportIndex`;
8. `Mesh -> LodMesh` in `FindExportIndex`.

It does **not** prove:

- UnrealI -> UnrealShare package retry;
- UnrealShare hash normalization;
- UnrealI/UnrealShare class-package equivalence;
- generic ClassRemap;
- generic PackageRemap;
- CLASS_SafeReplace gating;
- arbitrary aliases;
- fuzzy provider-name substitution.

Those must not be added.

## Comparison with supplied Unreal II source

The active dependency code in this UE2.5 tree is extremely close to the supplied Unreal II implementation, including:

- top-level package loading;
- nested import recursion;
- exact hash formula;
- exact provider match;
- root-export outer fallback;
- Mesh -> LodMesh;
- runtime native/transient lookup;
- broad SafeReplace behavior;
- subclass search in FindExportIndex.

However, the source histories are not interchangeable.

The supplied Unreal II source contains disabled merge-era `ClassRemap` and `PackageRemap` blocks. This UE2.5 `UnLinker.cpp` does not contain those blocks at all.

Therefore UnrealDB must not infer that UE2.5 supports those mechanisms merely because related Unreal II source contains disabled remnants.

## UnrealDB conformance requirements

For this UE2.5 target:

1. derive declared package dependencies from import parent chains;
2. identify top-level provider imports only according to serialized structure;
3. preserve ClassPackage, ClassName, ObjectName, and complete parent chain;
4. distinguish declared dependencies from proven object resolution;
5. use exact provider class identity when doing object-level resolution;
6. preserve the exact parent/outer candidate rules;
7. enforce RF_Public if emulating VerifyImport;
8. implement only the explicit Mesh -> LodMesh compatibility path;
9. do not implement UnrealI/UnrealShare compatibility;
10. do not implement ClassRemap or PackageRemap;
11. do not infer runtime public/native/transient objects from package bytes;
12. do not model SafeReplace as CLASS_SafeReplace — this source does not test that flag;
13. keep FindExportIndex subclass matching separate from VerifyImport;
14. preserve forgiving-mode broken-link semantics separately from successful resolution;
15. keep all these rules scoped to this supplied UE2.5 source.

## Source-reference matrix

| Rule | Source symbol |
|---|---|
| verify pass | `ULinkerLoad::Verify` |
| early return | `ULinkerLoad::VerifyImport` |
| top-level provider | `ULinkerLoad::VerifyImport` |
| nested parent traversal | `ULinkerLoad::VerifyImport` |
| hash formula | `HashNames` |
| exact provider match | `ULinkerLoad::VerifyImport` |
| parent/outer matching | `ULinkerLoad::VerifyImport` |
| RF_Public handling | `ULinkerLoad::VerifyImport` |
| Mesh -> LodMesh | `VerifyImport`, `FindExportIndex` |
| runtime native/transient lookup | `VerifyImport` |
| SafeReplace | `VerifyImport` |
| forgiving broken links | `VerifyImport` |
| class identity | `GetExportClassName`, `GetExportClassPackage` |
| subclass export lookup | `FindExportIndex` |
| import realization | `CreateImport` |
| signed object references | `IndexToObject` |
| import path | `ULinker::GetImportFullName` |
| package lookup | `UObject::GetPackageLinker` in `Core/Src/UnObj.cpp` |

## Next specification

The next target should be **UT2003 package format and reading**, using only the supplied `ardenee/Unreal_Tournament_2003_v2107` source as authority. It must be established independently even where it appears structurally identical to UE2.5.
