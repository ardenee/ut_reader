# UE1 / Unreal Tournament 99 Retail v1.400 Dependency and Import Resolution

## Scope

This specification documents dependency identification and import verification behavior proven by the retail Unreal Tournament v1.400 source tree dated 1999-11-30.

- Repository: `ardenee/UT99src`
- Branch: `main`
- Tree: `Unreal Tournament [v1.400] [1999-11-30] (Retail)`
- Primary implementation: `Core/Src/UnLinker.h`

This document is deliberately separate from the package-layout specification. It records what the retail loader actually does when mapping imports to provider packages and exports. It does not invent generalized UE1 fallbacks.

## Source entry points

| Behavior | Symbol |
|---|---|
| Verify all imports | `ULinkerLoad::Verify` |
| Verify one import | `ULinkerLoad::VerifyImport` |
| Export lookup hash | `HashNames`, constructor export-hash build |
| Export class package | `GetExportClassPackage` |
| Export class name | `GetExportClassName` |
| Import path rendering | `GetImportFullName` |
| Export path rendering | `GetExportFullName` |
| Direct export lookup | `FindExportIndex` |
| Runtime import creation | `CreateImport` |
| Signed object mapping | `IndexToObject` |

All paths are relative to the retail tree.

## Verification entry point

Unless the linker is opened with `LOAD_NoVerify`, its constructor calls `Verify()` after names, imports, exports, and the export hash have been built.

`Verify()` clears `PKG_BrokenLinks` on the linker root package, then iterates from import 0 through `Summary.ImportCount - 1` and calls `VerifyImport(i)`. If verification throws, the linker is removed from the global loader list and the error is rethrown.

Therefore normal retail loading verifies every serialized import, not merely top-level package imports.

## Imports that are skipped

`VerifyImport` immediately returns when any of these is true:

- `SourceIndex != INDEX_NONE` — already mapped;
- `ClassPackage == NAME_None`;
- `ClassName == NAME_None`;
- `ObjectName == NAME_None`.

The source comment describes the latter cases as not relevant in the current context.

## Top-level package imports

An import with `PackageIndex == 0` is required by assertions to have:

- `ClassName == Package`;
- `ClassPackage == Core`.

The loader creates/fetches a package object named by `Import.ObjectName` and calls `GetPackageLinker` for it using `LOAD_Throw | (LoadFlags & LOAD_Propagate)`.

This is the direct source-backed rule for identifying an external provider package: a root import is a `Core.Package` import whose `ObjectName` names that package.

### UnrealI -> UnrealShare provider fallback

If loading that top-level package linker throws and the requested package is `UnrealI`, the retail code retries with a package named `UnrealShare`.

No equivalent generic package alias fallback appears in this function.

For UnrealDB, this fallback is source-backed specifically for this retail UT99 implementation. It must not become a generic "try another package" rule.

## Nested imports and provider inheritance

For an import with nonzero `PackageIndex`, the code requires `PackageIndex < 0`.

It first verifies the parent import at:

`-PackageIndex - 1`

Then it assigns the child's `SourceLinker` from that parent import's `SourceLinker`.

Thus nested imports inherit the provider linker established by their parent chain.

The code then walks upward while `PackageIndex < 0`, counting `Depth`, until it reaches the top import. It creates a runtime package object named from that top import's `ObjectName`. This `Pkg` is later used by the native/transient and UnrealI compatibility paths.

## Export lookup key

The provider linker's export hash contains 256 buckets.

The hash input is:

- export/import object name;
- class name;
- class package.

`HashNames(A,B,C)` computes:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

with one explicit compatibility normalization: if `C == UnrealShare`, it first substitutes `UnrealI`.

The final bucket is masked with `255`.

The hash only narrows candidates. A candidate still has to pass all identity and outer checks below.

## Exact export identity match

For each export in the selected hash bucket, the normal match requires:

1. `Source.ObjectName == Import.ObjectName`;
2. provider `GetExportClassName(j) == Import.ClassName`;
3. provider `GetExportClassPackage(j) == Import.ClassPackage`.

There is one explicit exception to rule 3:

If the requested `Import.ClassPackage == UnrealI` and the provider export's class package is `UnrealShare`, `ClassHack` makes the class-package comparison succeed.

This is a one-direction compatibility rule in this code. Do not generalize it to arbitrary package aliases.

## Parent / outer matching

When the import is nested (`Import.PackageIndex < 0`), the parent import is examined.

If the parent has a `SourceLinker`:

- when the parent's `SourceIndex == INDEX_NONE`, a candidate source export is accepted by this outer check only when `Source.PackageIndex == 0`;
- when the parent has a resolved source index, the expected source parent is `ParentImport.SourceIndex + 1`;
- if that expected parent does not equal `Source.PackageIndex`, the candidate is still accepted when `Source.PackageIndex == 0`; otherwise it is rejected and lookup continues.

That root-export allowance is explicit retail behavior and must be represented exactly if UnrealDB is reproducing verification rather than merely listing dependencies.

## Public-export requirement

After identity/outer matching, the candidate must have `RF_Public`.

If it is not public:

- with `LOAD_Forgiving`, the current package is marked `PKG_BrokenLinks`, a broken-import message is logged, and verification returns without mapping the import;
- otherwise the loader throws `FailedImportPrivate`.

A successful public match stores the provider export index in `Import.SourceIndex`.

## Mesh -> LodMesh fallback

After the first export search, the code checks the import class name case-insensitively.

If it equals `Mesh`, it changes `Import.ClassName` to `LodMesh` and repeats the export search.

This mutation occurs regardless of whether the first search set `SourceIndex`; the source places the `Mesh` test after the search loop without an `SourceIndex == INDEX_NONE` guard.

`FindExportIndex` contains the same explicit class-name fallback: if lookup for class `Mesh` fails, it retries with `LodMesh`.

This is a concrete UT99 old-version fallback. It is not evidence for any other class remap.

## Runtime public/native/transient object resolution

If no provider export was mapped and `Pkg != NULL`, the loader attempts runtime resolution.

It finds a loaded package named by `Import.ClassPackage`, then a class named by `Import.ClassName` within that package, then calls `StaticFindObject` for `Import.ObjectName` within `Pkg`.

The runtime object satisfies the import only if all three flags are present:

- `RF_Public`;
- `RF_Native`;
- `RF_Transient`.

When satisfied, the object is stored in `Import.XObject` rather than mapped to a provider export.

This behavior depends on the engine's live object/class registry. Package bytes alone do not prove that such an object exists at runtime. UnrealDB must not invent a package-file substitute for this path.

## CLASS_SafeReplace behavior

If the runtime class exists and has `CLASS_SafeReplace`, a missing object sets `SafeReplace = 1`. The optional conflict logger can report it, but the missing import does not proceed to the normal fatal missing-import branch.

This too depends on runtime class metadata. It is not a generic serialized dependency fallback unless UnrealDB has authoritative class metadata proving the flag.

## Nested UnrealI -> UnrealShare fallback

A second, distinct UnrealI compatibility path exists when all of these are true:

- no runtime object was found;
- `Pkg != NULL`;
- `Pkg->GetFName() == UnrealI`;
- `Depth == 1`.

The loader mutates the current import's `PackageIndex` so it points to a newly appended import. It appends a synthetic import:

- `ClassPackage = Core`;
- `ClassName = Package`;
- `PackageIndex = 0`;
- `ObjectName = UnrealShare`;
- runtime fields reset.

It verifies that synthetic `UnrealShare` package import, then jumps back to re-run verification of the original import using the altered parent chain.

This is materially different from the top-level "if UnrealI package load fails, try UnrealShare" rule. Both must be kept separately when reproducing retail behavior.

## Missing import result

After the runtime and compatibility paths, if the import still has neither a runtime object nor `SafeReplace`:

- `LOAD_Forgiving`: set `PKG_BrokenLinks`, log a broken import, return;
- otherwise: throw `FailedImport`.

There is no general "accept nearest class", filename substitution, case-changing package alias, arbitrary remap table, or provider search fallback in `VerifyImport`.

## Import path construction

`GetImportFullName(i)` starts with signed import reference `-i-1` and repeatedly follows each import's `PackageIndex` until zero.

It prepends each `ObjectName`, separated by dots, and prefixes the result with the original import's `ClassName` plus a space.

This proves that the dependency object's logical path comes from the import outer chain, not merely the leaf `ObjectName`.

UnrealDB should retain enough information to reconstruct this chain exactly.

## Export path construction

`GetExportFullName(i)` starts at export reference `i+1`, follows positive export `PackageIndex` values to zero, and prepends export names separated by dots.

The class label is determined from `ClassIndex`:
- positive -> local export class name;
- negative -> imported class name;
- zero -> `Class`.

The path is rooted at `LinkerRoot->GetPathName()`, unless the caller supplies a fake root.

## Provider export class identity

`GetExportClassName`:
- negative `ClassIndex` -> imported class object's `ObjectName`;
- positive -> local class export's `ObjectName`;
- zero -> `Class`.

`GetExportClassPackage`:
- negative class index -> the class import must itself have a negative parent; that parent import's `ObjectName` is the class package;
- positive -> current linker root package name;
- zero -> `Core`.

These exact derived values participate in import matching.

## Hash compatibility for UnrealShare

`HashNames` normalizes class package `UnrealShare` to `UnrealI` before calculating the hash.

This is necessary because `VerifyImport` can deliberately treat an `UnrealShare` class package as matching an import requesting `UnrealI`. Without the hash normalization those equivalent candidates could fall into different buckets and never be compared.

This normalization is specifically tied to the retail UnrealI/UnrealShare compatibility behavior.

## CreateImport after verification

`CreateImport(Index)` does not independently search for dependencies. If `Import.XObject` is null and `Import.SourceIndex >= 0`, it requires `SourceLinker` and creates the provider export at `SourceIndex`, then stores that object in `Import.XObject`.

Thus provider discovery/matching belongs to verification; object creation consumes the mapping already established.

## Dependency concepts for UnrealDB

For static cataloging, the serialized import table proves two useful levels:

**Required package**: each root `Core.Package` import (`PackageIndex == 0`) identifies a provider package name via `ObjectName`.

**Required object**: a nested import identifies an object by its full import outer chain plus `ClassName` and `ClassPackage`.

However, "the engine can successfully resolve this dependency" is stronger than "the package declares this dependency." Exact engine resolution additionally depends on provider exports, `RF_Public`, compatibility fallbacks, and in some cases live runtime native/class state.

UnrealDB should keep these concepts distinct.

## Fallback inventory: retail v1.400 only

The source-backed compatibility behaviors in the reviewed import-resolution path are:

| Fallback / exception | Exact scope |
|---|---|
| Top-level `UnrealI` provider load -> `UnrealShare` | only when loading the requested `UnrealI` package linker throws |
| Class-package `UnrealI` accepts provider class package `UnrealShare` | export identity comparison |
| Hash `UnrealShare` -> `UnrealI` | hash compatibility for the above rule |
| `Mesh` -> `LodMesh` | import/export class lookup |
| Root export allowed when expected parent does not match | nested import outer matching |
| public/native/transient runtime object | only from live runtime object registry |
| `CLASS_SafeReplace` | only from live runtime class metadata |
| Nested depth-1 `UnrealI` -> synthetic `UnrealShare` package parent | only under the exact conditions documented above |
| `LOAD_Forgiving` broken-link acceptance | loader mode, marks package broken |

No other fallback should be attributed to retail UT99 without another authoritative source path proving it.

## Runtime/config-only boundary

The reviewed retail verification code contains no generic INI-driven `[ClassRemap]` lookup. The explicit `Mesh` -> `LodMesh` behavior is hard-coded and therefore reproducible.

Runtime native/transient resolution and `CLASS_SafeReplace` depend on loaded engine state. A static UnrealDB parser cannot infer them solely from the referencing package bytes.

If other UT99 subsystems contain configurable remapping, that does not make it part of this verified package import algorithm unless the authoritative loader path invokes it.

## UnrealDB conformance requirements

For UT99 dependency extraction and optional source-compatible verification, UnrealDB should:

1. identify root provider packages from `Core.Package` imports with `PackageIndex == 0`;
2. reconstruct nested dependency paths by following negative import `PackageIndex` chains;
3. retain `ClassPackage`, `ClassName`, `ObjectName`, and parent chain for object dependencies;
4. derive provider export class name/package exactly as the retail source does;
5. require exact object/class/class-package matching except for the explicit UnrealI/UnrealShare exception;
6. reproduce the documented parent/outer matching rule if performing verification;
7. account for `RF_Public` when deciding whether a provider export is loadable;
8. implement `Mesh` -> `LodMesh` only for this source-backed path;
9. keep the two UnrealI/UnrealShare fallback mechanisms distinct;
10. not emulate runtime native/transient or `CLASS_SafeReplace` success from package bytes alone;
11. distinguish a declared dependency from a dependency proven resolvable;
12. not introduce generalized class remaps, package aliases, fuzzy matching, or other fallbacks absent from this source.

## Source-reference matrix

| Rule | Retail source |
|---|---|
| verify all imports | `ULinkerLoad::Verify` |
| skip conditions | `ULinkerLoad::VerifyImport` initial condition |
| root package identity | `VerifyImport: PackageIndex == 0` |
| top-level UnrealI -> UnrealShare | `VerifyImport: SharewareKludge` |
| parent recursion/provider inheritance | `VerifyImport: PackageIndex < 0` branch |
| hash function | `HashNames` |
| exact object/class/package match | `VerifyImport: export hash loop` |
| UnrealI/UnrealShare class-package exception | `VerifyImport: ClassHack` |
| outer matching/root-export exception | `VerifyImport: ParentImport` block |
| public requirement | `VerifyImport: RF_Public` block |
| Mesh -> LodMesh | `VerifyImport: Rehack`; `FindExportIndex: Rehack` |
| runtime native/transient path | `VerifyImport: StaticFindObject` block |
| safe replace | `VerifyImport: CLASS_SafeReplace` |
| nested UnrealI synthetic parent | `VerifyImport: Depth==1` block |
| forgiving missing behavior | `VerifyImport: LOAD_Forgiving` |
| import full path | `GetImportFullName` |
| export full path | `GetExportFullName` |
| class derivation | `GetExportClassName`, `GetExportClassPackage` |
| mapped import object creation | `CreateImport` |

## Revision boundary

This specification is for the retail v1.400 tree only. Later UT99 source, including `ardenee/UT99src-ext`, must be reviewed separately before any later behavior is added. A later fallback must not be backported into this specification merely because it is also UE1/UT99.
